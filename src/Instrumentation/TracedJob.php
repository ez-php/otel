<?php

declare(strict_types=1);

namespace EzPhp\Otel\Instrumentation;

use EzPhp\Contracts\JobInterface;
use Throwable;

/**
 * Class TracedJob
 *
 * Storage envelope TracingQueue::push() wraps around a job so the producer's
 * W3C `traceparent` travels with the serialized payload — JobInterface has no
 * metadata slot. Every JobInterface method delegates to the wrapped job, so
 * drivers read its queue and delay unchanged. TracingQueue::pop() unwraps it
 * again: the Worker always runs the original job, never this envelope.
 *
 * @package EzPhp\Otel\Instrumentation
 */
final class TracedJob implements JobInterface
{
    /**
     * @param JobInterface $job         The job to run.
     * @param string       $traceParent W3C traceparent of the span active at push time.
     */
    public function __construct(
        private readonly JobInterface $job,
        private readonly string $traceParent,
    ) {
    }

    /**
     * @return JobInterface
     */
    public function job(): JobInterface
    {
        return $this->job;
    }

    /**
     * @return string
     */
    public function traceParent(): string
    {
        return $this->traceParent;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(): void
    {
        $this->job->handle();
    }

    /**
     * {@inheritdoc}
     */
    public function fail(Throwable $exception): void
    {
        $this->job->fail($exception);
    }

    /**
     * {@inheritdoc}
     */
    public function getQueue(): string
    {
        return $this->job->getQueue();
    }

    /**
     * {@inheritdoc}
     */
    public function getDelay(): int
    {
        return $this->job->getDelay();
    }

    /**
     * {@inheritdoc}
     */
    public function getMaxTries(): int
    {
        return $this->job->getMaxTries();
    }

    /**
     * {@inheritdoc}
     */
    public function getAttempts(): int
    {
        return $this->job->getAttempts();
    }

    /**
     * {@inheritdoc}
     */
    public function incrementAttempts(): void
    {
        $this->job->incrementAttempts();
    }
}
