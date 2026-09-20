<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Otel\SpanId;

final class SpanIdTest extends TestCase
{
    public function testGenerateProducesValid16HexSpanId(): void
    {
        $id = SpanId::generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id);
        self::assertTrue(SpanId::isValid($id));
    }

    public function testGenerateProducesDistinctIds(): void
    {
        self::assertNotSame(SpanId::generate(), SpanId::generate());
    }

    public function testIsValidRejectsAllZeroSpanId(): void
    {
        self::assertFalse(SpanId::isValid(str_repeat('0', 16)));
    }

    public function testIsValidRejectsWrongLength(): void
    {
        self::assertFalse(SpanId::isValid('abc'));
    }
}
