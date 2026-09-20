<?php

declare(strict_types=1);

namespace EzPhp\Otel;

/**
 * Parses and formats the W3C Trace Context `traceparent` header
 * (`{version}-{trace-id}-{parent-id}-{trace-flags}`), so an incoming request
 * can continue a distributed trace instead of always starting a new one.
 *
 * @package EzPhp\Otel
 *
 * @see https://www.w3.org/TR/trace-context/#traceparent-header
 */
final class TraceParent
{
    private function __construct()
    {
    }

    /**
     * @param string $header
     *
     * @return SpanContext|null Null when the header is missing, malformed, or the version is unsupported.
     */
    public static function parse(string $header): ?SpanContext
    {
        $parts = explode('-', trim($header));

        if (count($parts) !== 4) {
            return null;
        }

        [$version, $traceId, $spanId, $flags] = $parts;

        if ($version !== '00' || !TraceId::isValid($traceId) || !SpanId::isValid($spanId)) {
            return null;
        }

        if (preg_match('/^[0-9a-f]{2}$/', $flags) !== 1) {
            return null;
        }

        $sampled = (hexdec($flags) & 0x01) === 1;

        return new SpanContext($traceId, $spanId, $sampled);
    }

    /**
     * @param SpanContext $context
     *
     * @return string
     */
    public static function format(SpanContext $context): string
    {
        $flags = $context->sampled ? '01' : '00';

        return "00-{$context->traceId}-{$context->spanId}-{$flags}";
    }
}
