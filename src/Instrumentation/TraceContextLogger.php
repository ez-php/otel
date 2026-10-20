<?php

declare(strict_types=1);

namespace EzPhp\Otel\Instrumentation;

use EzPhp\Logging\LoggerInterface;
use EzPhp\Logging\LogLevel;
use EzPhp\Otel\Tracer;

/**
 * Class TraceContextLogger
 *
 * ez-php/logging LoggerInterface decorator that adds `trace_id` and `span_id`
 * of the tracer's innermost active span (e.g. the SERVER span OtelMiddleware
 * opened) to every log record's context, so log lines can be joined with the
 * spans they were written under. Outside any active span the context passes
 * through unchanged. Context keys the caller set explicitly win.
 *
 * Wired explicitly by the application, like TracingTransport/TracingDatabase:
 *
 *     $logger = new TraceContextLogger($logger, $tracer);
 *
 * Only correlation — logs are still written by ez-php/logging's drivers; there
 * is no OTLP log export.
 *
 * @package EzPhp\Otel\Instrumentation
 */
final readonly class TraceContextLogger implements LoggerInterface
{
    /**
     * @param LoggerInterface $inner  Logger to delegate to.
     * @param Tracer          $tracer Tracer whose active span context is attached.
     */
    public function __construct(
        private LoggerInterface $inner,
        private Tracer $tracer,
    ) {
    }

    /**
     * @param LogLevel             $level
     * @param string               $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    public function log(LogLevel $level, string $message, array $context = []): void
    {
        $active = $this->tracer->activeContext();

        if ($active !== null) {
            $context += ['trace_id' => $active->traceId, 'span_id' => $active->spanId];
        }

        $this->inner->log($level, $message, $context);
    }

    /**
     * @param string               $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * @param string               $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * @param string               $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * @param string               $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * @param string               $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }
}
