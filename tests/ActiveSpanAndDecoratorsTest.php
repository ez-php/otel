<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Contracts\DatabaseInterface;
use EzPhp\Http\Request;
use EzPhp\Http\Response;
use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClientException;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\Otel\Exporter\InMemorySpanExporter;
use EzPhp\Otel\Instrumentation\TracingDatabase;
use EzPhp\Otel\Instrumentation\TracingTransport;
use EzPhp\Otel\OtelMiddleware;
use EzPhp\Otel\Span;
use EzPhp\Otel\SpanContext;
use EzPhp\Otel\SpanId;
use EzPhp\Otel\SpanKind;
use EzPhp\Otel\SpanStatusCode;
use EzPhp\Otel\TraceId;
use EzPhp\Otel\TraceParent;
use EzPhp\Otel\Tracer;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * DatabaseInterface double that records calls and can fail.
 */
final class OtelRecordingDatabase implements DatabaseInterface
{
    /** @var list<string> */
    public array $sql = [];

    public function query(string $sql, array $bindings = []): array
    {
        $this->sql[] = $sql;

        if (str_contains($sql, 'FAIL')) {
            throw new \RuntimeException('db down');
        }

        return [['n' => 1]];
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $this->sql[] = $sql;

        return 3;
    }

    public function transaction(callable $fn): mixed
    {
        return $fn();
    }

    public function getPdo(): PDO
    {
        return new PDO('sqlite::memory:');
    }
}

/**
 * Class ActiveSpanAndDecoratorsTest
 *
 * @package Tests
 */
#[CoversClass(Tracer::class)]
#[CoversClass(OtelMiddleware::class)]
#[CoversClass(TracingTransport::class)]
#[CoversClass(TracingDatabase::class)]
#[UsesClass(Span::class)]
#[UsesClass(InMemorySpanExporter::class)]
#[UsesClass(SpanContext::class)]
#[UsesClass(SpanId::class)]
#[UsesClass(TraceId::class)]
#[UsesClass(TraceParent::class)]
final class ActiveSpanAndDecoratorsTest extends TestCase
{
    private InMemorySpanExporter $exporter;

    private Tracer $tracer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = new InMemorySpanExporter();
        $this->tracer = new Tracer($this->exporter);
    }

    /**
     * @return array<string, Span>
     */
    private function spansByName(): array
    {
        $byName = [];

        foreach ($this->exporter->exported() as $span) {
            $byName[$span->name()] = $span;
        }

        return $byName;
    }

    public function test_active_context_follows_activate_and_deactivate(): void
    {
        self::assertNull($this->tracer->activeContext());

        $outer = $this->tracer->startSpan('outer');
        $this->tracer->activate($outer);
        $inner = $this->tracer->startSpan('inner', parent: $this->tracer->activeContext());
        $this->tracer->activate($inner);

        self::assertSame($inner->spanId(), $this->tracer->activeContext()?->spanId);

        $this->tracer->deactivate($inner);
        self::assertSame($outer->spanId(), $this->tracer->activeContext()?->spanId);
        self::assertSame($outer->spanId(), $inner->parentSpanId());

        $this->tracer->deactivate($outer);
        self::assertNull($this->tracer->activeContext());
    }

    public function test_middleware_makes_the_server_span_the_parent_of_client_spans(): void
    {
        $transport = new TracingTransport(new FakeTransport(['*' => new HttpResponse(201, 'ok')]), $this->tracer);
        $middleware = new OtelMiddleware($this->tracer);

        $middleware->handle(new Request('GET', '/orders'), function () use ($transport): Response {
            $transport->send('POST', 'https://api.example.com/charge?x=1', [], '{}');

            return new Response('done');
        });

        $spans = $this->spansByName();
        $server = $spans['GET /orders'];
        $client = $spans['HTTP POST'];

        self::assertSame(SpanKind::Client, $client->kind());
        self::assertSame($server->traceId(), $client->traceId());
        self::assertSame($server->spanId(), $client->parentSpanId());
        self::assertSame(201, $client->attributes()['http.status_code']);
        self::assertSame('api.example.com', $client->attributes()['server.address']);
        self::assertNull($this->tracer->activeContext(), 'the middleware deactivates its span');
    }

    public function test_transport_injects_traceparent_of_the_client_span(): void
    {
        $inner = new FakeTransport(['*' => new HttpResponse(200, '')]);
        (new TracingTransport($inner, $this->tracer))->send('GET', 'https://x.test/', ['traceparent' => 'stale'], '');

        $client = $this->spansByName()['HTTP GET'];
        $sent = $inner->getRecorded()[0]['headers'];

        self::assertSame("00-{$client->traceId()}-{$client->spanId()}-01", $sent['traceparent']);
        self::assertArrayNotHasKey('Traceparent', $sent);
    }

    public function test_transport_marks_5xx_as_error(): void
    {
        (new TracingTransport(new FakeTransport(['*' => new HttpResponse(503, '')]), $this->tracer))->send('GET', 'https://x.test/', [], '');
        self::assertSame(SpanStatusCode::Error, $this->spansByName()['HTTP GET']->status());
    }

    public function test_transport_marks_transport_errors_as_error(): void
    {
        $failing = new FakeTransport(['*' => new HttpClientException('refused')]);

        try {
            (new TracingTransport($failing, $this->tracer))->send('GET', 'https://x.test/', [], '');
            self::fail('expected HttpClientException');
        } catch (HttpClientException) {
            self::assertSame(SpanStatusCode::Error, $this->spansByName()['HTTP GET']->status());
        }
    }

    public function test_database_spans_carry_the_statement_and_errors(): void
    {
        $db = new OtelRecordingDatabase();
        $tracing = new TracingDatabase($db, $this->tracer);

        self::assertSame([['n' => 1]], $tracing->query('SELECT 1 AS n'));
        self::assertSame(3, $tracing->execute('UPDATE t SET a = 1'));

        try {
            $tracing->query('SELECT FAIL');
        } catch (\RuntimeException) {
        }

        $spans = $this->exporter->exported();
        self::assertCount(3, $spans);
        self::assertSame('SELECT', $spans[0]->name());
        self::assertSame('SELECT 1 AS n', $spans[0]->attributes()['db.statement']);
        self::assertSame(SpanKind::Client, $spans[0]->kind());
        self::assertSame('UPDATE', $spans[1]->name());
        self::assertSame(3, $spans[1]->attributes()['db.rows_affected']);
        self::assertSame(SpanStatusCode::Error, $spans[2]->status());
        self::assertSame(['SELECT 1 AS n', 'UPDATE t SET a = 1', 'SELECT FAIL'], $db->sql);
    }

    public function test_database_transaction_and_pdo_are_passed_through(): void
    {
        $tracing = new TracingDatabase(new OtelRecordingDatabase(), $this->tracer);

        self::assertSame('ok', $tracing->transaction(static fn (): string => 'ok'));
        self::assertInstanceOf(PDO::class, $tracing->getPdo());
    }
}
