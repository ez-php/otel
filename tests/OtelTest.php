<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Otel\Exporter\InMemorySpanExporter;
use EzPhp\Otel\Otel;
use EzPhp\Otel\Tracer;
use RuntimeException;

/**
 * @package Tests
 */
final class OtelTest extends TestCase
{
    protected function tearDown(): void
    {
        Otel::resetTracer();
    }

    public function testTracerThrowsBeforeItIsSet(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OtelServiceProvider');

        Otel::tracer();
    }

    public function testSetTracerMakesItAvailable(): void
    {
        $tracer = new Tracer(new InMemorySpanExporter());

        Otel::setTracer($tracer);

        self::assertSame($tracer, Otel::tracer());
    }

    public function testResetTracerClearsIt(): void
    {
        Otel::setTracer(new Tracer(new InMemorySpanExporter()));
        Otel::resetTracer();

        $this->expectException(RuntimeException::class);
        Otel::tracer();
    }
}
