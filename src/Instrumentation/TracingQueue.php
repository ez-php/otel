<?php

declare(strict_types=1);

namespace EzPhp\Otel\Instrumentation;

use EzPhp\Contracts\JobInterface;
use EzPhp\Contracts\QueueInterface;
use EzPhp\Otel\Span;
use EzPhp\Otel\SpanKind;
use EzPhp\Otel\SpanStatusCode;
use EzPhp\Otel\TraceParent;
use EzPhp\Otel\Tracer;
use EzPhp\Queue\JsonPayloadJobInterface;
use Throwable;

/**
 * Class TracingQueue
 *
 * QueueInterface decorator that carries trace context through queued jobs.
 *
 * - push(): inside an active span, the job is stored wrapped in a TracedJob
 *   carrying that span's `traceparent`; outside any span — or for an ez-php/queue
 *   JsonPayloadJobInterface job, which must keep its JSON storage — it is pushed as is.
 * - pop(): unwraps the TracedJob, starts a CONSUMER span (`<queue> process`)
 *   parented to the stored context, activates it and returns the original job.
 *   Spans the job starts (TracingDatabase, TracingTransport, Otel::tracer())
 *   therefore join the request's trace.
 *
 * The Worker runs the job after pop() returns, so the consumer span ends at the
 * next queue call: the following pop() (OK), failed() for that job (Error), or a
 * push() of a retry — the same object, or a copy with the same class and attempt
 * count, i.e. handle() threw — (Error, `messaging.retry`). finish() ends it explicitly, e.g. after the worker loop; the
 * destructor does the same as a fallback.
 *
 * Only JobInterface/QueueInterface are needed; the JsonPayloadJobInterface check
 * is an `instanceof`, which does not autoload, so ez-php/queue stays a suggest.
 * Wrap the driver directly: put UniqueQueue outside this decorator (it must see
 * the original job), and keep resolving FailedJobRepositoryInterface from the
 * driver, since the decorator does not implement it.
 *
 * @package EzPhp\Otel\Instrumentation
 */
final class TracingQueue implements QueueInterface
{
    private ?Span $span = null;

    private ?JobInterface $job = null;

    /**
     * @param QueueInterface $queue  The driver (or inner decorator).
     * @param Tracer         $tracer
     */
    public function __construct(
        private readonly QueueInterface $queue,
        private readonly Tracer $tracer,
    ) {
    }

    /**
     * Ends an in-flight consumer span when the decorator goes away.
     */
    public function __destruct()
    {
        $this->finish();
    }

    /**
     * {@inheritdoc}
     */
    public function push(JobInterface $job): void
    {
        // Read before a retry ends the consumer span, so the retry stays in the same trace.
        $context = $this->tracer->activeContext();

        if ($this->isRetryOfCurrentJob($job)) {
            $this->span?->setAttribute('messaging.retry', true);
            $this->finish(SpanStatusCode::Error);
        }

        // A JSON-payload job must stay unwrapped: the envelope would be PHP-serialized,
        // undoing the reason it opted into JSON. Its consumer span then starts a new trace.
        $carry = $context !== null && !$job instanceof JsonPayloadJobInterface;

        $this->queue->push($carry ? new TracedJob($job, TraceParent::format($context)) : $job);
    }

    /**
     * {@inheritdoc}
     */
    public function pop(string $queue = 'default'): ?JobInterface
    {
        $this->finish();

        $popped = $this->queue->pop($queue);

        if ($popped === null) {
            return null;
        }

        $parent = null;

        if ($popped instanceof TracedJob) {
            $parent = TraceParent::parse($popped->traceParent());
            $popped = $popped->job();
        }

        $span = $this->tracer->startSpan($queue . ' process', SpanKind::Consumer, $parent);
        $span->setAttribute('messaging.operation', 'process');
        $span->setAttribute('messaging.destination.name', $queue);
        $span->setAttribute('messaging.message.type', $popped::class);
        $span->setAttribute('messaging.attempt', $popped->getAttempts() + 1);

        $this->tracer->activate($span);
        $this->span = $span;
        $this->job = $popped;

        return $popped;
    }

    /**
     * {@inheritdoc}
     */
    public function size(string $queue = 'default'): int
    {
        return $this->queue->size($queue);
    }

    /**
     * {@inheritdoc}
     */
    public function failed(JobInterface $job, Throwable $exception): void
    {
        if ($job === $this->job) {
            $this->span?->setAttribute('exception.type', $exception::class);
            $this->span?->setAttribute('exception.message', $exception->getMessage());
            $this->finish(SpanStatusCode::Error);
        }

        $this->queue->failed($job, $exception);
    }

    /**
     * End the consumer span of the last popped job, if one is still open.
     *
     * @param SpanStatusCode $status
     *
     * @return void
     */
    public function finish(SpanStatusCode $status = SpanStatusCode::Ok): void
    {
        if ($this->span === null) {
            return;
        }

        $this->tracer->deactivate($this->span);
        $this->tracer->endSpan($this->span, $status);
        $this->span = null;
        $this->job = null;
    }

    /**
     * The Worker re-pushes a failed `Job` as a copy with the same attempt count
     * (a release gives the attempt back, a chained job is a different object
     * that has not run yet), and a failed plain JobInterface job as the very
     * same object.
     *
     * @param JobInterface $job
     *
     * @return bool
     */
    private function isRetryOfCurrentJob(JobInterface $job): bool
    {
        if ($this->job === null || $job->getAttempts() < 1) {
            return false;
        }

        return $job === $this->job
            || ($job::class === $this->job::class && $job->getAttempts() === $this->job->getAttempts());
    }
}
