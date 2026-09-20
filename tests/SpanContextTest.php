<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Otel\SpanContext;

final class SpanContextTest extends TestCase
{
    public function testHoldsIdsAndDefaultsToSampled(): void
    {
        $context = new SpanContext('t', 's');

        self::assertSame('t', $context->traceId);
        self::assertSame('s', $context->spanId);
        self::assertTrue($context->sampled);
    }
}
