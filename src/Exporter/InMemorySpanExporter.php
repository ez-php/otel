<?php

declare(strict_types=1);

namespace EzPhp\Otel\Exporter;

use EzPhp\Otel\Span;
use EzPhp\Otel\SpanExporterInterface;

/**
 * Collects exported spans in memory instead of sending them anywhere —
 * intended for tests and for local debugging of instrumentation.
 *
 * @package EzPhp\Otel\Exporter
 */
final class InMemorySpanExporter implements SpanExporterInterface
{
    /**
     * @var list<Span>
     */
    private array $spans = [];

    /**
     * @param list<Span> $spans
     *
     * @return bool
     */
    public function export(array $spans): bool
    {
        array_push($this->spans, ...$spans);

        return true;
    }

    /**
     * @return list<Span>
     */
    public function exported(): array
    {
        return $this->spans;
    }

    /**
     * @return void
     */
    public function reset(): void
    {
        $this->spans = [];
    }
}
