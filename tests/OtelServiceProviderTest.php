<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Otel\Exporter\BatchingSpanExporter;
use EzPhp\Otel\Exporter\InMemorySpanExporter;
use EzPhp\Otel\Exporter\OtlpHttpExporter;
use EzPhp\Otel\Exporter\TraceIdRatioSampler;
use EzPhp\Otel\Otel;
use EzPhp\Otel\OtelServiceProvider;
use EzPhp\Otel\SpanExporterInterface;
use EzPhp\Otel\Tracer;
use RuntimeException;
use Tests\Support\FakeConfig;
use Tests\Support\FakeContainer;

final class OtelServiceProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        Otel::resetTracer();
    }

    public function testRegisterBindsTracer(): void
    {
        $container = new FakeContainer(new FakeConfig([]));

        (new OtelServiceProvider($container))->register();

        self::assertTrue($container->wasBound(Tracer::class));
        self::assertInstanceOf(Tracer::class, $container->make(Tracer::class));
    }

    public function testBootWiresFacade(): void
    {
        $container = new FakeContainer(new FakeConfig([]));
        $provider = new OtelServiceProvider($container);

        $provider->register();
        $provider->boot();

        self::assertInstanceOf(Tracer::class, Otel::tracer());
    }

    public function testFacadeThrowsBeforeBoot(): void
    {
        $this->expectException(RuntimeException::class);

        Otel::tracer();
    }

    public function testMemoryAndOtlpAndDefaultModesAllResolveATracer(): void
    {
        $configs = [
            ['otel.exporter' => 'memory'],
            ['otel.endpoint' => 'http://127.0.0.1:1/v1/traces', 'otel.service_name' => 'svc', 'otel.exporter' => 'otlp'],
            ['otel.exporter' => 'otlp'],
            [],
        ];

        foreach ($configs as $config) {
            $container = new FakeContainer(new FakeConfig($config));
            (new OtelServiceProvider($container))->register();

            self::assertInstanceOf(Tracer::class, $container->make(Tracer::class));
        }
    }

    public function testOtlpIsBatchedByDefault(): void
    {
        $exporter = $this->exporterFor(['otel.endpoint' => 'http://127.0.0.1:1/v1/traces']);

        self::assertInstanceOf(BatchingSpanExporter::class, $exporter);
    }

    public function testBatchSizeZeroDisablesBatching(): void
    {
        $exporter = $this->exporterFor(['otel.endpoint' => 'http://127.0.0.1:1/v1/traces', 'otel.batch_size' => 0]);

        self::assertInstanceOf(OtlpHttpExporter::class, $exporter);
    }

    public function testSampleRatioBelowOneWrapsTheExporterInASampler(): void
    {
        $exporter = $this->exporterFor(['otel.endpoint' => 'http://127.0.0.1:1/v1/traces', 'otel.sample_ratio' => '0.25']);

        self::assertInstanceOf(TraceIdRatioSampler::class, $exporter);
    }

    public function testMemoryModeIsNeitherBatchedNorSampled(): void
    {
        $exporter = $this->exporterFor(['otel.exporter' => 'memory', 'otel.sample_ratio' => 0.1, 'otel.batch_size' => 10]);

        self::assertInstanceOf(InMemorySpanExporter::class, $exporter);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function exporterFor(array $config): SpanExporterInterface
    {
        $container = new FakeContainer(new FakeConfig($config));
        (new OtelServiceProvider($container))->register();
        $tracer = $container->make(Tracer::class);

        $exporter = (new \ReflectionProperty(Tracer::class, 'exporter'))->getValue($tracer);
        self::assertInstanceOf(SpanExporterInterface::class, $exporter);

        return $exporter;
    }
}
