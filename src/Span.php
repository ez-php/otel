<?php

declare(strict_types=1);

namespace EzPhp\Otel;

/**
 * A single span. Mutable between {@see Tracer::startSpan()} and
 * {@see Tracer::endSpan()} — attributes accumulate and the status/end time are
 * set once, at the end of the span's lifetime.
 *
 * @package EzPhp\Otel
 */
final class Span
{
    /**
     * @var array<string, bool|int|float|string>
     */
    private array $attributes = [];

    private int $endTimeUnixNano = 0;

    private SpanStatusCode $status = SpanStatusCode::Unset;

    private bool $ended = false;

    private readonly int $startTimeUnixNano;

    /**
     * @param string      $traceId      32 lowercase hex characters.
     * @param string      $spanId       16 lowercase hex characters.
     * @param string|null $parentSpanId 16 lowercase hex characters, or null for a root span.
     * @param string      $name
     * @param SpanKind    $kind
     */
    public function __construct(
        private readonly string $traceId,
        private readonly string $spanId,
        private readonly ?string $parentSpanId,
        private readonly string $name,
        private readonly SpanKind $kind = SpanKind::Internal,
    ) {
        $this->startTimeUnixNano = (int) (microtime(true) * 1_000_000_000);
    }

    /**
     * @return string
     */
    public function traceId(): string
    {
        return $this->traceId;
    }

    /**
     * @return string
     */
    public function spanId(): string
    {
        return $this->spanId;
    }

    /**
     * @return string|null
     */
    public function parentSpanId(): ?string
    {
        return $this->parentSpanId;
    }

    /**
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return SpanKind
     */
    public function kind(): SpanKind
    {
        return $this->kind;
    }

    /**
     * @return int Unix epoch nanoseconds.
     */
    public function startTimeUnixNano(): int
    {
        return $this->startTimeUnixNano;
    }

    /**
     * @return int Unix epoch nanoseconds; 0 until {@see end()} is called.
     */
    public function endTimeUnixNano(): int
    {
        return $this->endTimeUnixNano;
    }

    /**
     * @return SpanStatusCode
     */
    public function status(): SpanStatusCode
    {
        return $this->status;
    }

    /**
     * @return array<string, bool|int|float|string>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param string             $key
     * @param bool|int|float|string $value
     *
     * @return void
     */
    public function setAttribute(string $key, bool|int|float|string $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * @return bool
     */
    public function isEnded(): bool
    {
        return $this->ended;
    }

    /**
     * Mark the span as finished. A no-op if already ended.
     *
     * @param SpanStatusCode $status
     *
     * @return void
     */
    public function end(SpanStatusCode $status = SpanStatusCode::Ok): void
    {
        if ($this->ended) {
            return;
        }

        $this->status = $status;
        $this->endTimeUnixNano = (int) (microtime(true) * 1_000_000_000);
        $this->ended = true;
    }
}
