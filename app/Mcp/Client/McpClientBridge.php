<?php

declare(strict_types=1);

namespace App\Mcp\Client;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * v5.0/W1 — HTTP bridge to the Node MCP sidecar.
 *
 * v1 implementation is intentionally minimal: healthcheck + raw invoke.
 * Follow-up W1.2 slices move transport negotiation + retry policy here.
 */
final class McpClientBridge
{
    private function baseUrl(): string
    {
        return rtrim((string) config('mcp.sidecar.base_url', 'http://127.0.0.1:3535'), '/');
    }

    private function timeoutMs(): int
    {
        $value = (int) config('mcp.sidecar.timeout_ms', 2500);
        if ($value <= 0) {
            return 2500;
        }

        return $value;
    }

    private function timeoutSeconds(): float
    {
        return max(0.25, $this->timeoutMs() / 1000);
    }

    public function isHealthy(): bool
    {
        try {
            $healthPath = (string) config('mcp.sidecar.health_endpoint', '/healthz');
            return Http::timeout($this->timeoutSeconds())
                ->get($this->baseUrl() . $healthPath)
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function invokeTool(array $payload): array
    {
        $response = Http::timeout($this->timeoutSeconds())
            ->asJson()
            ->post($this->baseUrl() . '/invoke-tool', $payload);

        return $this->decodeResponse($response);
    }

    public function handshake(array $payload): array
    {
        $response = Http::timeout($this->timeoutSeconds())
            ->asJson()
            ->post($this->baseUrl() . '/handshake', $payload);

        return $this->decodeResponse($response);
    }

    private function decodeResponse(Response $response): array
    {
        if ($response->failed()) {
            throw new \RuntimeException('MCP sidecar request failed: ' . $response->body());
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new \RuntimeException('MCP sidecar returned invalid JSON payload.');
        }

        return $payload;
    }
}
