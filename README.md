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

With the OTLP exporter, spans are batched (`otel.batch_size`, default 512 per request; the remainder
is sent at the end of the PHP request) and optionally head-sampled (`otel.sample_ratio`, e.g. `0.1` keeps
10 % of traces — decided per trace ID, so a trace is never cut in half). Both are plain exporter
decorators you can also compose by hand:

```php
use EzPhp\Otel\Exporter\BatchingSpanExporter;
use EzPhp\Otel\Exporter\TraceIdRatioSampler;

$exporter = new TraceIdRatioSampler(new BatchingSpanExporter(new OtlpHttpExporter($url, 'svc')), ratio: 0.1);
```

### Outgoing HTTP and database spans

Wrap the HTTP client transport and the database connection; their spans become children of the
request's SERVER span (`OtelMiddleware` marks it active), and outgoing requests carry a `traceparent`
header so the called service joins the trace:

```php
use EzPhp\Otel\Instrumentation\TracingDatabase;
use EzPhp\Otel\Instrumentation\TracingTransport;

$client = new HttpClient(new TracingTransport(new CurlTransport(), $tracer));   // requires ez-php/http-client
$db = new TracingDatabase($database, $tracer);                                  // any DatabaseInterface
```

Database spans carry the SQL statement (`db.statement`), never the bindings. Streaming HTTP requests are
not traced.

A long-running worker should call `$batching->flush()` after each job. There is no tail sampling and no
metrics/logs signal.
