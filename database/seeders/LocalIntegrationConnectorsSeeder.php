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

    public function run(?string $onlyCompanyKey = null): void
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
            $companyKeys = CaseStudyUsersSeeder::companyKeys();
            if ($onlyCompanyKey !== null && ! in_array($onlyCompanyKey, $companyKeys, true)) {
                throw new RuntimeException("Unknown case-study tenant: {$onlyCompanyKey}.");
            }
            foreach ($onlyCompanyKey === null ? $companyKeys : [$onlyCompanyKey] as $companyKey) {
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
            slug: 'fixture_recent_orders',
            name: 'Ultimi ordini locali',
            description: 'Elenca gli ordini più recenti del tenant. Data = data dell’email citata, non data di creazione dell’ordine. Filtri facoltativi: dal/al (YYYY-MM-DD), nome cliente o fornitore e limite 1–100.',
            url: $baseUrl.'/orders',
            endpointType: 'list',
            itemsPath: 'orders',
            properties: [
                'from_date' => ['type' => 'string', 'description' => 'Data minima YYYY-MM-DD.'],
                'to_date' => ['type' => 'string', 'description' => 'Data massima YYYY-MM-DD.'],
                'name' => ['type' => 'string', 'description' => 'Nome cliente o fornitore.'],
                'limit' => ['type' => 'string', 'description' => 'Numero massimo di ordini (1–100).'],
            ],
            required: [],
            parameters: [
                ['name' => 'from_date', 'location' => 'query', 'required' => false],
                ['name' => 'to_date', 'location' => 'query', 'required' => false],
                ['name' => 'name', 'location' => 'query', 'required' => false],
                ['name' => 'limit', 'location' => 'query', 'required' => false],
            ],
        );
        $this->upsertRoute(
            connector: $connector,
            companyKey: $companyKey,
            slug: 'fixture_list_claims',
            name: 'Reclami locali',
            description: 'Elenca reclami e segnalazioni del tenant, filtrabili per spedizione o ordine.',
            url: $baseUrl.'/claims',
            endpointType: 'list',
            itemsPath: 'claims',
            properties: [
                'shipment_id' => ['type' => 'string', 'description' => 'Identificativo spedizione.'],
                'order_id' => ['type' => 'string', 'description' => 'Identificativo ordine.'],
            ],
            required: [],
            parameters: [
                ['name' => 'shipment_id', 'location' => 'query', 'required' => false],
                ['name' => 'order_id', 'location' => 'query', 'required' => false],
            ],
        );
        $this->upsertRoute(
            connector: $connector,
            companyKey: $companyKey,
            slug: 'fixture_get_claim',
            name: 'Dettaglio reclamo locale',
            description: 'Recupera un reclamo del tenant tramite il suo identificativo.',
            url: $baseUrl.'/claims/{claim_id}',
            endpointType: 'detail',
            itemsPath: null,
            properties: [
                'claim_id' => ['type' => 'string', 'description' => 'Identificativo reclamo.'],
            ],
            required: ['claim_id'],
            parameters: [
                ['name' => 'claim_id', 'location' => 'path', 'required' => true],
            ],
        );
        $this->upsertRoute(
            connector: $connector,
            companyKey: $companyKey,
            slug: 'fixture_list_inventory',
            name: 'Giacenze locali',
            description: 'Elenca disponibilità o arrivi previsti, distinguendo quantità note da quantità non confermate.',
            url: $baseUrl.'/inventory',
            endpointType: 'list',
            itemsPath: 'inventory',
            properties: [
                'product_id' => ['type' => 'string', 'description' => 'Identificativo prodotto.'],
            ],
            required: [],
            parameters: [
                ['name' => 'product_id', 'location' => 'query', 'required' => false],
            ],
        );
        $this->upsertRoute(
            connector: $connector,
            companyKey: $companyKey,
            slug: 'fixture_get_inventory',
            name: 'Dettaglio giacenza locale',
            description: 'Recupera una voce di giacenza del tenant tramite identificativo.',
            url: $baseUrl.'/inventory/{inventory_id}',
            endpointType: 'detail',
            itemsPath: null,
            properties: [
                'inventory_id' => ['type' => 'string', 'description' => 'Identificativo giacenza.'],
            ],
            required: ['inventory_id'],
            parameters: [
                ['name' => 'inventory_id', 'location' => 'path', 'required' => true],
            ],
        );
        $this->pruneLegacyFixtureRoutes($connector, $companyKey);
    }

    private function pruneLegacyFixtureRoutes(ApiConnector $connector, string $companyKey): void
    {
        $legacy = ApiRoute::query()
            ->where('tenant_id', $companyKey)
            ->where('project_key', $companyKey)
            ->where('api_connector_id', $connector->id)
            ->whereIn('slug', [
                'fixture_company_context', 'fixture_list_operational_records', 'fixture_get_operational_record',
            ])
            ->get();
        foreach ($legacy as $route) {
            ApiRouteParameter::query()->where('tenant_id', $companyKey)->where('api_route_id', $route->id)->delete();
            $route->delete();
        }
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
        $existing = ApiRoute::query()
            ->where('tenant_id', $companyKey)
            ->where('project_key', $companyKey)
            ->where('slug', $slug)
            ->first();
        if ($existing instanceof ApiRoute && $existing->api_connector_id !== $connector->id) {
            throw new RuntimeException("Route {$slug} belongs to another connector; refusing to replace it.");
        }
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
