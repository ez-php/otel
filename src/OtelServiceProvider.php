<?php

declare(strict_types=1);

namespace EzPhp\Otel;

use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\ServiceProvider;
use EzPhp\Otel\Exporter\InMemorySpanExporter;
use EzPhp\Otel\Exporter\NullSpanExporter;
use EzPhp\Otel\Exporter\OtlpHttpExporter;
use Throwable;

/**
 * Binds the {@see Tracer} singleton and wires the {@see Otel} facade.
 *
 * Exporter selection, from `config/otel.php` (see `docs/CONFIG.md`):
 *   - `otel.exporter` = `'otlp'` (default when `otel.endpoint` is set) — {@see OtlpHttpExporter}
 *     against `otel.endpoint`, tagged with `otel.service_name`
 *   - `otel.exporter` = `'memory'` — {@see InMemorySpanExporter}, useful in tests/dev
 *   - anything else, or no `ConfigInterface` bound — {@see NullSpanExporter} (spans discarded)
 *
 * Does not register {@see OtelMiddleware} itself — add it explicitly via
 * `$app->middleware(OtelMiddleware::class)`, the same way `ez-php/rate-limiter`
 * leaves `ThrottleMiddleware` for the application to wire per-route or globally.
 *
 * @package EzPhp\Otel
 */
final class OtelServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(Tracer::class, function (ContainerInterface $app): Tracer {
            return new Tracer($this->resolveExporter($app));
        });
    }

    /**
     * @return void
     */
    public function boot(): void
    {
        Otel::setTracer($this->app->make(Tracer::class));
    }

    /**
     * @param ContainerInterface $app
     *
     * @return SpanExporterInterface
     */
    private function resolveExporter(ContainerInterface $app): SpanExporterInterface
    {
        try {
            /** @var ConfigInterface $config */
            $config = $app->make(ConfigInterface::class);
        } catch (Throwable) {
            return new NullSpanExporter();
        }

        $endpointValue = $config->get('otel.endpoint', null);
        $endpoint = is_string($endpointValue) ? $endpointValue : null;

        $modeValue = $config->get('otel.exporter', $endpoint !== null ? 'otlp' : 'null');
        $mode = is_string($modeValue) ? $modeValue : 'null';

        if ($mode === 'memory') {
            return new InMemorySpanExporter();
        }

        if ($mode === 'otlp' && $endpoint !== null) {
            $serviceNameValue = $config->get('otel.service_name', 'ez-php-app');
            $serviceName = is_string($serviceNameValue) ? $serviceNameValue : 'ez-php-app';

            return new OtlpHttpExporter($endpoint, $serviceName);
        }

        return new NullSpanExporter();
    }
}
