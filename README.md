# ez-php/otel

OpenTelemetry spans and OTLP/HTTP JSON export for the ez-php framework — no vendor SDK, no protobuf, stdlib only.

## Installation

```bash
composer require ez-php/otel
```

Register the provider in `provider/modules.php`:

```php
EzPhp\Otel\OtelServiceProvider::class,
```

## Configuration (`config/otel.php`)

| Key | Env var | Default | Description |
|---|---|---|---|
| `otel.exporter` | `OTEL_EXPORTER` | `otlp` if an endpoint is set, else discard | `otlp`, `memory`, or anything else to discard spans |
| `otel.endpoint` | `OTEL_EXPORTER_OTLP_ENDPOINT` | `null` | Full OTLP/HTTP traces URL, e.g. `http://localhost:4318/v1/traces` |
| `otel.service_name` | `OTEL_SERVICE_NAME` | `ez-php-app` | `service.name` resource attribute |

## Usage

Trace every HTTP request by adding the middleware:

```php
$app->middleware(EzPhp\Otel\OtelMiddleware::class);
```

It creates a SERVER span per request, continues an incoming W3C `traceparent` header when present, and marks the span as an error on 5xx responses or exceptions.

To share one trace ID between logs and spans, construct the middleware with a seed resolver that returns the same correlation ID your logger uses (for example the `request_id` from `ez-php/logging`'s `RequestContextMiddleware`):

```php
new OtelMiddleware($tracer, static fn (): ?string => $currentRequestId);
```

Custom spans:

```php
use EzPhp\Otel\Otel;
use EzPhp\Otel\SpanKind;

$tracer = Otel::tracer();
$span = $tracer->startSpan('charge-card', SpanKind::Client);
$span->setAttribute('payment.provider', 'stripe');
// ... work ...
$tracer->endSpan($span);
```

## Limits

Each ended span is exported immediately as one OTLP request — there is no batching, sampler chain, or metrics/logs signal. Wrap `SpanExporterInterface` yourself if you need buffering.
