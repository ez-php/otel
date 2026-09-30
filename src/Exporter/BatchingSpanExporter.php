<?php

declare(strict_types=1);

namespace EzPhp\Otel\Exporter;

use EzPhp\Otel\Span;
use EzPhp\Otel\SpanExporterInterface;

/**
 * Class BatchingSpanExporter
 *
 * Decorator that buffers spans and hands them to the inner exporter in one call
 * instead of one call per span: when `$maxBatchSize` spans are waiting, on
 * {@see flush()}, and — unless disabled — at the end of the PHP request via a
 * shutdown function, which runs after the framework's terminate(). Long-running
 * processes (queue workers) should call flush() themselves, e.g. after each job.
 *
 * @package EzPhp\Otel\Exporter
 */
final class BatchingSpanExporter implements SpanExporterInterface
{
    /**
     * @var list<Span>
     */
    private array $buffer = [];

    private bool $shutdownRegistered = false;

    /**
     * @param SpanExporterInterface $inner
     * @param int                   $maxBatchSize    Spans per export call; a full buffer is exported at once.
     * @param bool                  $flushOnShutdown Export what is left when the process ends.
     */
    public function __construct(
        private readonly SpanExporterInterface $inner,
        private readonly int $maxBatchSize = 512,
        private readonly bool $flushOnShutdown = true,
    ) {
    }

    /**
     * Buffer the spans; never fails, since nothing is sent yet.
     *
     * @param list<Span> $spans
     *
     * @return bool
     */
    public function export(array $spans): bool
    {
        if ($this->flushOnShutdown && !$this->shutdownRegistered) {
            register_shutdown_function(function (): void {
                $this->flush();
            });
            $this->shutdownRegistered = true;
        }

        foreach ($spans as $span) {
            $this->buffer[] = $span;

            if (count($this->buffer) >= max(1, $this->maxBatchSize)) {
                $this->flush();
            }
        }

        return true;
    }

    /**
     * Export and clear the buffer.
     *
     * @return bool The inner exporter's result; true when there was nothing to send.
     */
    public function flush(): bool
    {
        if ($this->buffer === []) {
            return true;
        }

        $batch = $this->buffer;
        $this->buffer = [];

        return $this->inner->export($batch);
    }
}
