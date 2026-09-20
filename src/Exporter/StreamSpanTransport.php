<?php

declare(strict_types=1);

namespace EzPhp\Otel\Exporter;

/**
 * Default {@see SpanTransportInterface} implementation using PHP's stream
 * wrapper HTTP client — stdlib only, no `ext-curl` requirement, in keeping
 * with "no vendor SDK".
 *
 * @package EzPhp\Otel\Exporter
 */
final class StreamSpanTransport implements SpanTransportInterface
{
    /**
     * @param int $timeoutSeconds
     */
    public function __construct(
        private readonly int $timeoutSeconds = 5,
    ) {
    }

    /**
     * @param string                $url
     * @param string                $payload
     * @param array<string, string> $headers
     *
     * @return bool
     */
    public function send(string $url, string $payload, array $headers): bool
    {
        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $payload,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            return false;
        }

        $statusLine = http_get_last_response_headers()[0] ?? '';

        return preg_match('#^HTTP/\S+\s+2\d\d#', $statusLine) === 1;
    }
}
