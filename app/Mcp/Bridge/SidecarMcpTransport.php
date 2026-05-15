<?php

declare(strict_types=1);

namespace App\Mcp\Bridge;

use App\Models\McpServer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Exceptions\McpTransportException;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;

/**
 * v7.0/W1.B — custom transport that translates the package's JSON-RPC
 * protocol into the AskMyDocs Node sidecar's existing REST endpoints
 * (`/handshake`, `/invoke-tool`).
 *
 * The package's stock {@see \Padosoft\AskMyDocsMcpPack\Transports\HttpJsonRpcTransport}
 * would POST a JSON-RPC envelope at `/rpc`, which the in-repo sidecar
 * does not expose; this adapter keeps the sidecar untouched.
 *
 * Translation rules:
 *
 *   - `initialize` → POST `/handshake` (the sidecar returns
 *      `{capabilities, tools, ...}` in one shot). The full payload is
 *      cached on the transport instance so the immediately-following
 *      `tools/list` call does not double-round-trip.
 *   - `tools/list` → returns the cached `tools` array if `initialize`
 *      already fired this session; otherwise hits `/handshake`.
 *   - `tools/call` → POST `/invoke-tool` with
 *     `{server_id, tool_name, tool_input}`.
 *
 * Auth: every call carries `Authorization: Bearer <MCP_INTERNAL_AUTH_TOKEN>`
 * (the same `mcp.internal_auth_token` config key the sidecar has used
 * since v5.0). When the host's `EloquentMcpServerAdapter` wraps the
 * row, the underlying McpServer is available via `$server->server`,
 * which we use to pass the host-side server id straight through.
 */
final class SidecarMcpTransport implements McpTransportContract
{
    /** @var array<string,mixed>|null */
    private ?array $handshakeCache = null;

    public function __construct(private readonly McpServerContract $server) {}

    public function request(JsonRpcMessage $request): JsonRpcMessage
    {
        if (! $request->isRequest()) {
            throw new \InvalidArgumentException('SidecarMcpTransport::request() requires a JSON-RPC request message.');
        }

        try {
            return match ($request->method) {
                'initialize' => $this->callInitialize($request),
                'tools/list' => $this->callToolsList($request),
                'tools/call' => $this->callToolsCall($request),
                default => JsonRpcMessage::errorResponse(
                    $request->id,
                    -32601,
                    "Method [{$request->method}] not supported by SidecarMcpTransport.",
                ),
            };
        } catch (ConnectionException $e) {
            throw new McpTransportException("Sidecar transport connection failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function notify(JsonRpcMessage $notification): void
    {
        // The Node sidecar has no notification surface. Notifications
        // are a no-op — the orchestrator never relies on the sidecar
        // emitting one. Caller still gets a stable return shape.
    }

    public function isHealthy(): bool
    {
        try {
            $base = $this->baseUrl();
            $healthPath = (string) config('mcp.sidecar.health_endpoint', '/healthz');
            return Http::timeout($this->timeoutSeconds())
                ->get($base . $healthPath)
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function callInitialize(JsonRpcMessage $request): JsonRpcMessage
    {
        $payload = $this->handshakePayload();
        $capabilities = is_array($payload['capabilities'] ?? null)
            ? $payload['capabilities']
            : ['tools' => new \stdClass()];

        return JsonRpcMessage::response($request->id, $capabilities);
    }

    private function callToolsList(JsonRpcMessage $request): JsonRpcMessage
    {
        $payload = $this->handshakePayload();
        $tools = is_array($payload['tools'] ?? null) ? $payload['tools'] : [];

        // The sidecar can emit `tools` either as a list or under
        // `capabilities.tools`; normalise into a flat list of objects
        // with at least a `name` key.
        if (! array_is_list($tools)) {
            $tools = is_array($payload['capabilities']['tools'] ?? null)
                ? array_values($payload['capabilities']['tools'])
                : [];
        }

        return JsonRpcMessage::response($request->id, ['tools' => $tools]);
    }

    private function callToolsCall(JsonRpcMessage $request): JsonRpcMessage
    {
        $params = $request->params ?? [];
        $toolName = (string) ($params['name'] ?? '');
        $toolInput = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $response = Http::timeout($this->timeoutSeconds())
            ->withHeaders($this->headers())
            ->asJson()
            ->post($this->baseUrl() . '/invoke-tool', [
                'server_id' => $this->underlyingServerId(),
                'server_name' => $this->server->name(),
                'tool_name' => $toolName,
                'input' => $toolInput,
            ]);

        if ($response->failed()) {
            return JsonRpcMessage::errorResponse(
                $request->id,
                -32000,
                "Sidecar /invoke-tool returned status {$response->status()}: {$response->body()}",
            );
        }

        $body = $response->json();
        return JsonRpcMessage::response($request->id, is_array($body) ? $body : []);
    }

    /** @return array<string,mixed> */
    private function handshakePayload(): array
    {
        if ($this->handshakeCache !== null) {
            return $this->handshakeCache;
        }

        $response = Http::timeout($this->timeoutSeconds())
            ->withHeaders($this->headers())
            ->asJson()
            ->post($this->baseUrl() . '/handshake', [
                'server_id' => $this->underlyingServerId(),
                'name' => $this->server->name(),
                'transport' => $this->server->transport(),
                'tenant_id' => $this->server->tenantId(),
            ]);

        if ($response->failed()) {
            throw new McpTransportException(
                "Sidecar /handshake returned status {$response->status()}: {$response->body()}",
            );
        }

        $body = $response->json();
        $this->handshakeCache = is_array($body) ? $body : [];

        return $this->handshakeCache;
    }

    private function underlyingServerId(): int|string
    {
        if ($this->server instanceof EloquentMcpServerAdapter) {
            return $this->server->server->id;
        }

        return $this->server->id();
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('mcp.sidecar.base_url', 'http://127.0.0.1:3535'), '/');
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        $headers = [];
        $token = (string) config('mcp.internal_auth_token', '');
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    private function timeoutSeconds(): float
    {
        $ms = (int) config('mcp.sidecar.timeout_ms', 5_000);
        return max(0.25, $ms / 1000);
    }
}
