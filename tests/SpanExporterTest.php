<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Otel\Exporter\InMemorySpanExporter;
use EzPhp\Otel\Exporter\NullSpanExporter;
use EzPhp\Otel\Span;

final class SpanExporterTest extends TestCase
{
    public function testInMemoryExporterCollectsAndResets(): void
    {
        $exporter = new InMemorySpanExporter();
        $a = new Span('t', 's1', null, 'a');
        $b = new Span('t', 's2', null, 'b');

        self::assertTrue($exporter->export([$a]));
        self::assertTrue($exporter->export([$b]));
        self::assertSame([$a, $b], $exporter->exported());

        $exporter->reset();

        self::assertSame([], $exporter->exported());
    }

    public function testNullExporterAcceptsAndDiscards(): void
    {
        self::assertTrue((new NullSpanExporter())->export([new Span('t', 's', null, 'n')]));
    }
}
