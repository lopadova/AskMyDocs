<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\Admin\AppSettingsResolver;
use App\Services\Dev\LocalIntegrationFixtureEnvironment;
use App\Services\Dev\LocalIntegrationFixtureLifecycle;
use App\Support\TenantContext as HostTenantContext;
use Database\Seeders\CaseStudyUsersSeeder;
use Database\Seeders\LocalIntegrationConnectorsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Services\McpConnectionServerAdapter;
use Padosoft\AskMyDocsConnectorMcp\Services\McpCredentialVault;
use Padosoft\AskMyDocsConnectorMcp\Services\McpEndpointSecurityGuard;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use RuntimeException;

/** Refreshes fixture connectors without resetting tenant content or Gmail. */
final class RefreshLocalIntegrationConnectorsCommand extends Command
{
    protected $signature = 'dev:refresh-local-integration-connectors
        {--tenant= : One of the three existing case-study tenant slugs; defaults to all}';

    protected $description = 'Refresh only the local API/MCP fixture servers and connectors for existing case-study tenants.';

    public function handle(
        LocalIntegrationFixtureEnvironment $environment,
        LocalIntegrationFixtureLifecycle $lifecycle,
        LocalIntegrationConnectorsSeeder $connectors,
        AppSettingsResolver $settings,
        HostTenantContext $hostTenants,
        ConnectorTenantContext $connectorTenants,
        McpCredentialVault $vault,
        McpEndpointSecurityGuard $guard,
    ): int {
        try {
            $environment->assertLocal();
            if (! LocalIntegrationFixtureEnvironment::enabled()) {
                throw new RuntimeException('Local fixture connectors are not enabled. Initialize the case-study fixtures first.');
            }
            $requested = trim((string) $this->option('tenant'));
            $allowed = CaseStudyUsersSeeder::companyKeys();
            if ($requested !== '' && ! in_array($requested, $allowed, true)) {
                throw new RuntimeException('Unknown case-study tenant. Allowed: '.implode(', ', $allowed));
            }
            $targets = $requested === '' ? $allowed : [$requested];
            foreach ($targets as $tenantId) {
                if (! Tenant::query()->where('slug', $tenantId)->exists()) {
                    throw new RuntimeException("Case-study tenant {$tenantId} does not exist. Run the full fixture setup first.");
                }
                if (! User::withoutGlobalScopes()
                    ->where('email', CaseStudyUsersSeeder::superAdminEmailFor($tenantId))->exists()) {
                    throw new RuntimeException("Case-study super-admin for {$tenantId} does not exist. Run the full fixture setup first.");
                }
            }

            $this->components->info('Riavvio i due mock condivisi; non modifico e-mail, documenti, utenti o tenant.');
            $lifecycle->restart();
            config([
                'connector-api.ssrf.enabled' => false,
                'connector-api.ssrf.https_only' => false,
                'connector-mcp.enabled' => true,
                'connector-mcp.http.internal_endpoint_allowlist' => ['127.0.0.1', '::1', 'localhost'],
            ]);
            $previousHost = $hostTenants->current();
            $previousConnector = $connectorTenants->current();
            try {
                foreach ($targets as $tenantId) {
                    $hostTenants->set($tenantId);
                    $connectorTenants->set($tenantId);
                    $this->line("[{$tenantId}] Aggiorno route API e riscopro gli strumenti MCP...");
                    $connectors->run($tenantId);
                    $settings->set('connector.mcp.runtime_mode', 'active', $tenantId, AppSetting::WILDCARD);
                    $this->smokeApi($tenantId);
                    $this->smokeMcp($tenantId, $vault, $guard);
                    $this->components->info("[{$tenantId}] API e MCP attivi; smoke completato.");
                }
            } finally {
                $hostTenants->set($previousHost);
                $connectorTenants->set($previousConnector);
            }
        } catch (\Throwable $exception) {
            report($exception);
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Aggiornamento connettori completato senza reset dei dati dei tenant.');

        return self::SUCCESS;
    }

    private function smokeApi(string $tenantId): void
    {
        $base = LocalIntegrationFixtureEnvironment::apiBaseUrl().'/v1/companies/'.rawurlencode($tenantId);
        foreach (['claims', 'inventory', 'orders'] as $resource) {
            $response = Http::timeout(5)->get($base.'/'.$resource);
            if (! $response->successful() || ! is_array($response->json())
                || ($response->json('datasetRevision') ?? null) !== 'case-study-linked-v2') {
                throw new RuntimeException("[{$tenantId}] API smoke failed for {$resource}.");
            }
        }
        $this->line("[{$tenantId}] API: reclami, giacenze e ordini recenti verificati.");
    }

    private function smokeMcp(string $tenantId, McpCredentialVault $vault, McpEndpointSecurityGuard $guard): void
    {
        $connection = McpConnection::query()
            ->where('tenant_id', $tenantId)
            ->where('project_key', $tenantId)
            ->where('label', 'Fixture MCP operativo locale')
            ->with('server')
            ->first();
        if (! $connection instanceof McpConnection) {
            throw new RuntimeException("[{$tenantId}] Fixture MCP connection is missing.");
        }
        [$tool, $query] = match ($tenantId) {
            'rotta-logistics' => ['search_shipments', 'SPD-51230'],
            'prometeo-antincendio' => ['search_orders', 'ORD-2024-3471'],
            'passolibero-calzature' => ['search_orders', 'FRN-2024-241'],
        };
        $client = McpClient::forServer(new McpConnectionServerAdapter($connection, $vault, $guard));
        $result = $client->callToolResult($tool, ['query' => $query]);
        $structured = $result->structuredContent;
        if ($result->isError || ! is_array($structured)
            || ($structured['datasetRevision'] ?? null) !== 'case-study-linked-v2'
            || ($structured['companyKey'] ?? null) !== $tenantId
            || empty($structured['records'])) {
            throw new RuntimeException("[{$tenantId}] MCP smoke failed for {$tool}.");
        }
        $this->line("[{$tenantId}] MCP: {$tool} verificato su {$query}.");
    }
}
