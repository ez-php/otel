<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Otel\TraceId;

final class TraceIdTest extends TestCase
{
    public function testGenerateProducesValid32HexTraceId(): void
    {
        $id = TraceId::generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        self::assertTrue(TraceId::isValid($id));
    }

    public function testGenerateProducesDistinctIds(): void
    {
        self::assertNotSame(TraceId::generate(), TraceId::generate());
    }

    public function testFromSeedIsDeterministic(): void
    {
        $a = TraceId::fromSeed('request-abc123');
        $b = TraceId::fromSeed('request-abc123');

        self::assertSame($a, $b);
        self::assertTrue(TraceId::isValid($a));
    }

    public function testFromSeedDiffersForDifferentSeeds(): void
    {
        self::assertNotSame(TraceId::fromSeed('a'), TraceId::fromSeed('b'));
    }

    public function testIsValidRejectsAllZeroTraceId(): void
    {
        self::assertFalse(TraceId::isValid(str_repeat('0', 32)));
    }

    /**
     * @return void
     */
    public function testIsValidRejectsWrongLength(): void
    {
        self::assertFalse(TraceId::isValid('abc'));
    }

    public function testIsValidRejectsUppercaseHex(): void
    {
        self::assertFalse(TraceId::isValid(strtoupper(TraceId::generate())));
    }
}
