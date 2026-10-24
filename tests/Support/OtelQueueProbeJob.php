<?php

declare(strict_types=1);

namespace Tests\Support;

use EzPhp\Otel\Otel;
use EzPhp\Queue\Job;
use RuntimeException;

/**
 * Queue job fixture: records the active span context while it runs; throws when $fail is set.
 */
final class OtelQueueProbeJob extends Job
{
    /**
     * traceparent active during each handle() call ('' outside any span).
     *
     * @var list<string>
     */
    public static array $seen = [];

    protected int $maxTries = 2;

    public function __construct(private readonly bool $fail = false)
    {
    }

    public function handle(): void
    {
        $context = Otel::tracer()->activeContext();
        self::$seen[] = $context === null ? '' : $context->traceId . '-' . $context->spanId;

        if ($this->fail) {
            throw new RuntimeException('boom');
        }
    }
}
