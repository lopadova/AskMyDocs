<?php

declare(strict_types=1);

namespace Tests\Support\Mcp;

use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;

/**
 * v7.0/W6.3 — host-local stub of the package's MCP transport.
 *
 * The package ships its own `StubMcpTransport` under
 * `vendor/.../tests/Support/`, but `composer require` does NOT
 * autoload the package's `tests/` namespace into the host's
 * autoloader. We carry a small in-repo copy so the host's
 * round-trip integration test can script canned JSON-RPC responses
 * without a real upstream MCP server.
 *
 * Same shape as the package version — scripts canned responses per
 * (method, params['name']?) key.
 */
final class StubMcpTransport implements McpTransportContract
{
    /** @var array<string,mixed> */
    public array $responses = [];

    public bool $healthy = true;

    /** @var list<JsonRpcMessage> */
    public array $sentRequests = [];

    public function request(JsonRpcMessage $request): JsonRpcMessage
    {
        $this->sentRequests[] = $request;
        $key = $this->keyFor($request);

        if (! array_key_exists($key, $this->responses)) {
            return JsonRpcMessage::errorResponse($request->id, -32601, "No stub for [{$key}]");
        }

        $payload = $this->responses[$key];
        if ($payload instanceof JsonRpcMessage) {
            return $payload;
        }

        return JsonRpcMessage::response($request->id, $payload);
    }

    public function notify(JsonRpcMessage $notification): void
    {
        $this->sentRequests[] = $notification;
    }

    public function isHealthy(): bool
    {
        return $this->healthy;
    }

    private function keyFor(JsonRpcMessage $request): string
    {
        if ($request->method === 'tools/call') {
            // Guard against a non-array `params` payload — JSON-RPC
            // permits an array or omitted params, so indexing
            // `$request->params['name']` directly would crash on a
            // malformed test call. Fall back to the bare key.
            $name = is_array($request->params) ? ($request->params['name'] ?? '') : '';
            return 'tools/call:' . (string) $name;
        }
        return (string) $request->method;
    }

    /** @param  array<string,mixed>  $capabilities */
    public function scriptInitialize(array $capabilities = ['tools' => []]): self
    {
        $this->responses['initialize'] = $capabilities;
        return $this;
    }

    /** @param  array<int,array<string,mixed>>  $tools */
    public function scriptListTools(array $tools): self
    {
        $this->responses['tools/list'] = ['tools' => $tools];
        return $this;
    }

    public function scriptToolCall(string $toolName, mixed $result): self
    {
        $this->responses["tools/call:{$toolName}"] = is_array($result) ? $result : ['content' => $result];
        return $this;
    }
}
