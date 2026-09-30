<?php

declare(strict_types=1);

namespace EzPhp\Otel;

/**
 * Starts and finishes spans, exporting each one on {@see endSpan()}.
 *
 * There is no vendor SDK behind this: no processor pipeline, no global tracer
 * registry — one span in, one export call out. Batching and sampling are
 * exporter decorators (BatchingSpanExporter, TraceIdRatioSampler) that
 * OtelServiceProvider puts around the OTLP exporter.
 *
 * @package EzPhp\Otel
 */
final class Tracer
{
    /**
     * Spans marked active, innermost last.
     *
     * @var list<Span>
     */
    private array $active = [];

    /**
     * @param SpanExporterInterface $exporter Receives each span once it ends.
     */
    public function __construct(
        private readonly SpanExporterInterface $exporter,
    ) {
    }

    /**
     * Mark a span as the current one: instrumentation (TracingTransport,
     * TracingDatabase) uses activeContext() as the parent of the spans it
     * starts. OtelMiddleware activates the SERVER span for the whole request.
     *
     * @param Span $span
     *
     * @return void
     */
    public function activate(Span $span): void
    {
        $this->active[] = $span;
    }

    /**
     * Remove a span from the active stack (and anything activated after it that
     * was not deactivated, so an exception cannot leave the stack skewed).
     *
     * @param Span $span
     *
     * @return void
     */
    public function deactivate(Span $span): void
    {
        for ($i = count($this->active) - 1; $i >= 0; $i--) {
            if ($this->active[$i] === $span) {
                $this->active = array_slice($this->active, 0, $i);

                return;
            }
        }
    }

    /**
     * The context of the innermost active span, or null outside any.
     *
     * @return SpanContext|null
     */
    public function activeContext(): ?SpanContext
    {
        $span = $this->active[count($this->active) - 1] ?? null;

        return $span === null ? null : new SpanContext($span->traceId(), $span->spanId());
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
