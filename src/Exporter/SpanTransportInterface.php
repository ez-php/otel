<?php

declare(strict_types=1);

namespace EzPhp\Otel\Exporter;

/**
 * The outbound HTTP call {@see OtlpHttpExporter} needs, kept behind an
 * interface so tests can inject a fake instead of hitting the network and so
 * an application can swap in its own client (e.g. `ez-php/http-client`)
 * without this module depending on it.
 *
 * @package EzPhp\Otel\Exporter
 */
interface SpanTransportInterface
{
    /**
     * @param string                $url
     * @param string                $payload JSON-encoded request body.
     * @param array<string, string> $headers
     *
     * @return bool True when the endpoint responded with a 2xx status.
     */
    public function send(string $url, string $payload, array $headers): bool;
}
