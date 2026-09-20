<?php

declare(strict_types=1);

namespace EzPhp\Otel;

/**
 * OTLP span kind, matching the `SpanKind` enum values in the OpenTelemetry
 * trace proto (`opentelemetry.proto.trace.v1.Span.SpanKind`).
 *
 * @package EzPhp\Otel
 */
enum SpanKind: int
{
    case Internal = 1;
    case Server = 2;
    case Client = 3;
    case Producer = 4;
    case Consumer = 5;
}
