<?php

declare(strict_types=1);

namespace Tests\Support;

use EzPhp\Contracts\JobInterface;
use RuntimeException;
use Throwable;

/**
 * Queue job fixture implementing JobInterface directly (not ez-php/queue's Job):
 * the Worker re-pushes such a job as the same object on retry. Always fails.
 */
final class OtelQueueBareJob implements JobInterface
{
    private int $attempts = 0;

    public function handle(): void
    {
        throw new RuntimeException('bare boom');
    }

    public function fail(Throwable $exception): void
    {
    }

    public function getQueue(): string
    {
        return 'default';
    }

    public function getDelay(): int
    {
        return 0;
    }

    public function getMaxTries(): int
    {
        return 2;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function incrementAttempts(): void
    {
        $this->attempts++;
    }
}
