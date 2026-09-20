<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Otel\Span;
use EzPhp\Otel\SpanKind;
use EzPhp\Otel\SpanStatusCode;

final class SpanTest extends TestCase
{
    public function testConstructorSetsIdentityAndStartsUnended(): void
    {
        $span = new Span('trace123', 'span123', 'parent123', 'do-work', SpanKind::Client);

        self::assertSame('trace123', $span->traceId());
        self::assertSame('span123', $span->spanId());
        self::assertSame('parent123', $span->parentSpanId());
        self::assertSame('do-work', $span->name());
        self::assertSame(SpanKind::Client, $span->kind());
        self::assertSame(SpanStatusCode::Unset, $span->status());
        self::assertFalse($span->isEnded());
        self::assertSame(0, $span->endTimeUnixNano());
        self::assertGreaterThan(0, $span->startTimeUnixNano());
    }

    public function testDefaultKindIsInternalAndParentIsOptional(): void
    {
        $span = new Span('trace123', 'span123', null, 'root');

        self::assertSame(SpanKind::Internal, $span->kind());
        self::assertNull($span->parentSpanId());
    }

    public function testSetAttributeAccumulates(): void
    {
        $span = new Span('t', 's', null, 'n');

        $span->setAttribute('http.method', 'GET');
        $span->setAttribute('http.status_code', 200);

        self::assertSame(['http.method' => 'GET', 'http.status_code' => 200], $span->attributes());
    }

    public function testEndSetsStatusAndEndTimeAndMarksEnded(): void
    {
        $span = new Span('t', 's', null, 'n');

        $span->end(SpanStatusCode::Error);

        self::assertTrue($span->isEnded());
        self::assertSame(SpanStatusCode::Error, $span->status());
        self::assertGreaterThan(0, $span->endTimeUnixNano());
        self::assertGreaterThanOrEqual($span->startTimeUnixNano(), $span->endTimeUnixNano());
    }

    public function testEndIsIdempotent(): void
    {
        $span = new Span('t', 's', null, 'n');

        $span->end(SpanStatusCode::Ok);
        $firstEndTime = $span->endTimeUnixNano();

        $span->end(SpanStatusCode::Error);

        self::assertSame(SpanStatusCode::Ok, $span->status());
        self::assertSame($firstEndTime, $span->endTimeUnixNano());
    }
}
