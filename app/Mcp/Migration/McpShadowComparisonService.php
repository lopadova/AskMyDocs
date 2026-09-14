<?php

declare(strict_types=1);

namespace App\Mcp\Migration;

use App\Events\McpShadowComparisonCompleted;
use App\Models\McpConnectorShadowReport;
use App\Models\McpServer;
use App\Support\TenantContext as HostTenantContext;
use Illuminate\Support\Facades\Log;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Services\McpDiscoveryService;

final readonly class McpShadowComparisonService
{
    public function __construct(
        private LegacyMcpServerImporter $importer,
        private McpDiscoveryService $discovery,
        private HostTenantContext $hostTenants,
        private TenantContext $connectorTenants,
    ) {}

    public function compare(McpServer $legacy): McpConnectorShadowReport
    {
        $tenantId = (string) $legacy->tenant_id;
        $this->hostTenants->set($tenantId);
        $this->connectorTenants->set($tenantId);
        $imported = $this->importer->importServer($legacy);
        /** @var McpConnection $connection */
        $connection = $imported['connection'];
        $before = $this->connectorTools($connection);
        $legacyTools = $this->legacyTools($legacy);

        if ($legacy->transport === McpServer::TRANSPORT_STDIO) {
            return $this->store(
                $legacy,
                $connection,
                McpConnectorShadowReport::EXPECTED_EXCEPTION,
                $legacyTools,
                $before,
                [],
                [['code' => 'stdio_imported', 'message' => 'Legacy stdio remains administrative-only and is not probed remotely.']],
            );
        }

        try {
            $result = $this->discovery->discover($connection->load('server'));
            $connection = $result['connection'];
            $after = $this->connectorTools($connection);
            [$blockers, $warnings] = $this->diff($legacyTools, $before, $after);
            if (is_string($result['catalog_error'])) {
                $blockers[] = ['code' => 'tools_list_failed', 'message' => $result['catalog_error']];
            }
            if (is_string($result['resource_catalog_error'])) {
                $warnings[] = ['code' => 'resources_list_failed', 'message' => $result['resource_catalog_error']];
            }

            return $this->store(
                $legacy,
                $connection,
                $blockers === [] ? McpConnectorShadowReport::MATCH : McpConnectorShadowReport::DRIFT,
                $legacyTools,
                $after,
                $blockers,
                $warnings,
            );
        } catch (\Throwable $e) {
            return $this->store(
                $legacy,
                $connection,
                McpConnectorShadowReport::ERROR,
                $legacyTools,
                $before,
                [['code' => 'discovery_failed', 'class' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 1024)]],
                [],
            );
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function legacyTools(McpServer $legacy): array
    {
        $handshake = is_array($legacy->handshake_response_json) ? $legacy->handshake_response_json : [];
        $candidate = $handshake['tools'] ?? data_get($handshake, 'capabilities.tools') ?? [];
        if (! is_array($candidate)) {
            return [];
        }
        $enabled = is_array($legacy->enabled_tools_json) ? $legacy->enabled_tools_json : [];
        $wildcard = $enabled === ['*'];
        $tools = [];
        foreach ($candidate as $key => $value) {
            $tool = is_array($value) ? $value : (is_string($value) ? ['name' => $value] : []);
            $name = is_string($tool['name'] ?? null) ? $tool['name'] : (! is_int($key) ? (string) $key : '');
            if ($name === '') {
                continue;
            }
            $schema = $tool['inputSchema'] ?? $tool['input_schema'] ?? $tool['parameters'] ?? ['type' => 'object'];
            $tools[$name] = [
                'name' => $name,
                'enabled' => $wildcard || in_array($name, $enabled, true),
                'schema_hash' => $this->hash(is_array($schema) ? $schema : []),
            ];
        }

        ksort($tools);

        return $tools;
    }

    /** @return array<string,array<string,mixed>> */
    private function connectorTools(McpConnection $connection): array
    {
        $tools = [];
        McpConnectionTool::query()
            ->where('tenant_id', $connection->tenant_id)
            ->where('mcp_connector_connection_id', $connection->getKey())
            ->orderBy('remote_name')
            ->each(function (McpConnectionTool $tool) use (&$tools): void {
                $tools[$tool->remote_name] = [
                    'name' => $tool->remote_name,
                    'enabled' => $tool->enabled,
                    'risk' => $tool->risk,
                    'confirmation_required' => $tool->confirmation_required,
                    'removed' => $tool->removed_at !== null,
                    'schema_hash' => $this->hash($tool->input_schema_json),
                ];
            });

        return $tools;
    }

    /**
     * @param array<string,array<string,mixed>> $legacy
     * @param array<string,array<string,mixed>> $before
     * @param array<string,array<string,mixed>> $after
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private function diff(array $legacy, array $before, array $after): array
    {
        $blockers = [];
        $warnings = [];
        foreach ($legacy as $name => $tool) {
            if (! ($tool['enabled'] ?? false)) {
                continue;
            }
            $current = $after[$name] ?? null;
            if ($current === null || ($current['removed'] ?? false)) {
                $blockers[] = ['code' => 'enabled_tool_missing', 'tool' => $name];
                continue;
            }
            if (! ($current['enabled'] ?? false)) {
                $blockers[] = ['code' => 'enabled_tool_disabled', 'tool' => $name, 'risk' => $current['risk'] ?? null];
            }
            if (($tool['schema_hash'] ?? null) !== ($current['schema_hash'] ?? null)) {
                $blockers[] = ['code' => 'enabled_tool_schema_changed', 'tool' => $name];
            }
            $previousRisk = $before[$name]['risk'] ?? null;
            if ($previousRisk !== null && $previousRisk !== ($current['risk'] ?? null)) {
                $blockers[] = [
                    'code' => 'enabled_tool_risk_changed',
                    'tool' => $name,
                    'from' => $previousRisk,
                    'to' => $current['risk'] ?? null,
                ];
            }
        }
        foreach (array_diff(array_keys($after), array_keys($legacy)) as $name) {
            $warnings[] = ['code' => 'new_remote_tool', 'tool' => $name];
        }

        return [$blockers, $warnings];
    }

    /**
     * @param array<string,array<string,mixed>> $legacyTools
     * @param array<string,array<string,mixed>> $connectorTools
     * @param list<array<string,mixed>> $blockers
     * @param list<array<string,mixed>> $warnings
     */
    private function store(
        McpServer $legacy,
        McpConnection $connection,
        string $status,
        array $legacyTools,
        array $connectorTools,
        array $blockers,
        array $warnings,
    ): McpConnectorShadowReport {
        $report = McpConnectorShadowReport::query()->create([
            'tenant_id' => $legacy->tenant_id,
            'mcp_server_id' => $legacy->getKey(),
            'mcp_connector_connection_id' => $connection->getKey(),
            'status' => $status,
            'legacy_catalog_hash' => $this->hash($legacyTools),
            'connector_catalog_hash' => $this->hash($connectorTools),
            'summary_json' => [
                'legacy_tools' => count($legacyTools),
                'legacy_enabled_tools' => count(array_filter($legacyTools, static fn (array $tool): bool => (bool) ($tool['enabled'] ?? false))),
                'connector_tools' => count($connectorTools),
                'blockers' => count($blockers),
                'warnings' => count($warnings),
                'negotiated_era' => $connection->server->negotiated_era,
                'negotiated_version' => $connection->server->negotiated_version,
            ],
            'blockers_json' => $blockers === [] ? null : $blockers,
            'warnings_json' => $warnings === [] ? null : $warnings,
            'compared_at' => now(),
        ]);

        Log::info('MCP shadow comparison completed.', [
            'tenant_id' => $report->tenant_id,
            'legacy_server_id' => $report->mcp_server_id,
            'connection_id' => $connection->public_id,
            'status' => $status,
            'blockers' => count($blockers),
            'warnings' => count($warnings),
        ]);
        McpShadowComparisonCompleted::dispatch($report);

        return $report;
    }

    private function hash(array $value): string
    {
        $value = $this->sortRecursive($value);

        return hash('sha256', (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
