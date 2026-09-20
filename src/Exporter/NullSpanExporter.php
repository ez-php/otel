<?php

declare(strict_types=1);

namespace EzPhp\Otel\Exporter;

use EzPhp\Otel\SpanExporterInterface;

/**
 * Discards every span. The default exporter when no OTLP endpoint is
 * configured, so instrumentation code never has to check whether tracing is
 * enabled.
 *
 * @package EzPhp\Otel\Exporter
 */
final class NullSpanExporter implements SpanExporterInterface
{
    /**
     * @param array<int, \EzPhp\Otel\Span> $spans
     *
     * @return bool
     */
    public function export(array $spans): bool
    {
        return true;
    }
}
