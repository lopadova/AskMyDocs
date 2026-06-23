<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\AppSetting;
use App\Services\Admin\AppSettingsResolver;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * v8.22 (Ciclo 3) — MCP read surface (R44) for runtime configuration governance.
 *
 * The third surface over the same {@see AppSettingsResolver} core as the HTTP
 * `admin/app-settings` endpoint and the `app-settings:list` command: every
 * governable key with its effective value + provenance (config / tenant / project),
 * tenant-scoped (R30). Read-only — runtime mutation is intentionally NOT exposed
 * over MCP (governance writes go through the authenticated super-admin HTTP/CLI
 * surfaces, never an LLM tool).
 */
#[Description('List this tenant\'s governable runtime settings (e.g. AI provider, connector sync cadence) with each one\'s effective value and where it comes from (config default / tenant override / project override). Optionally scope to a project_key. Read-only.')]
#[IsReadOnly]
#[IsIdempotent]
class AppSettingsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()
                ->description('Resolve overrides for this project (optional; defaults to the tenant-wide "*" scope).')
                ->nullable()
                ->default(AppSetting::WILDCARD),
        ];
    }

    public function handle(Request $request, AppSettingsResolver $resolver, TenantContext $tenants): Response
    {
        $projectKey = AppSetting::normalizeProjectKey($request->get('project_key'));

        return Response::json([
            'tenant_id' => $tenants->current(),
            'project_key' => $projectKey,
            'settings' => $resolver->all($tenants->current(), $projectKey),
        ]);
    }
}
