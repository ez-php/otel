<?php

declare(strict_types=1);

namespace EzPhp\Otel;

/**
 * OTLP span status code, matching `opentelemetry.proto.trace.v1.Status.StatusCode`.
 *
 * @package EzPhp\Otel
 */
enum SpanStatusCode: int
{
    case Unset = 0;
    case Ok = 1;
    case Error = 2;
}
