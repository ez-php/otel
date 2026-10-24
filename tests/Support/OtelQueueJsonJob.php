<?php

declare(strict_types=1);

namespace Tests\Support;

use EzPhp\Queue\Job;
use EzPhp\Queue\JsonPayloadJobInterface;

/**
 * Queue job fixture stored as JSON by ez-php/queue.
 */
final class OtelQueueJsonJob extends Job implements JsonPayloadJobInterface
{
    public function __construct(public readonly string $value = 'v')
    {
    }

    public function handle(): void
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return ['value' => $this->value];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): static
    {
        return new self(is_string($payload['value'] ?? null) ? $payload['value'] : '');
    }
}
