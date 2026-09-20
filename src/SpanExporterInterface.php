<?php

declare(strict_types=1);

namespace EzPhp\Otel;

/**
 * Contract for delivering finished spans somewhere — an OTLP collector, an
 * in-memory buffer for tests, or nowhere at all.
 *
 * Must never throw: a failed export should not fail the request it was
 * observing. Implementations catch their own transport errors and return
 * false.
 *
 * @package EzPhp\Otel
 */
interface SpanExporterInterface
{
    /**
     * @param list<Span> $spans
     *
     * @return bool True on success.
     */
    public function export(array $spans): bool;
}
