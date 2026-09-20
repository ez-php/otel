<?php

declare(strict_types=1);

namespace EzPhp\Otel;

use Closure;
use EzPhp\Contracts\MiddlewareInterface;
use EzPhp\Http\RequestInterface;
use EzPhp\Http\ResponseInterface;
use Throwable;

/**
 * Wraps the request in a root (or continued) SERVER span: parses an incoming
 * W3C `traceparent` header to continue a distributed trace, or falls back to
 * `$traceIdSeedResolver` — typically a closure reading the same correlation
 * ID `EzPhp\Logging\RequestContextMiddleware` attaches to logs — so a single
 * request produces one trace ID for both logs and spans without this module
 * depending on `ez-php/logging`. With neither available, a fresh trace ID is
 * generated.
 *
 * Not registered automatically by {@see OtelServiceProvider} — add it via
 * `$app->middleware(OtelMiddleware::class)` like any other global middleware.
 *
 * @package EzPhp\Otel
 */
final class OtelMiddleware implements MiddlewareInterface
{
    /**
     * @param Tracer       $tracer
     * @param Closure(): (string|null)|null $traceIdSeedResolver Returns a seed string, or null to skip.
     */
    public function __construct(
        private readonly Tracer $tracer,
        private readonly ?Closure $traceIdSeedResolver = null,
    ) {
    }

    /**
     * @param RequestInterface $request
     * @param callable         $next
     *
     * @return ResponseInterface
     */
    public function handle(RequestInterface $request, callable $next): ResponseInterface
    {
        $parent = $this->resolveParentContext($request);
        $traceId = $parent->traceId ?? $this->resolveSeedTraceId();
        $path = parse_url($request->uri(), PHP_URL_PATH);
        $path = is_string($path) ? $path : $request->uri();

        $span = $this->tracer->startSpan("{$request->method()} {$path}", SpanKind::Server, $parent, $traceId);
        $span->setAttribute('http.method', $request->method());
        $span->setAttribute('http.target', $path);

        $status = SpanStatusCode::Ok;

        try {
            $response = $next($request);
            $span->setAttribute('http.status_code', $response->status());

            if ($response->status() >= 500) {
                $status = SpanStatusCode::Error;
            }

            return $response;
        } catch (Throwable $exception) {
            $status = SpanStatusCode::Error;

            throw $exception;
        } finally {
            $this->tracer->endSpan($span, $status);
        }
    }

    /**
     * @param RequestInterface $request
     *
     * @return SpanContext|null A real parent span from an incoming `traceparent` header, if present and valid.
     */
    private function resolveParentContext(RequestInterface $request): ?SpanContext
    {
        $header = $request->header('traceparent');

        return is_string($header) ? TraceParent::parse($header) : null;
    }

    /**
     * @return string|null A trace ID derived from `$traceIdSeedResolver`, with no corresponding parent span.
     */
    private function resolveSeedTraceId(): ?string
    {
        if ($this->traceIdSeedResolver === null) {
            return null;
        }

        $seed = ($this->traceIdSeedResolver)();

        return is_string($seed) && $seed !== '' ? TraceId::fromSeed($seed) : null;
    }
}
