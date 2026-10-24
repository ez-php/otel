<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Otel\Exporter\InMemorySpanExporter;
use EzPhp\Otel\Instrumentation\TracedJob;
use EzPhp\Otel\Instrumentation\TracingQueue;
use EzPhp\Otel\Otel;
use EzPhp\Otel\Span;
use EzPhp\Otel\SpanContext;
use EzPhp\Otel\SpanId;
use EzPhp\Otel\SpanKind;
use EzPhp\Otel\SpanStatusCode;
use EzPhp\Otel\TraceId;
use EzPhp\Otel\TraceParent;
use EzPhp\Otel\Tracer;
use EzPhp\Queue\Driver\DatabaseDriver;
use EzPhp\Queue\Driver\InMemoryDriver;
use EzPhp\Queue\Worker;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\OtelQueueBareJob;
use Tests\Support\OtelQueueJsonJob;
use Tests\Support\OtelQueueProbeJob;

#[CoversClass(TracingQueue::class)]
#[CoversClass(TracedJob::class)]
#[UsesClass(Tracer::class)]
#[UsesClass(Span::class)]
#[UsesClass(SpanContext::class)]
#[UsesClass(SpanId::class)]
#[UsesClass(TraceId::class)]
#[UsesClass(TraceParent::class)]
#[UsesClass(Otel::class)]
#[UsesClass(InMemorySpanExporter::class)]
final class TracingQueueTest extends TestCase
{
    private InMemorySpanExporter $exporter;

