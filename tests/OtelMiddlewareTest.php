<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Http\Request;
use EzPhp\Http\Response;
use EzPhp\Otel\Exporter\InMemorySpanExporter;
use EzPhp\Otel\OtelMiddleware;
use EzPhp\Otel\SpanKind;
use EzPhp\Otel\SpanStatusCode;
use EzPhp\Otel\TraceId;
use EzPhp\Otel\Tracer;
use RuntimeException;

final class OtelMiddlewareTest extends TestCase
{
    private InMemorySpanExporter $exporter;

    private Tracer $tracer;

    protected function setUp(): void
    {
        $this->exporter = new InMemorySpanExporter();
        $this->tracer = new Tracer($this->exporter);
    }

    public function testCreatesServerSpanWithHttpAttributes(): void
    {
        $middleware = new OtelMiddleware($this->tracer);

        $response = $middleware->handle(
            new Request('GET', '/users?page=2'),
            static fn (): Response => new Response('ok', 200),
        );

        self::assertSame(200, $response->status());

        $span = $this->exporter->exported()[0];
        self::assertSame('GET /users', $span->name());
        self::assertSame(SpanKind::Server, $span->kind());
        self::assertSame(SpanStatusCode::Ok, $span->status());
        self::assertSame('GET', $span->attributes()['http.method']);
        self::assertSame('/users', $span->attributes()['http.target']);
        self::assertSame(200, $span->attributes()['http.status_code']);
    }

    public function testMarksServerErrorResponseAsErrorStatus(): void
    {
        $middleware = new OtelMiddleware($this->tracer);

        $middleware->handle(new Request('GET', '/x'), static fn (): Response => new Response('', 503));

        self::assertSame(SpanStatusCode::Error, $this->exporter->exported()[0]->status());
    }

    public function testClientErrorResponseIsNotAnErrorSpan(): void
    {
        $middleware = new OtelMiddleware($this->tracer);

        $middleware->handle(new Request('GET', '/x'), static fn (): Response => new Response('', 404));

        self::assertSame(SpanStatusCode::Ok, $this->exporter->exported()[0]->status());
    }

    public function testExceptionEndsSpanWithErrorAndRethrows(): void
    {
        $middleware = new OtelMiddleware($this->tracer);

        try {
            $middleware->handle(new Request('GET', '/x'), static function (): never {
                throw new RuntimeException('fail');
            });
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertSame('fail', $e->getMessage());
        }

        self::assertCount(1, $this->exporter->exported());
        self::assertSame(SpanStatusCode::Error, $this->exporter->exported()[0]->status());
    }

    public function testContinuesTraceFromTraceparentHeader(): void
    {
        $traceId = str_repeat('a', 32);
        $parentSpan = str_repeat('b', 16);
        $request = new Request('GET', '/x', headers: ['traceparent' => "00-{$traceId}-{$parentSpan}-01"]);

        (new OtelMiddleware($this->tracer))->handle($request, static fn (): Response => new Response());

        $span = $this->exporter->exported()[0];
        self::assertSame($traceId, $span->traceId());
        self::assertSame($parentSpan, $span->parentSpanId());
    }

    public function testInvalidTraceparentStartsNewTrace(): void
    {
        $request = new Request('GET', '/x', headers: ['traceparent' => 'garbage']);

        (new OtelMiddleware($this->tracer))->handle($request, static fn (): Response => new Response());

        $span = $this->exporter->exported()[0];
        self::assertTrue(TraceId::isValid($span->traceId()));
        self::assertNull($span->parentSpanId());
    }

    public function testSeedResolverDerivesTraceIdWithoutParentSpan(): void
    {
        $middleware = new OtelMiddleware($this->tracer, static fn (): string => 'req-42');

        $middleware->handle(new Request('GET', '/x'), static fn (): Response => new Response());

        $span = $this->exporter->exported()[0];
        self::assertSame(TraceId::fromSeed('req-42'), $span->traceId());
        self::assertNull($span->parentSpanId());
    }

    public function testEmptySeedFallsBackToFreshTraceId(): void
    {
        $middleware = new OtelMiddleware($this->tracer, static fn (): ?string => null);

        $middleware->handle(new Request('GET', '/x'), static fn (): Response => new Response());

        self::assertTrue(TraceId::isValid($this->exporter->exported()[0]->traceId()));
    }

    public function testTraceparentTakesPrecedenceOverSeed(): void
    {
        $traceId = str_repeat('a', 32);
        $request = new Request('GET', '/x', headers: ['traceparent' => "00-{$traceId}-" . str_repeat('b', 16) . '-01']);
        $middleware = new OtelMiddleware($this->tracer, static fn (): string => 'ignored');

        $middleware->handle($request, static fn (): Response => new Response());

        self::assertSame($traceId, $this->exporter->exported()[0]->traceId());
    }
}
