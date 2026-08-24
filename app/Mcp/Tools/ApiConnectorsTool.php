<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService;
use Padosoft\AskMyDocsConnectorApi\Support\RouteConfig;

/**
 * v8.27 — MCP read surface (R44) for the API connectors (Connettore API).
 *
 * The third surface over the SAME core as the HTTP
 * `GET /api/admin/api-connectors` index and the `api-connector:list` Artisan
 * command — all three delegate to {@see ConnectorAdminService::listConnectors()}.
 * Strictly tenant-scoped (R30): an agent only ever sees its own tenant's API
 * connectors. Read-only; never exposes auth credentials (secrets stay in the
 * encrypted profile). Degrades cleanly to an empty roster (R43).
 */
#[Description('List this tenant\'s API connectors (Connettore API) and their routes. Each connector reports its routes — tool slug, HTTP method, endpoint type (list/detail/unknown), status (draft/tested/active/disabled), mode (tool/ingest/both) and last test status — plus its list→detail relations (which list feeds which detail, and the field map). Active+tool routes are the live LLM tools available in chat; a relation means an item from the list tool can be drilled into the detail tool. Read-only; no secrets are returned.')]
#[IsReadOnly]
#[IsIdempotent]
class ApiConnectorsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'only_active' => $schema->boolean()
                ->description('When true, list only routes that are live tools (status=active, mode=tool|both). Default false (all routes).')
                ->default(false),
            'with_config' => $schema->boolean()
                ->description('When true, include each route\'s canonical config JSON (identity/request/response/options) — the same object the admin editor uses. Read-only; no secrets (secret params carry only their key name). Default false.')
                ->default(false),
        ];
    }

    public function handle(Request $request, ConnectorAdminService $service, TenantContext $tenants): Response
    {
        $onlyActive = (bool) ($request->get('only_active') ?? false);
        $withConfig = (bool) ($request->get('with_config') ?? false);

        $connectors = [];
        $liveTools = 0;

        foreach ($service->listConnectors() as $connector) {
            $routes = [];

            foreach ($connector->routes as $route) {
                $isLive = $route->status->value === 'active'
                    && in_array($route->mode->value, ['tool', 'both'], true);

                if ($isLive) {
                    $liveTools++;
                }

                if ($onlyActive && ! $isLive) {
                    continue;
                }

                $row = [
                    'slug' => $route->slug,
                    'name' => $route->name,
                    'http_method' => $route->http_method->value,
                    'endpoint_type' => $route->endpoint_type->value,
                    'status' => $route->status->value,
                    'mode' => $route->mode->value,
                    'last_test_status' => $route->last_test_status,
                    'is_live_tool' => $isLive,
                ];

                if ($withConfig) {
                    $route->loadMissing('parameters');
                    $row['config'] = RouteConfig::fromRoute($route);
                }

                $routes[] = $row;
            }

            $relations = [];
            foreach ($connector->relations as $relation) {
                $relations[] = [
                    'list' => $relation->listRoute?->slug,
                    'detail' => $relation->detailRoute?->slug,
                    'maps' => array_map(
                        static fn (array $m): array => [
                            'from' => $m['from'] ?? null,
                            'to_param' => $m['to_param'] ?? null,
                        ],
                        $relation->field_map,
                    ),
                    'description' => $relation->description,
                ];
            }

            $connectors[] = [
                'id' => $connector->id,
                'name' => $connector->name,
                'project_key' => $connector->project_key,
                'is_active' => (bool) $connector->is_active,
                'routes' => $routes,
                'relations' => $relations,
            ];
        }

        return Response::json([
            'tenant_id' => $tenants->current(),
            'connectors' => $connectors,
            'live_tool_count' => $liveTools,
        ]);
    }
}
