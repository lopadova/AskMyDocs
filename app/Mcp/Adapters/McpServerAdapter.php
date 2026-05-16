<?php

declare(strict_types=1);

namespace App\Mcp\Adapters;

use App\Models\McpServer;
use Illuminate\Support\Facades\Crypt;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerContract;

/**
 * v7.0/W6.3 — adapter from the host's `App\Models\McpServer`
 * Eloquent row to the package's `McpServerContract` shape.
 *
 * The host stores credentials encrypted in `auth_config_encrypted`
 * (Laravel `Crypt`); the package's transport factory expects them
 * decrypted in `transportConfig()`. The adapter decrypts on-demand
 * and merges them with the endpoint into the per-transport keys
 * the package recognises (`endpoint` + `headers` for http / sse,
 * `command` + `args` + `env` for stdio).
 *
 * Decryption failures (key rotation, corrupt blob) degrade
 * gracefully to an empty auth map so a single misconfigured row
 * cannot crash the orchestrator — the upstream MCP server will
 * reject the call instead, which surfaces a clean
 * `McpTransportException` to the audit trail.
 */
final class McpServerAdapter implements McpServerContract
{
    public function __construct(
        private readonly McpServer $server,
    ) {}

    public function id(): string
    {
        // `McpServerContract::id()` is documented as scoped per
        // tenant. The host's primary key is an int; cast to string
        // so the contract's string identity is preserved across the
        // transport layer (e.g. cache keys, audit rows).
        return (string) $this->server->id;
    }

    public function name(): string
    {
        return (string) $this->server->name;
    }

    public function transport(): string
    {
        return (string) $this->server->transport;
    }

    public function tenantId(): ?string
    {
        $tenant = $this->server->tenant_id;
        return is_string($tenant) && $tenant !== '' ? $tenant : null;
    }

    /** @return array<string,mixed> */
    public function transportConfig(): array
    {
        $auth = $this->decryptAuthConfig();

        return match ($this->server->transport) {
            McpServer::TRANSPORT_HTTP, McpServer::TRANSPORT_SSE => [
                'endpoint' => (string) $this->server->endpoint,
                'headers' => $this->extractHeaders($auth),
            ],
            McpServer::TRANSPORT_STDIO => $this->stdioConfig($auth),
            default => [
                'endpoint' => (string) $this->server->endpoint,
            ],
        };
    }

    /** @return array<int,string> */
    public function allowedTools(): array
    {
        $tools = $this->server->enabled_tools_json;
        if (! is_array($tools)) {
            return [];
        }
        // `['*']` is the host's "all tools" sentinel — the package
        // contract treats an empty array the same way, so flatten.
        if ($tools === ['*']) {
            return [];
        }
        return array_values(array_filter(
            array_map(
                static fn($t): string => is_string($t) ? $t : '',
                $tools,
            ),
            static fn(string $t): bool => $t !== '',
        ));
    }

    public function isEnabled(): bool
    {
        return $this->server->status === McpServer::STATUS_ACTIVE;
    }

    /** @return array<string,mixed> */
    private function decryptAuthConfig(): array
    {
        $cipher = $this->server->auth_config_encrypted;
        if (! is_string($cipher) || $cipher === '') {
            return [];
        }
        try {
            $decoded = json_decode(Crypt::decryptString($cipher), true);
        } catch (\Throwable) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $auth
     * @return array<string,string>
     */
    private function extractHeaders(array $auth): array
    {
        $headers = $auth['headers'] ?? [];
        if (! is_array($headers)) {
            return [];
        }
        $out = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && (is_string($value) || is_numeric($value))) {
                $out[$name] = (string) $value;
            }
        }
        // Common pattern: a bare `token` in auth_config maps to a
        // standard `Authorization: Bearer <token>` header. Hosts
        // that need a custom shape can store `headers.Authorization`
        // explicitly instead.
        if (! isset($out['Authorization']) && isset($auth['token']) && is_string($auth['token'])) {
            $out['Authorization'] = 'Bearer ' . $auth['token'];
        }
        return $out;
    }

    /**
     * @param  array<string,mixed>  $auth
     * @return array<string,mixed>
     */
    private function stdioConfig(array $auth): array
    {
        // For stdio servers the host stores the command line in
        // `endpoint` (space-separated) — split into `command` +
        // `args` so the package's `StdioJsonRpcTransport` can
        // `proc_open()` it correctly.
        $parts = preg_split('/\s+/', trim((string) $this->server->endpoint)) ?: [];
        $command = array_shift($parts) ?? '';
        $env = $auth['env'] ?? [];
        if (! is_array($env)) {
            $env = [];
        }
        return [
            'command' => $command,
            'args' => array_values(array_filter($parts, static fn($p): bool => $p !== '')),
            'cwd' => isset($auth['cwd']) && is_string($auth['cwd']) ? $auth['cwd'] : null,
            'env' => $env,
        ];
    }
}