    private Tracer $tracer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = new InMemorySpanExporter();
        $this->tracer = new Tracer($this->exporter);
        Otel::setTracer($this->tracer);
        OtelQueueProbeJob::$seen = [];
    }

    protected function tearDown(): void
    {
        Otel::resetTracer();
        parent::tearDown();
    }

    /**
     * @return list<Span>
     */
    private function consumerSpans(): array
    {
        return array_values(array_filter(
            $this->exporter->exported(),
            static fn (Span $s): bool => $s->kind() === SpanKind::Consumer,
        ));
    }

    private function databaseQueue(): TracingQueue
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return new TracingQueue(new DatabaseDriver($pdo), $this->tracer);
    }

    public function testJobPushedInsideASpanContinuesThatTraceThroughTheDatabaseDriver(): void
    {
        $queue = $this->databaseQueue();
        $request = $this->tracer->startSpan('GET /orders', SpanKind::Server);
        $this->tracer->activate($request);
        $queue->push(new OtelQueueProbeJob());
        $this->tracer->deactivate($request);
        $this->tracer->endSpan($request);

        (new Worker($queue))->runNextJob();
        $queue->finish();

        $consumer = $this->consumerSpans()[0];
        self::assertSame($request->traceId(), $consumer->traceId());
        self::assertSame($request->spanId(), $consumer->parentSpanId());
        self::assertSame('default process', $consumer->name());
        self::assertSame(SpanStatusCode::Ok, $consumer->status());
        self::assertSame(OtelQueueProbeJob::class, $consumer->attributes()['messaging.message.type']);
        self::assertSame([$consumer->traceId() . '-' . $consumer->spanId()], OtelQueueProbeJob::$seen);
    }

    public function testPopReturnsTheOriginalJobNotTheEnvelope(): void
    {
        $driver = new InMemoryDriver();
        $queue = new TracingQueue($driver, $this->tracer);
        $span = $this->tracer->startSpan('producer');
        $this->tracer->activate($span);
        $queue->push(new OtelQueueProbeJob());

        self::assertInstanceOf(OtelQueueProbeJob::class, $queue->pop());
    }

    public function testJobPushedOutsideAnySpanIsStoredUnwrappedAndStartsANewTrace(): void
    {
        $driver = new InMemoryDriver();
        $queue = new TracingQueue($driver, $this->tracer);
        $queue->push(new OtelQueueProbeJob());

        self::assertInstanceOf(OtelQueueProbeJob::class, $driver->pop());

        $queue->push(new OtelQueueProbeJob());
        $queue->pop();
        $queue->finish();

        self::assertNull($this->consumerSpans()[0]->parentSpanId());
    }

    public function testJsonPayloadJobsAreNotWrapped(): void
    {
        $driver = new InMemoryDriver();
        $queue = new TracingQueue($driver, $this->tracer);
        $span = $this->tracer->startSpan('producer');
        $this->tracer->activate($span);

        $queue->push(new OtelQueueJsonJob('x'));

        $stored = $driver->pop();
        self::assertInstanceOf(OtelQueueJsonJob::class, $stored);
        self::assertSame('x', $stored->value);
    }

    public function testRetryAndPermanentFailureEndTheConsumerSpansWithError(): void
    {
        $queue = $this->databaseQueue();
        $root = $this->tracer->startSpan('producer');
        $this->tracer->activate($root);
        $queue->push(new OtelQueueProbeJob(fail: true));
        $this->tracer->deactivate($root);

        $worker = new Worker($queue);
        $worker->runNextJob(); // attempt 1 → retried
        $worker->runNextJob(); // attempt 2 → failed()

        [$first, $second] = $this->consumerSpans();
        self::assertSame(SpanStatusCode::Error, $first->status());
        self::assertTrue($first->attributes()['messaging.retry']);
        self::assertSame(SpanStatusCode::Error, $second->status());
        self::assertSame('boom', $second->attributes()['exception.message']);
        self::assertSame(2, $second->attributes()['messaging.attempt']);
        // The retry was pushed while attempt 1's span was active, so attempt 2 is its child.
        self::assertSame($first->spanId(), $second->parentSpanId());
        self::assertSame($root->traceId(), $second->traceId());
    }

    public function testRetryOfABareJobInterfaceJobEndsTheConsumerSpanWithError(): void
    {
        $queue = $this->databaseQueue();
        $root = $this->tracer->startSpan('producer');
        $this->tracer->activate($root);
        $queue->push(new OtelQueueBareJob());
        $this->tracer->deactivate($root);

        $worker = new Worker($queue);
        $worker->runNextJob(); // attempt 1 → re-pushed as the same object
        $worker->runNextJob(); // attempt 2 → failed()

        [$first, $second] = $this->consumerSpans();
        self::assertSame(SpanStatusCode::Error, $first->status());
        self::assertTrue($first->attributes()['messaging.retry']);
        self::assertSame(SpanStatusCode::Error, $second->status());
        self::assertSame('bare boom', $second->attributes()['exception.message']);
        self::assertSame($first->spanId(), $second->parentSpanId());
    }

    public function testNextPopEndsThePreviousSpanAndFinishIsIdempotent(): void
    {
        $queue = new TracingQueue(new InMemoryDriver(), $this->tracer);
        $queue->push(new OtelQueueProbeJob());
        $queue->pop();

        self::assertSame([], $this->consumerSpans());
        self::assertNull($queue->pop());
        self::assertCount(1, $this->consumerSpans());
        self::assertNull($this->tracer->activeContext());

        $queue->finish();
        self::assertCount(1, $this->consumerSpans());
        self::assertSame(0, $queue->size());
    }

    public function testTracedJobDelegatesToTheWrappedJob(): void
    {
        $inner = new OtelQueueProbeJob();
        $traced = new TracedJob($inner, '00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01');

        $traced->incrementAttempts();
        $traced->handle();
        $traced->fail(new \RuntimeException('x'));

        self::assertSame(1, $traced->getAttempts());
        self::assertSame('default', $traced->getQueue());
        self::assertSame(0, $traced->getDelay());
        self::assertSame(2, $traced->getMaxTries());
        self::assertSame($inner, $traced->job());
        self::assertCount(1, OtelQueueProbeJob::$seen);
    }
}
