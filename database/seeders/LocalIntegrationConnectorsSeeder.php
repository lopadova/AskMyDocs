<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Services\Dev\LocalIntegrationFixtureEnvironment;
use App\Support\TenantContext as HostTenantContext;
use Illuminate\Database\Seeder;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Services\McpConnectionManager;
use Padosoft\AskMyDocsConnectorMcp\Services\McpDiscoveryService;
use RuntimeException;

/**
 * Configures one API connector and one scoped MCP connector for every local
 * case-study tenant. It is intentionally a seeder rather than a production
 * migration: endpoints are fixed loopback development fixtures and must never
 * become part of a deployed tenant.
 */
final class LocalIntegrationConnectorsSeeder extends Seeder
{
    private const API_CONNECTOR_NAME = 'Fixture operativo locale';

    private const MCP_LABEL = 'Fixture MCP operativo locale';

    public function run(): void
    {
        if (! LocalIntegrationFixtureEnvironment::enabled()) {
            throw new RuntimeException(
                'Set LOCAL_INTEGRATION_FIXTURES_ENABLED=true through the local fixture command before seeding local connectors.',
            );
        }

        $hostTenants = app(HostTenantContext::class);
        $connectorTenants = app(ConnectorTenantContext::class);
        $manager = app(McpConnectionManager::class);
        $discovery = app(McpDiscoveryService::class);
        $previousHostTenant = $hostTenants->current();
        $previousConnectorTenant = $connectorTenants->current();

        try {
            foreach (CaseStudyUsersSeeder::companyKeys() as $companyKey) {
                $hostTenants->set($companyKey);
                $connectorTenants->set($companyKey);

                $this->seedApiConnector($companyKey);
                $connection = $this->findOrCreateMcpConnection($companyKey, $manager);
                $discovery->discover($connection);
            }
        } finally {
            $hostTenants->set($previousHostTenant);
            $connectorTenants->set($previousConnectorTenant);
        }
    }

    private function seedApiConnector(string $companyKey): void
    {
        $connector = ApiConnector::query()->updateOrCreate(
            [
                'tenant_id' => $companyKey,
                'project_key' => $companyKey,
                'name' => self::API_CONNECTOR_NAME,
            ],
            [
                'description' => 'Dati operativi statici locali correlati a e-mail e documenti del case study.',
                'base_url' => LocalIntegrationFixtureEnvironment::apiBaseUrl(),
                'headers' => [],
                'is_active' => true,
            ],
        );

        $baseUrl = LocalIntegrationFixtureEnvironment::apiBaseUrl().'/v1/companies/'.rawurlencode($companyKey);

        $this->upsertRoute(
            connector: $connector,
            companyKey: $companyKey,
            slug: 'fixture_company_context',
            name: 'Contesto aziendale locale',
            description: 'Recupera identità, caselle e documenti correlati della sola azienda corrente.',
            url: $baseUrl.'/context',
            endpointType: 'detail',
            itemsPath: null,
            properties: [],
            required: [],
            parameters: [],
        );
        $this->upsertRoute(
            connector: $connector,
            companyKey: $companyKey,
            slug: 'fixture_list_operational_records',
            name: 'Elenco dati operativi locali',
            description: 'Elenca i dati operativi statici correlati alla sola azienda corrente; può filtrare per tipo o stato.',
            url: $baseUrl.'/records',
            endpointType: 'list',
            itemsPath: 'records',
            properties: [
                'kind' => ['type' => 'string', 'description' => 'Tipo di record operativo, se noto.'],
                'status' => ['type' => 'string', 'description' => 'Stato operativo, se noto.'],
            ],
            required: [],
            parameters: [
                ['name' => 'kind', 'location' => 'query', 'required' => false],
                ['name' => 'status', 'location' => 'query', 'required' => false],
            ],
        );
        $this->upsertRoute(
            connector: $connector,
            companyKey: $companyKey,
            slug: 'fixture_get_operational_record',
            name: 'Dettaglio dato operativo locale',
            description: 'Recupera il dettaglio di un record operativo noto della sola azienda corrente.',
            url: $baseUrl.'/records/{record_id}',
            endpointType: 'detail',
            itemsPath: null,
            properties: [
                'record_id' => ['type' => 'string', 'description' => 'Identificativo del record operativo.'],
            ],
            required: ['record_id'],
            parameters: [
                ['name' => 'record_id', 'location' => 'path', 'required' => true],
            ],
        );
    }

    /**
     * @param  array<string,array<string,string>>  $properties
     * @param  list<string>  $required
     * @param  list<array{name:string,location:string,required:bool}>  $parameters
     */
    private function upsertRoute(
        ApiConnector $connector,
        string $companyKey,
        string $slug,
        string $name,
        string $description,
        string $url,
        string $endpointType,
        ?string $itemsPath,
        array $properties,
        array $required,
        array $parameters,
    ): void {
        $schema = [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
        $route = ApiRoute::query()->updateOrCreate(
            ['tenant_id' => $companyKey, 'project_key' => $companyKey, 'slug' => $slug],
            [
                'api_connector_id' => $connector->id,
                'name' => $name,
                'description' => $description,
                'http_method' => 'GET',
                'url' => $url,
                'input_schema' => $schema,
                'tool_definition' => [
                    'name' => $slug,
                    'description' => $description,
                    'input_schema' => $schema,
                ],
                'mode' => 'tool',
                'status' => 'active',
                'endpoint_type' => $endpointType,
                'endpoint_type_locked' => true,
                'items_path' => $itemsPath,
            ],
        );

        foreach ($parameters as $order => $parameter) {
            ApiRouteParameter::query()->updateOrCreate(
                ['tenant_id' => $companyKey, 'api_route_id' => $route->id, 'name' => $parameter['name']],
                [
                    'location' => $parameter['location'],
                    'source' => 'llm',
                    'type' => 'string',
                    'required' => $parameter['required'],
                    'value' => null,
                    'description' => (string) ($properties[$parameter['name']]['description'] ?? $parameter['name']),
                    'sort_order' => $order,
                ],
            );
        }
    }

    private function findOrCreateMcpConnection(string $companyKey, McpConnectionManager $manager): McpConnection
    {
        $connection = McpConnection::query()
            ->with('server')
            ->where('tenant_id', $companyKey)
            ->where('mode', 'shared')
            ->where('project_key', $companyKey)
            ->where('label', self::MCP_LABEL)
            ->first();

        if ($connection instanceof McpConnection) {
            return $manager->update($connection, [
                'name' => self::MCP_LABEL,
                'label' => self::MCP_LABEL,
                'project_key' => $companyKey,
                'endpoint' => LocalIntegrationFixtureEnvironment::mcpEndpointFor($companyKey),
                'transport' => 'streamable_http',
            ]);
        }

        $actorId = User::query()
            ->where('email', CaseStudyUsersSeeder::superAdminEmailFor($companyKey))
            ->value('id');
        if (! is_numeric($actorId)) {
            throw new RuntimeException("Case-study super-admin missing for {$companyKey}.");
        }

        return $manager->createShared([
            'name' => self::MCP_LABEL,
            'label' => self::MCP_LABEL,
            'project_key' => $companyKey,
            'endpoint' => LocalIntegrationFixtureEnvironment::mcpEndpointFor($companyKey),
            'transport' => 'streamable_http',
            'auth_mode' => 'none',
        ], (string) $actorId);
    }
}
