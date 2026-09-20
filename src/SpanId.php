<?php

declare(strict_types=1);

namespace EzPhp\Otel;

/**
 * Generates and validates OTLP span IDs — 8 bytes, encoded as 16 lowercase
 * hex characters.
 *
 * @package EzPhp\Otel
 */
final class SpanId
{
    private function __construct()
    {
    }

    /**
     * @return string 16 lowercase hex characters.
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * @param string $id
     *
     * @return bool
     */
    public static function isValid(string $id): bool
    {
        return preg_match('/^[0-9a-f]{16}$/', $id) === 1 && $id !== str_repeat('0', 16);
    }
}
