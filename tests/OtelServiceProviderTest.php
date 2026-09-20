<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Otel\Otel;
use EzPhp\Otel\OtelServiceProvider;
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
}
