<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Otel\SpanContext;
use EzPhp\Otel\TraceParent;

final class TraceParentTest extends TestCase
{
    public function testParsesValidSampledHeader(): void
    {
        $traceId = str_repeat('a', 32);
        $spanId = str_repeat('b', 16);

        $context = TraceParent::parse("00-{$traceId}-{$spanId}-01");

        self::assertInstanceOf(SpanContext::class, $context);
        self::assertSame($traceId, $context->traceId);
        self::assertSame($spanId, $context->spanId);
        self::assertTrue($context->sampled);
    }

    public function testParsesUnsampledHeader(): void
    {
        $traceId = str_repeat('a', 32);
        $spanId = str_repeat('b', 16);

        $context = TraceParent::parse("00-{$traceId}-{$spanId}-00");

        self::assertNotNull($context);
        self::assertFalse($context->sampled);
    }

    public function testRejectsUnsupportedVersion(): void
    {
        $traceId = str_repeat('a', 32);
        $spanId = str_repeat('b', 16);

        self::assertNull(TraceParent::parse("ff-{$traceId}-{$spanId}-01"));
    }

    public function testRejectsMalformedHeader(): void
    {
        self::assertNull(TraceParent::parse('not-a-traceparent'));
    }

    public function testRejectsAllZeroTraceId(): void
    {
        $spanId = str_repeat('b', 16);

        self::assertNull(TraceParent::parse('00-' . str_repeat('0', 32) . "-{$spanId}-01"));
    }

    public function testFormatRoundTripsThroughParse(): void
    {
        $original = new SpanContext(str_repeat('a', 32), str_repeat('b', 16), true);

        $parsed = TraceParent::parse(TraceParent::format($original));

        self::assertEquals($original, $parsed);
    }
}
