<?php

declare(strict_types=1);

namespace EzPhp\Otel\Instrumentation;

use EzPhp\HttpClient\HttpClientException;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\HttpClient\TransportInterface;
use EzPhp\Otel\SpanContext;
use EzPhp\Otel\SpanKind;
use EzPhp\Otel\SpanStatusCode;
use EzPhp\Otel\TraceParent;
use EzPhp\Otel\Tracer;

/**
 * Class TracingTransport
 *
 * `ez-php/http-client` transport decorator: one CLIENT span per request, child of
 * the tracer's active span (the request's SERVER span under OtelMiddleware), and
 * a W3C `traceparent` header so the called service continues the same trace.
 * A 5xx response or a transport exception marks the span as an error.
 *
 * `ez-php/http-client` is a soft dependency (`suggest`); this class is only loaded
 * when referenced. Wrap the transport when building the client:
 * `new HttpClient(new TracingTransport(new CurlTransport(), $tracer))`.
 * Streaming requests are not wrapped — this decorator implements TransportInterface only.
 *
 * @package EzPhp\Otel\Instrumentation
 */
final readonly class TracingTransport implements TransportInterface
{
    /**
     * @param TransportInterface $inner
     * @param Tracer             $tracer
     */
    public function __construct(
        private TransportInterface $inner,
        private Tracer $tracer,
    ) {
    }

    /**
     * @param string                $method
     * @param string                $url
     * @param array<string, string> $headers
     * @param string                $body
     * @param int|null              $timeoutSeconds
     *
     * @throws HttpClientException
     *
     * @return HttpResponse
     */
    public function send(string $method, string $url, array $headers, string $body, ?int $timeoutSeconds = null): HttpResponse
    {
        $method = strtoupper($method);
        $span = $this->tracer->startSpan("HTTP {$method}", SpanKind::Client, $this->tracer->activeContext());
        $span->setAttribute('http.method', $method);
        $span->setAttribute('http.url', $url);

        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host)) {
            $span->setAttribute('server.address', $host);
        }

        foreach (array_keys($headers) as $name) {
            if (strcasecmp($name, 'traceparent') === 0) {
                unset($headers[$name]);
            }
        }

        $headers['traceparent'] = TraceParent::format(new SpanContext($span->traceId(), $span->spanId()));
        $status = SpanStatusCode::Ok;

        try {
            $response = $this->inner->send($method, $url, $headers, $body, $timeoutSeconds);
            $span->setAttribute('http.status_code', $response->status());

            if ($response->status() >= 500) {
                $status = SpanStatusCode::Error;
            }

            return $response;
        } catch (\Throwable $e) {
            $status = SpanStatusCode::Error;

            throw $e;
        } finally {
            $this->tracer->endSpan($span, $status);
        }
    }
}
