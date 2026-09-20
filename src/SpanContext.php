<?php

declare(strict_types=1);

namespace EzPhp\Otel;

/**
 * Immutable propagation context for a span — the trace/span ID pair (and
 * sampling decision) carried across process boundaries via the W3C
 * `traceparent` header (see {@see TraceParent}).
 *
 * @package EzPhp\Otel
 */
final readonly class SpanContext
{
    /**
     * @param string $traceId 32 lowercase hex characters.
     * @param string $spanId  16 lowercase hex characters.
     * @param bool   $sampled Whether the trace-flags sampled bit is set.
     */
    public function __construct(
        public string $traceId,
        public string $spanId,
        public bool $sampled = true,
    ) {
    }
}
