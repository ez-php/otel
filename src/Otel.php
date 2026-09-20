<?php

declare(strict_types=1);

namespace EzPhp\Otel;

use RuntimeException;

/**
 * Static facade for the {@see Tracer} singleton, following the same pattern
 * as `Health`, `Log`, and `Mail` in sibling modules. Wired by
 * {@see OtelServiceProvider::boot()}. Call {@see resetTracer()} in test
 * tearDown() to prevent state leakage.
 *
 * @package EzPhp\Otel
 */
final class Otel
{
    private static ?Tracer $tracer = null;

    /**
     * @param Tracer $tracer
     *
     * @return void
     */
    public static function setTracer(Tracer $tracer): void
    {
        self::$tracer = $tracer;
    }

    /**
     * @return void
     */
    public static function resetTracer(): void
    {
        self::$tracer = null;
    }

    /**
     * @return Tracer
     *
     * @throws RuntimeException When called before {@see setTracer()}.
     */
    public static function tracer(): Tracer
    {
        if (self::$tracer === null) {
            throw new RuntimeException('Otel::setTracer() has not been called — is OtelServiceProvider registered?');
        }

        return self::$tracer;
    }
}
