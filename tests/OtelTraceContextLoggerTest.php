<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Logging\LoggerInterface;
use EzPhp\Logging\LogLevel;
use EzPhp\Otel\Exporter\InMemorySpanExporter;
use EzPhp\Otel\Instrumentation\TraceContextLogger;
use EzPhp\Otel\Span;
use EzPhp\Otel\SpanContext;
use EzPhp\Otel\SpanId;
use EzPhp\Otel\SpanKind;
use EzPhp\Otel\SpanStatusCode;
use EzPhp\Otel\TraceId;
use EzPhp\Otel\Tracer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * TraceContextLogger adds the active span's trace/span id to log context.
 *
 * @package Tests
 */
#[CoversClass(TraceContextLogger::class)]
#[UsesClass(Tracer::class)]
#[UsesClass(Span::class)]
#[UsesClass(SpanContext::class)]
#[UsesClass(SpanId::class)]
#[UsesClass(TraceId::class)]
#[UsesClass(InMemorySpanExporter::class)]
final class OtelTraceContextLoggerTest extends TestCase
{
    /**
     * @return LoggerInterface&object{records: list<array{LogLevel, string, array<string, mixed>}>}
     */
    private function recorder(): LoggerInterface
    {
        return new class () implements LoggerInterface {
            /** @var list<array{LogLevel, string, array<string, mixed>}> */
            public array $records = [];

            public function log(LogLevel $level, string $message, array $context = []): void
            {
                $this->records[] = [$level, $message, $context];
            }

            public function debug(string $message, array $context = []): void
            {
                $this->log(LogLevel::DEBUG, $message, $context);
            }

            public function info(string $message, array $context = []): void
            {
                $this->log(LogLevel::INFO, $message, $context);
            }

            public function warning(string $message, array $context = []): void
            {
                $this->log(LogLevel::WARNING, $message, $context);
            }

            public function error(string $message, array $context = []): void
            {
                $this->log(LogLevel::ERROR, $message, $context);
            }

            public function critical(string $message, array $context = []): void
            {
                $this->log(LogLevel::CRITICAL, $message, $context);
            }
        };
    }

    public function test_active_span_ids_are_added_to_every_level(): void
    {
        $tracer = new Tracer(new InMemorySpanExporter());
        $inner = $this->recorder();
        $logger = new TraceContextLogger($inner, $tracer);

        $span = $tracer->startSpan('GET /orders', SpanKind::Server);
        $tracer->activate($span);

        $logger->debug('d');
        $logger->info('i', ['order' => 7]);
        $logger->warning('w');
        $logger->error('e');
        $logger->critical('c');

        $tracer->deactivate($span);
        $tracer->endSpan($span, SpanStatusCode::Ok);

        $this->assertCount(5, $inner->records);
        $this->assertSame(
            [LogLevel::DEBUG, LogLevel::INFO, LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL],
            array_column($inner->records, 0),
        );

        foreach ($inner->records as [, , $context]) {
            $this->assertSame($span->traceId(), $context['trace_id']);
            $this->assertSame($span->spanId(), $context['span_id']);
        }

        $this->assertSame(7, $inner->records[1][2]['order']);
    }

    public function test_context_passes_through_unchanged_outside_any_span(): void
    {
        $inner = $this->recorder();

        (new TraceContextLogger($inner, new Tracer(new InMemorySpanExporter())))->info('m', ['a' => 1]);

        $this->assertSame([[LogLevel::INFO, 'm', ['a' => 1]]], $inner->records);
    }

    public function test_innermost_span_wins_and_explicit_keys_are_kept(): void
    {
        $tracer = new Tracer(new InMemorySpanExporter());
        $inner = $this->recorder();
        $logger = new TraceContextLogger($inner, $tracer);

        $outer = $tracer->startSpan('outer');
        $tracer->activate($outer);
        $child = $tracer->startSpan('child', parent: $tracer->activeContext());
        $tracer->activate($child);

        $logger->info('inner');
        $logger->info('explicit', ['trace_id' => 'caller-set']);

        $this->assertSame($child->spanId(), $inner->records[0][2]['span_id']);
        $this->assertSame($outer->traceId(), $inner->records[0][2]['trace_id']);
        $this->assertSame('caller-set', $inner->records[1][2]['trace_id']);
    }
}
