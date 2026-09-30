<?php

declare(strict_types=1);

namespace EzPhp\Otel\Exporter;

use EzPhp\Otel\Span;
use EzPhp\Otel\SpanExporterInterface;
use InvalidArgumentException;

/**
 * Class TraceIdRatioSampler
 *
 * Exporter decorator that keeps only a share of traces. The decision is a pure
 * function of the trace ID — its low 56 bits against `$ratio` — so every span of
 * a trace is kept or dropped together, in every process that sees the trace.
 * Head sampling only: nothing looks at span status or duration.
 *
 * @package EzPhp\Otel\Exporter
 */
final readonly class TraceIdRatioSampler implements SpanExporterInterface
{
    /**
     * 2^56: the range of the trace-ID bits compared against the ratio.
     */
    private const int RANGE = 72_057_594_037_927_936;

    /**
     * @param SpanExporterInterface $inner
     * @param float                 $ratio Share of traces to keep, 0.0–1.0.
     *
     * @throws InvalidArgumentException When $ratio is outside 0.0–1.0.
     */
    public function __construct(
        private SpanExporterInterface $inner,
        private float $ratio,
    ) {
        if ($ratio < 0.0 || $ratio > 1.0) {
            throw new InvalidArgumentException("Sample ratio must be between 0.0 and 1.0, got {$ratio}.");
        }
    }

    /**
     * @param list<Span> $spans
     *
     * @return bool
     */
    public function export(array $spans): bool
    {
        $kept = array_values(array_filter($spans, fn (Span $span): bool => $this->shouldSample($span->traceId())));

        return $kept === [] ? true : $this->inner->export($kept);
    }

    /**
     * @param string $traceId 32 hex characters.
     *
     * @return bool
     */
    public function shouldSample(string $traceId): bool
    {
        if ($this->ratio >= 1.0) {
            return true;
        }

        if ($this->ratio <= 0.0) {
            return false;
        }

        $low = substr($traceId, -14);

        if (strlen($low) !== 14 || !ctype_xdigit($low)) {
            return true; // not a valid trace ID: keep rather than lose it silently
        }

        return hexdec($low) < $this->ratio * self::RANGE;
    }
}
