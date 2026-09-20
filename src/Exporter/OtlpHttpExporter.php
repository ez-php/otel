<?php

declare(strict_types=1);

namespace EzPhp\Otel\Exporter;

use EzPhp\Otel\Span;
use EzPhp\Otel\SpanExporterInterface;
use JsonException;

/**
 * Exports spans as OTLP/HTTP with JSON encoding — a hand-built
 * `ExportTraceServiceRequest` body (see the OTLP spec's JSON mapping),
 * POSTed to a collector's `/v1/traces` endpoint. No protobuf, no vendor SDK.
 *
 * @package EzPhp\Otel\Exporter
 *
 * @see https://opentelemetry.io/docs/specs/otlp/#otlphttp
 */
final class OtlpHttpExporter implements SpanExporterInterface
{
    private readonly SpanTransportInterface $transport;

    /**
     * @param string                 $endpoint    Full URL of the collector's traces endpoint, e.g. `http://localhost:4318/v1/traces`.
     * @param string                 $serviceName Value of the `service.name` resource attribute.
     * @param SpanTransportInterface|null $transport   Defaults to {@see StreamSpanTransport}.
     * @param array<string, string>  $headers     Extra headers merged into every export request (e.g. auth).
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly string $serviceName = 'ez-php-app',
        ?SpanTransportInterface $transport = null,
        private readonly array $headers = [],
    ) {
        $this->transport = $transport ?? new StreamSpanTransport();
    }

    /**
     * @param list<Span> $spans
     *
     * @return bool
     */
    public function export(array $spans): bool
    {
        if ($spans === []) {
            return true;
        }

        try {
            $payload = json_encode($this->toExportRequest($spans), JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        $headers = array_merge(['Content-Type' => 'application/json'], $this->headers);

        try {
            return $this->transport->send($this->endpoint, $payload, $headers);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<Span> $spans
     *
     * @return array<string, mixed>
     */
    private function toExportRequest(array $spans): array
    {
        return [
            'resourceSpans' => [
                [
                    'resource' => [
                        'attributes' => [
                            $this->keyValue('service.name', $this->serviceName),
                        ],
                    ],
                    'scopeSpans' => [
                        [
                            'scope' => ['name' => 'ez-php/otel'],
                            'spans' => array_map($this->toOtlpSpan(...), $spans),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param Span $span
     *
     * @return array<string, mixed>
     */
    private function toOtlpSpan(Span $span): array
    {
        $otlpSpan = [
            'traceId' => $span->traceId(),
            'spanId' => $span->spanId(),
            'name' => $span->name(),
            'kind' => $span->kind()->value,
            'startTimeUnixNano' => (string) $span->startTimeUnixNano(),
            'endTimeUnixNano' => (string) $span->endTimeUnixNano(),
            'attributes' => array_map(
                fn (string $key, bool|int|float|string $value): array => $this->keyValue($key, $value),
                array_keys($span->attributes()),
                array_values($span->attributes()),
            ),
            'status' => ['code' => $span->status()->value],
        ];

        if ($span->parentSpanId() !== null) {
            $otlpSpan['parentSpanId'] = $span->parentSpanId();
        }

        return $otlpSpan;
    }

    /**
     * @param string                 $key
     * @param bool|int|float|string  $value
     *
     * @return array<string, mixed>
     */
    private function keyValue(string $key, bool|int|float|string $value): array
    {
        $anyValue = match (true) {
            is_bool($value) => ['boolValue' => $value],
            is_int($value) => ['intValue' => (string) $value],
            is_float($value) => ['doubleValue' => $value],
            default => ['stringValue' => $value],
        };

        return ['key' => $key, 'value' => $anyValue];
    }
}
