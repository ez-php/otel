<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Otel\Exporter\OtlpHttpExporter;
use EzPhp\Otel\Exporter\SpanTransportInterface;
use EzPhp\Otel\Span;
use EzPhp\Otel\SpanKind;
use EzPhp\Otel\SpanStatusCode;
use RuntimeException;

final class OtlpHttpExporterTest extends TestCase
{
    public function testPostsOtlpJsonPayloadToEndpoint(): void
    {
        $transport = new RecordingSpanTransport();
        $exporter = new OtlpHttpExporter('http://collector:4318/v1/traces', 'svc', $transport, ['X-Auth' => 'k']);

        $span = new Span(str_repeat('a', 32), str_repeat('b', 16), str_repeat('c', 16), 'GET /x', SpanKind::Server);
        $span->setAttribute('http.status_code', 200);
        $span->setAttribute('ok', true);
        $span->setAttribute('ratio', 0.5);
        $span->setAttribute('path', '/x');
        $span->end(SpanStatusCode::Ok);

        self::assertTrue($exporter->export([$span]));

        self::assertSame('http://collector:4318/v1/traces', $transport->url);
        self::assertSame('application/json', $transport->headers['Content-Type']);
        self::assertSame('k', $transport->headers['X-Auth']);

        $body = json_decode($transport->payload, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('service.name', $this->at($body, 'resourceSpans', 0, 'resource', 'attributes', 0, 'key'));
        self::assertSame('svc', $this->at($body, 'resourceSpans', 0, 'resource', 'attributes', 0, 'value', 'stringValue'));

        $otlp = $this->at($body, 'resourceSpans', 0, 'scopeSpans', 0, 'spans', 0);

        self::assertSame(str_repeat('a', 32), $this->at($otlp, 'traceId'));
        self::assertSame(str_repeat('b', 16), $this->at($otlp, 'spanId'));
        self::assertSame(str_repeat('c', 16), $this->at($otlp, 'parentSpanId'));
        self::assertSame('GET /x', $this->at($otlp, 'name'));
        self::assertSame(2, $this->at($otlp, 'kind'));
        self::assertSame(1, $this->at($otlp, 'status', 'code'));
        self::assertIsString($this->at($otlp, 'startTimeUnixNano'));

        $byKey = [];
        foreach ([0, 1, 2, 3] as $i) {
            $key = $this->at($otlp, 'attributes', $i, 'key');
            self::assertIsString($key);
            $byKey[$key] = $this->at($otlp, 'attributes', $i, 'value');
        }

        self::assertSame(['intValue' => '200'], $byKey['http.status_code']);
        self::assertSame(['boolValue' => true], $byKey['ok']);
        self::assertSame(['doubleValue' => 0.5], $byKey['ratio']);
        self::assertSame(['stringValue' => '/x'], $byKey['path']);
    }

    private function at(mixed $data, string|int ...$path): mixed
    {
        foreach ($path as $segment) {
            self::assertIsArray($data);
            self::assertArrayHasKey($segment, $data);
            $data = $data[$segment];
        }

        return $data;
    }

    public function testRootSpanOmitsParentSpanId(): void
    {
        $transport = new RecordingSpanTransport();
        $exporter = new OtlpHttpExporter('http://c/v1/traces', 'svc', $transport);

        $exporter->export([new Span('t', 's', null, 'root')]);

        self::assertStringNotContainsString('parentSpanId', $transport->payload);
    }

    public function testEmptySpanListSkipsTransport(): void
    {
        $transport = new RecordingSpanTransport();
        $exporter = new OtlpHttpExporter('http://c/v1/traces', 'svc', $transport);

        self::assertTrue($exporter->export([]));
        self::assertSame(0, $transport->calls);
    }

    public function testReturnsFalseWhenTransportReportsFailure(): void
    {
        $transport = new RecordingSpanTransport();
        $transport->result = false;
        $exporter = new OtlpHttpExporter('http://c/v1/traces', 'svc', $transport);

        self::assertFalse($exporter->export([new Span('t', 's', null, 'n')]));
    }

    public function testReturnsFalseWhenTransportThrows(): void
    {
        $transport = new RecordingSpanTransport();
        $transport->throw = true;
        $exporter = new OtlpHttpExporter('http://c/v1/traces', 'svc', $transport);

        self::assertFalse($exporter->export([new Span('t', 's', null, 'n')]));
    }

    public function testReturnsFalseWhenPayloadCannotBeEncoded(): void
    {
        $transport = new RecordingSpanTransport();
        $exporter = new OtlpHttpExporter('http://c/v1/traces', 'svc', $transport);

        $span = new Span('t', 's', null, "bad\xB1utf8");

        self::assertFalse($exporter->export([$span]));
        self::assertSame(0, $transport->calls);
    }
}

final class RecordingSpanTransport implements SpanTransportInterface
{
    public string $url = '';

    public string $payload = '';

    /**
     * @var array<string, string>
     */
    public array $headers = [];

    public int $calls = 0;

    public bool $result = true;

    public bool $throw = false;

    public function send(string $url, string $payload, array $headers): bool
    {
        $this->calls++;
        $this->url = $url;
        $this->payload = $payload;
        $this->headers = $headers;

        if ($this->throw) {
            throw new RuntimeException('boom');
        }

        return $this->result;
    }
}
