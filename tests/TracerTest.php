<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Otel\Exporter\InMemorySpanExporter;
use EzPhp\Otel\SpanContext;
use EzPhp\Otel\SpanId;
use EzPhp\Otel\SpanKind;
use EzPhp\Otel\SpanStatusCode;
use EzPhp\Otel\TraceId;
use EzPhp\Otel\Tracer;

final class TracerTest extends TestCase
{
    public function testStartSpanWithNoParentStartsNewTrace(): void
    {
        $tracer = new Tracer(new InMemorySpanExporter());

        $span = $tracer->startSpan('op');

        self::assertTrue(TraceId::isValid($span->traceId()));
        self::assertNull($span->parentSpanId());
        self::assertSame(SpanKind::Internal, $span->kind());
    }

    public function testStartSpanWithParentContextContinuesTrace(): void
    {
        $tracer = new Tracer(new InMemorySpanExporter());
        $parent = new SpanContext(TraceId::generate(), 'a1b2c3d4e5f6a7b8');

        $span = $tracer->startSpan('op', SpanKind::Server, $parent);

        self::assertSame($parent->traceId, $span->traceId());
        self::assertSame($parent->spanId, $span->parentSpanId());
    }

    public function testStartSpanWithTraceIdOnlyHasNoParentSpan(): void
    {
        $tracer = new Tracer(new InMemorySpanExporter());
        $traceId = TraceId::generate();

        $span = $tracer->startSpan('op', SpanKind::Internal, null, $traceId);

        self::assertSame($traceId, $span->traceId());
        self::assertNull($span->parentSpanId());
    }

    public function testParentContextTakesPrecedenceOverTraceId(): void
    {
        $tracer = new Tracer(new InMemorySpanExporter());
        $parent = new SpanContext(TraceId::generate(), SpanId::generate());
        $unusedTraceId = TraceId::generate();

        $span = $tracer->startSpan('op', SpanKind::Internal, $parent, $unusedTraceId);

        self::assertSame($parent->traceId, $span->traceId());
        self::assertNotSame($unusedTraceId, $span->traceId());
    }

    public function testEndSpanExportsTheSpan(): void
    {
        $exporter = new InMemorySpanExporter();
        $tracer = new Tracer($exporter);

        $span = $tracer->startSpan('op');
        $tracer->endSpan($span, SpanStatusCode::Ok);

        self::assertCount(1, $exporter->exported());
        self::assertSame($span, $exporter->exported()[0]);
        self::assertTrue($span->isEnded());
        self::assertSame(SpanStatusCode::Ok, $span->status());
    }
}
