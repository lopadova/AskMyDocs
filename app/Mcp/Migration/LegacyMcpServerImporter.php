<?php

declare(strict_types=1);

namespace App\Mcp\Migration;

use App\Models\McpServer;
use App\Support\TenantContext as HostTenantContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Padosoft\AskMyDocsConnectorMcp\Services\McpLocalToolName;
use Padosoft\AskMyDocsConnectorMcp\Services\McpToolPolicy;

final readonly class LegacyMcpServerImporter
{
    public function __construct(
        private HostTenantContext $hostTenants,
        private TenantContext $connectorTenants,
        private McpLocalToolName $names,
        private McpToolPolicy $policy,
    ) {}

    /** @return array{servers:int,connections:int,tools:int} */
    public function importTenant(string $tenantId): array
    {
        $this->hostTenants->set($tenantId);
        $this->connectorTenants->set($tenantId);
        $counts = ['servers' => 0, 'connections' => 0, 'tools' => 0];

        McpServer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->each(function (McpServer $legacy) use (&$counts): void {
                $result = $this->importServer($legacy);
                $counts['servers']++;
                $counts['connections'] += $result['connection_created'] ? 1 : 0;
                $counts['tools'] += $result['tools'];
            });

        return $counts;
    }

    /** @return array{server:McpServerDefinition,connection:McpConnection,connection_created:bool,tools:int} */
    public function importServer(McpServer $legacy): array
    {
        $tenantId = (string) $legacy->tenant_id;
        $this->hostTenants->set($tenantId);
        $this->connectorTenants->set($tenantId);

        return DB::transaction(function () use ($legacy, $tenantId): array {
            $handshake = is_array($legacy->handshake_response_json) ? $legacy->handshake_response_json : [];
            $auth = $this->legacyAuth($legacy);
            $headers = $this->legacyHeaders($auth);
            $protocolVersion = is_string($handshake['protocol_version'] ?? null)
                ? $handshake['protocol_version']
                : null;
            $status = $this->status((string) $legacy->status);
            $server = McpServerDefinition::query()->firstOrNew([
                'tenant_id' => $tenantId,
                'legacy_reference' => 'mcp_servers:'.$legacy->getKey(),
            ]);
            $server->fill([
                'name' => (string) $legacy->name,
                'catalog_scope' => 'tenant',
                'owner_type' => null,
                'owner_id' => null,
                'transport' => match ((string) $legacy->transport) {
                    McpServer::TRANSPORT_STDIO => 'stdio_imported',
                    McpServer::TRANSPORT_SSE => 'legacy_sse',
                    default => 'auto',
                },
                'auth_mode' => $headers !== [] ? 'legacy_headers' : 'none',
                'endpoint' => (string) $legacy->endpoint,
                'endpoint_hash' => hash('sha256', (string) $legacy->endpoint),
                'legacy_headers_encrypted' => $headers !== [] ? $headers : null,
                'negotiated_era' => $protocolVersion === null
                    ? null
                    : ($protocolVersion === '2026-07-28' ? 'modern' : 'legacy'),
                'negotiated_version' => $protocolVersion,
                'capabilities_json' => is_array($handshake['capabilities'] ?? null) ? $handshake['capabilities'] : null,
                'server_info_json' => is_array($handshake['server_info'] ?? null) ? $handshake['server_info'] : null,
                'status' => $status,
                'last_discovered_at' => $legacy->last_handshake_at,
                'error_json' => $status === McpServerDefinition::STATUS_ERRORED
                    ? ['phase' => 'legacy_import', 'legacy_handshake' => $handshake]
                    : null,
                'created_by' => (string) $legacy->created_by,
            ]);
            $server->save();

            $connection = McpConnection::query()
                ->where('tenant_id', $tenantId)
                ->where('mcp_connector_server_id', $server->getKey())
                ->where('mode', 'shared')
                ->first();
            $connectionCreated = $connection === null;
            $connection ??= new McpConnection;
            $connection->fill([
                'tenant_id' => $tenantId,
                'mcp_connector_server_id' => $server->getKey(),
                'mode' => 'shared',
                'owner_type' => null,
                'owner_id' => null,
                'label' => (string) $legacy->name,
                'project_key' => null,
                'status' => $status,
                'last_discovered_at' => $legacy->last_handshake_at,
                'error_json' => $status === McpConnection::STATUS_ERRORED
                    ? ['phase' => 'legacy_import', 'legacy_handshake' => $handshake]
                    : null,
            ]);
            $connection->save();

            $tools = $this->reconcileTools($connection, $legacy, $handshake);

            return [
                'server' => $server,
                'connection' => $connection,
                'connection_created' => $connectionCreated,
                'tools' => $tools,
            ];
        });
    }

    public function deleteImported(McpServer $legacy): void
    {
        McpServerDefinition::query()
            ->where('tenant_id', (string) $legacy->tenant_id)
            ->where('legacy_reference', 'mcp_servers:'.$legacy->getKey())
            ->delete();
    }

    /** @param array<string, mixed> $handshake */
    private function reconcileTools(McpConnection $connection, McpServer $legacy, array $handshake): int
    {
        $remoteTools = $this->tools($handshake);
        $enabled = is_array($legacy->enabled_tools_json) ? $legacy->enabled_tools_json : [];
        $allowKnown = $enabled === ['*'];
        $seen = [];

        foreach ($remoteTools as $remoteTool) {
            $remoteName = is_string($remoteTool['name'] ?? null) ? $remoteTool['name'] : '';
            if ($remoteName === '') {
                continue;
            }
            $seen[] = $remoteName;
            $defaults = $this->policy->defaults($remoteTool);
            $explicitlyEnabled = $allowKnown || in_array($remoteName, $enabled, true);
            $tool = McpConnectionTool::query()->firstOrNew([
                'tenant_id' => $connection->tenant_id,
                'mcp_connector_connection_id' => $connection->getKey(),
                'remote_name' => $remoteName,
            ]);
            $tool->fill([
                'local_name' => $tool->local_name ?: $this->names->make($connection, $remoteName),
                'title' => is_string($remoteTool['title'] ?? null) ? $remoteTool['title'] : null,
                'description' => is_string($remoteTool['description'] ?? null) ? $remoteTool['description'] : null,
                'input_schema_json' => is_array($remoteTool['inputSchema'] ?? null)
                    ? $remoteTool['inputSchema']
                    : ['type' => 'object', 'properties' => []],
                'output_schema_json' => is_array($remoteTool['outputSchema'] ?? null) ? $remoteTool['outputSchema'] : null,
                'annotations_json' => is_array($remoteTool['annotations'] ?? null) ? $remoteTool['annotations'] : null,
                'meta_json' => is_array($remoteTool['_meta'] ?? null) ? $remoteTool['_meta'] : null,
                'risk' => $defaults['risk'],
                'policy' => $explicitlyEnabled ? 'enabled' : 'disabled',
                'enabled' => $explicitlyEnabled,
                'read_only' => $defaults['risk'] === 'read',
                'destructive' => $defaults['risk'] === 'destructive',
                'idempotent' => ($remoteTool['annotations']['idempotentHint'] ?? null) === true,
                'confirmation_required' => $explicitlyEnabled && $defaults['risk'] !== 'read',
                'discovered_at' => $legacy->last_handshake_at ?? now(),
                'last_seen_at' => $legacy->last_handshake_at ?? now(),
                'removed_at' => null,
            ]);
            $tool->save();
        }

        McpConnectionTool::query()
            ->where('tenant_id', $connection->tenant_id)
            ->where('mcp_connector_connection_id', $connection->getKey())
            ->when($seen !== [], fn ($query) => $query->whereNotIn('remote_name', $seen))
            ->update(['enabled' => false, 'removed_at' => now()]);

        return count($seen);
    }

    /** @param array<string, mixed> $handshake @return list<array<string, mixed>> */
    private function tools(array $handshake): array
    {
        $candidate = $handshake['tools'] ?? data_get($handshake, 'capabilities.tools') ?? [];
        if (! is_array($candidate)) {
            return [];
        }
        if (array_is_list($candidate)) {
            return array_values(array_filter($candidate, 'is_array'));
        }

        $tools = [];
        foreach ($candidate as $name => $tool) {
            if (is_array($tool)) {
                $tool['name'] ??= (string) $name;
                $tools[] = $tool;
            } elseif (is_string($tool)) {
                $tools[] = ['name' => $tool];
            }
        }

        return $tools;
    }

    /** @return array<string, mixed> */
    private function legacyAuth(McpServer $legacy): array
    {
        $cipher = $legacy->auth_config_encrypted;
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

    /** @param array<string, mixed> $auth @return array<string, string> */
    private function legacyHeaders(array $auth): array
    {
        $headers = [];
        foreach (is_array($auth['headers'] ?? null) ? $auth['headers'] : [] as $name => $value) {
            if (is_string($name) && (is_string($value) || is_numeric($value))) {
                $headers[$name] = (string) $value;
            }
        }
        $token = $auth['token'] ?? null;
        if (is_string($token) && trim($token) !== '' && ! $this->hasAuthorizationHeader($headers)) {
            $headers['Authorization'] = 'Bearer '.trim($token);
        }

        return $headers;
    }

    /** @param array<string, string> $headers */
    private function hasAuthorizationHeader(array $headers): bool
    {
        foreach (array_keys($headers) as $name) {
            if (strcasecmp($name, 'Authorization') === 0) {
                return true;
            }
        }

        return false;
    }

    private function status(string $status): string
    {
        return match ($status) {
            McpServer::STATUS_ACTIVE => McpConnection::STATUS_ACTIVE,
            McpServer::STATUS_DISABLED => McpConnection::STATUS_DISABLED,
            McpServer::STATUS_ERRORED => McpConnection::STATUS_ERRORED,
            default => McpConnection::STATUS_PENDING,
        };
    }
}
