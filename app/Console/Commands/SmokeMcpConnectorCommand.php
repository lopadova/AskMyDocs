<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\TenantContext as HostTenantContext;
use Illuminate\Console\Command;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Services\McpConnectionServerAdapter;
use Padosoft\AskMyDocsConnectorMcp\Services\McpCredentialVault;
use Padosoft\AskMyDocsConnectorMcp\Services\McpEndpointSecurityGuard;
use Padosoft\AskMyDocsConnectorMcp\Services\McpOAuthService;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;

final class SmokeMcpConnectorCommand extends Command
{
    protected $signature = 'mcp-connectors:smoke {--connection= : Connection public ULID} {--tool= : Optional read-only remote tool name} {--json : Emit machine-readable JSON}';

    protected $description = 'Run a redacted protocol/catalog smoke test against a configured MCP connection.';

    public function handle(
        HostTenantContext $hostTenants,
        TenantContext $connectorTenants,
        McpCredentialVault $vault,
        McpEndpointSecurityGuard $guard,
        McpOAuthService $oauth,
    ): int {
        $publicId = trim((string) $this->option('connection'));
        if ($publicId === '') {
            $this->components->error('The --connection ULID is required.');

            return self::INVALID;
        }
        $connection = McpConnection::withoutGlobalScopes()->with('server')->where('public_id', $publicId)->first();
        if (! $connection instanceof McpConnection) {
            $this->components->error('The MCP connection was not found.');

            return self::FAILURE;
        }
        $hostTenants->set($connection->tenant_id);
        $connectorTenants->set($connection->tenant_id);
        if ($connection->server->transport === 'stdio_imported') {
            $this->components->error('Imported stdio connections cannot be probed remotely.');

            return self::FAILURE;
        }

        try {
            if ($connection->server->auth_mode === 'oauth') {
                $oauth->refreshIfNeeded($connection);
            }
            $client = McpClient::forServer(new McpConnectionServerAdapter($connection, $vault, $guard));
            $negotiated = $client->negotiate();
            $tools = $this->drain(fn (?string $cursor) => $client->listToolsPage($cursor));
            $resources = array_key_exists('resources', $negotiated->capabilities)
                ? $this->drain(fn (?string $cursor) => $client->listResourcesPage($cursor))
                : [];
            $payload = [
                'ok' => true,
                'connection_id' => $connection->public_id,
                'tenant_id' => $connection->tenant_id,
                'era' => $negotiated->era->value,
                'protocol_version' => $negotiated->protocolVersion,
                'capabilities' => array_values(array_keys($negotiated->capabilities)),
                'tools' => [
                    'count' => count($tools),
                    'catalog_hash' => $this->catalogHash($tools),
                ],
                'resources' => [
                    'count' => count($resources),
                    'catalog_hash' => $this->catalogHash($resources),
                ],
            ];
            $remoteTool = trim((string) $this->option('tool'));
            if ($remoteTool !== '') {
                $tool = McpConnectionTool::query()
                    ->where('tenant_id', $connection->tenant_id)
                    ->where('mcp_connector_connection_id', $connection->getKey())
                    ->where('remote_name', $remoteTool)
                    ->where('enabled', true)
                    ->where('read_only', true)
                    ->whereNull('removed_at')
                    ->first();
                if (! $tool instanceof McpConnectionTool) {
                    throw new \RuntimeException('Smoke calls are restricted to enabled read-only tools in the discovered catalog.');
                }
                $result = $client->callToolResult($remoteTool, []);
                $payload['tool_call'] = [
                    'name' => $remoteTool,
                    'is_error' => $result->isError,
                    'content_types' => array_values(array_unique(array_map(
                        static fn (array $block): string => (string) ($block['type'] ?? 'unknown'),
                        $result->content,
                    ))),
                    'result_hash' => hash('sha256', (string) json_encode($result->toArray())),
                ];
            }

            $this->render($payload);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $payload = [
                'ok' => false,
                'connection_id' => $connection->public_id,
                'tenant_id' => $connection->tenant_id,
                'error_class' => $e::class,
                'message' => $this->redactError($e->getMessage()),
            ];
            $this->render($payload);

            return self::FAILURE;
        }
    }

    /** @return list<array<string,mixed>> */
    private function drain(callable $page): array
    {
        $items = [];
        $cursor = null;
        $maxPages = max(1, (int) config('connector-mcp.http.max_catalog_pages', 20));
        for ($number = 0; $number < $maxPages; $number++) {
            $result = $page($cursor);
            array_push($items, ...$result->items);
            $cursor = $result->nextCursor;
            if ($cursor === null) {
                return $items;
            }
        }

        throw new \RuntimeException('MCP catalog exceeded the configured page limit.');
    }

    /** @param list<array<string,mixed>> $catalog */
    private function catalogHash(array $catalog): string
    {
        $normalized = array_map(static fn (array $item): array => [
            'name' => $item['name'] ?? null,
            'uri' => $item['uri'] ?? null,
            'inputSchema' => $item['inputSchema'] ?? null,
        ], $catalog);

        return hash('sha256', (string) json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function redactError(string $message): string
    {
        $message = preg_replace('/(Bearer\s+)[^\s]+/i', '$1[redacted]', $message) ?? 'MCP smoke test failed.';
        $message = preg_replace('/(token|secret|password|code)\s*[=:]\s*[^\s,;&]+/i', '$1=[redacted]', $message) ?? 'MCP smoke test failed.';

        return mb_substr($message, 0, 1024);
    }

    /** @param array<string,mixed> $payload */
    private function render(array $payload): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return;
        }

        foreach ($payload as $key => $value) {
            $this->line($key.': '.(is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES)));
        }
    }
}
