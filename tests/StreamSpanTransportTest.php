<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Otel\Exporter\StreamSpanTransport;

final class StreamSpanTransportTest extends TestCase
{
    public function testReturnsFalseWhenEndpointIsUnreachable(): void
    {
        $transport = new StreamSpanTransport(1);

        self::assertFalse($transport->send('http://127.0.0.1:1/v1/traces', '{}', ['Content-Type' => 'application/json']));
    }
}
