<?php

declare(strict_types=1);

namespace EzPhp\Otel;

/**
 * Generates and validates OTLP trace IDs — 16 bytes, encoded as 32 lowercase
 * hex characters.
 *
 * @package EzPhp\Otel
 */
final class TraceId
{
    private function __construct()
    {
    }

    /**
     * Generate a new random trace ID.
     *
     * @return string 32 lowercase hex characters.
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Deterministically derive a 32-hex trace ID from an arbitrary seed string,
     * such as a `request_id` produced elsewhere (e.g.
     * `EzPhp\Logging\RequestContextMiddleware`'s 16-hex request ID), so a single
     * request correlates the same trace ID across logs and spans without this
     * module depending on `ez-php/logging`.
     *
     * @param string $seed
     *
     * @return string 32 lowercase hex characters.
     */
    public static function fromSeed(string $seed): string
    {
        return substr(hash('sha256', $seed), 0, 32);
    }

    /**
     * @param string $id
     *
     * @return bool
     */
    public static function isValid(string $id): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $id) === 1 && $id !== str_repeat('0', 32);
    }
}
