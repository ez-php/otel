<?php

declare(strict_types=1);

namespace EzPhp\Otel;

/**
 * Starts and finishes spans, exporting each one on {@see endSpan()}.
 *
 * There is no vendor SDK behind this: no batching processor, no sampler
 * chain, no global tracer registry — one span in, one export call out. An
 * application that needs batching wraps a {@see SpanExporterInterface} that
 * buffers and flushes on its own schedule.
 *
 * @package EzPhp\Otel
 */
final class Tracer
{
    /**
     * @param SpanExporterInterface $exporter
     */
    public function __construct(
        private readonly SpanExporterInterface $exporter,
    ) {
    }

    /**
     * @param string           $name
     * @param SpanKind         $kind    Defaults to {@see SpanKind::Internal}.
     * @param SpanContext|null $parent  A real parent span to continue — both its trace ID and span ID are used.
     * @param string|null      $traceId Used only when $parent is null: continues this trace with no parent span
     *                                  (e.g. a trace ID correlated from a log's request ID). Ignored when $parent is set.
     *
     * @return Span
     */
    public function startSpan(
        string $name,
        SpanKind $kind = SpanKind::Internal,
        ?SpanContext $parent = null,
        ?string $traceId = null,
    ): Span {
        $resolvedTraceId = $parent->traceId ?? $traceId ?? TraceId::generate();

        return new Span($resolvedTraceId, SpanId::generate(), $parent?->spanId, $name, $kind);
    }

    /**
     * Ends the span and hands it to the configured exporter.
     *
     * @param Span           $span
     * @param SpanStatusCode $status
     *
     * @return void
     */
    public function endSpan(Span $span, SpanStatusCode $status = SpanStatusCode::Ok): void
    {
        $span->end($status);
        $this->exporter->export([$span]);
    }
}
