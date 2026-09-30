<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Otel\Exporter\BatchingSpanExporter;
use EzPhp\Otel\Exporter\InMemorySpanExporter;
use EzPhp\Otel\Exporter\TraceIdRatioSampler;
use EzPhp\Otel\Span;
use EzPhp\Otel\SpanExporterInterface;
use EzPhp\Otel\TraceId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Counts export() calls, to prove batching reduces them.
 */
final class OtelCountingExporter implements SpanExporterInterface
{
    /** @var list<int> */
    public array $batchSizes = [];

    public function export(array $spans): bool
    {
        $this->batchSizes[] = count($spans);

        return true;
    }
}

/**
 * Class BatchingAndSamplingTest
 *
 * @package Tests
 */
#[CoversClass(BatchingSpanExporter::class)]
#[CoversClass(TraceIdRatioSampler::class)]
#[UsesClass(Span::class)]
#[UsesClass(InMemorySpanExporter::class)]
#[UsesClass(TraceId::class)]
final class BatchingAndSamplingTest extends TestCase
{
    private function span(string $traceId = '0af7651916cd43dd8448eb211c80319c', string $name = 's'): Span
    {
        return new Span($traceId, 'b7ad6b7169203331', null, $name);
    }

    public function test_batching_buffers_until_flush(): void
    {
        $inner = new OtelCountingExporter();
        $batching = new BatchingSpanExporter($inner, maxBatchSize: 10, flushOnShutdown: false);

        self::assertTrue($batching->export([$this->span()]));
        self::assertTrue($batching->export([$this->span(), $this->span()]));
        self::assertSame([], $inner->batchSizes);

        self::assertTrue($batching->flush());
        self::assertSame([3], $inner->batchSizes);
        self::assertTrue($batching->flush(), 'an empty flush is a no-op success');
        self::assertSame([3], $inner->batchSizes);
    }

    public function test_batching_flushes_when_the_batch_is_full(): void
    {
        $inner = new OtelCountingExporter();
        $batching = new BatchingSpanExporter($inner, maxBatchSize: 2, flushOnShutdown: false);

        $batching->export([$this->span()]);
        $batching->export([$this->span()]);
        $batching->export([$this->span()]);

        self::assertSame([2], $inner->batchSizes);
        $batching->flush();
        self::assertSame([2, 1], $inner->batchSizes);
    }

    public function test_batching_hands_spans_through_in_order(): void
    {
        $inner = new InMemorySpanExporter();
        $batching = new BatchingSpanExporter($inner, flushOnShutdown: false);

        $batching->export([$this->span(name: 'a'), $this->span(name: 'b')]);
        $batching->flush();

        self::assertSame(['a', 'b'], array_map(static fn (Span $s): string => $s->name(), $inner->exported()));
    }

    public function test_sampler_ratio_one_keeps_everything_and_zero_nothing(): void
    {
        $all = new InMemorySpanExporter();
        $none = new InMemorySpanExporter();

        (new TraceIdRatioSampler($all, 1.0))->export([$this->span(TraceId::generate())]);
        (new TraceIdRatioSampler($none, 0.0))->export([$this->span(TraceId::generate())]);

        self::assertCount(1, $all->exported());
        self::assertSame([], $none->exported());
    }

    public function test_sampler_decides_per_trace_so_a_trace_is_kept_or_dropped_whole(): void
    {
        $inner = new InMemorySpanExporter();
        $sampler = new TraceIdRatioSampler($inner, 0.5);
        $traces = array_map(static fn (): string => TraceId::generate(), range(1, 200));

        foreach ($traces as $traceId) {
            $sampler->export([$this->span($traceId, 'first'), $this->span($traceId, 'second')]);
        }

        $kept = array_count_values(array_map(static fn (Span $s): string => $s->traceId(), $inner->exported()));

        foreach ($kept as $count) {
            self::assertSame(2, $count);
        }

        // Roughly half of 200 random traces (binomial; 60–140 is far outside chance).
        self::assertGreaterThan(60, count($kept));
        self::assertLessThan(140, count($kept));
    }

    public function test_sampler_is_deterministic_for_a_trace_id(): void
    {
        $a = new InMemorySpanExporter();
        $b = new InMemorySpanExporter();
        $traceId = TraceId::generate();

        (new TraceIdRatioSampler($a, 0.3))->export([$this->span($traceId)]);
        (new TraceIdRatioSampler($b, 0.3))->export([$this->span($traceId)]);

        self::assertSame(count($a->exported()), count($b->exported()));
    }

    public function test_sampler_rejects_an_invalid_ratio(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TraceIdRatioSampler(new InMemorySpanExporter(), 1.5);
    }
}
