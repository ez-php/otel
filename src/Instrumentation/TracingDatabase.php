<?php

declare(strict_types=1);

namespace EzPhp\Otel\Instrumentation;

use EzPhp\Contracts\DatabaseInterface;
use EzPhp\Otel\Span;
use EzPhp\Otel\SpanKind;
use EzPhp\Otel\SpanStatusCode;
use EzPhp\Otel\Tracer;
use PDO;

/**
 * Class TracingDatabase
 *
 * DatabaseInterface decorator (like ez-php/orm's LoggingDatabase): one CLIENT span
 * per query()/execute(), named after the SQL verb, child of the tracer's active
 * span, with the statement (never the bindings) as `db.statement`. A thrown
 * exception marks the span as an error and is rethrown. transaction() and
 * getPdo() pass through — statements run inside a transaction are still traced
 * when they go through this decorator.
 *
 * @package EzPhp\Otel\Instrumentation
 */
final readonly class TracingDatabase implements DatabaseInterface
{
    /**
     * @param DatabaseInterface $db
     * @param Tracer            $tracer
     */
    public function __construct(
        private DatabaseInterface $db,
        private Tracer $tracer,
    ) {
    }

    /**
     * @param string                   $sql
     * @param array<int|string, mixed> $bindings
     *
     * @return list<array<string, mixed>>
     */
    public function query(string $sql, array $bindings = []): array
    {
        return $this->traced($sql, fn (): array => $this->db->query($sql, $bindings));
    }

    /**
     * @param string                   $sql
     * @param array<int|string, mixed> $bindings
     *
     * @return int
     */
    public function execute(string $sql, array $bindings = []): int
    {
        return $this->traced($sql, function (Span $span) use ($sql, $bindings): int {
            $affected = $this->db->execute($sql, $bindings);
            $span->setAttribute('db.rows_affected', $affected);

            return $affected;
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        return $this->db->transaction($fn);
    }

    /**
     * @return PDO
     */
    public function getPdo(): PDO
    {
        return $this->db->getPdo();
    }

    /**
     * @template T
     *
     * @param string              $sql
     * @param callable(Span): T   $run
     *
     * @return T
     */
    private function traced(string $sql, callable $run): mixed
    {
        $verb = strtoupper((string) strtok(ltrim($sql), " \n\t("));
        $span = $this->tracer->startSpan($verb !== '' ? $verb : 'SQL', SpanKind::Client, $this->tracer->activeContext());
        $span->setAttribute('db.statement', $sql);
        $status = SpanStatusCode::Ok;

        try {
            return $run($span);
        } catch (\Throwable $e) {
            $status = SpanStatusCode::Error;

            throw $e;
        } finally {
            $this->tracer->endSpan($span, $status);
        }
    }
}
