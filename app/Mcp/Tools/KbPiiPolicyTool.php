<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KbPiiSetting;
use App\Services\Kb\Pii\KbPiiPolicyResolver;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * v8.23 (Ciclo 4) — read-only MCP surface (R44 third surface) for the
 * per-(tenant, project) PII ingestion policy.
 *
 * Returns the effective `redact_enabled` + `strategy` the inline ingestion path
 * would apply for the given project (or the tenant-wide `*` scope), after
 * layering config defaults with the tenant-wide and exact-project overrides.
 * Tenant-scoped (R30). Read-only — mutating the policy is the HTTP `PUT
 * /api/admin/pii/policy` surface, gated by `manageKbPiiPolicy`.
 */
#[Description('Show this tenant\'s effective PII ingestion policy (whether ingestion redacts, and with which strategy: mask one-way / tokenise reversible) for a given project, after layering config defaults + tenant-wide + per-project overrides. Read-only.')]
#[IsReadOnly]
#[IsIdempotent]
class KbPiiPolicyTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()
                ->description('Resolve the policy for this project (optional; defaults to the tenant-wide "*" scope).')
                ->nullable()
                ->default(KbPiiSetting::WILDCARD),
        ];
    }

    public function handle(Request $request, KbPiiPolicyResolver $resolver, TenantContext $tenants): Response
    {
        $raw = $request->get('project_key');
        $projectKey = is_string($raw) && trim($raw) !== '' ? trim($raw) : KbPiiSetting::WILDCARD;
        $tenantId = $tenants->current();

        return Response::json([
            'tenant_id' => $tenantId,
            'project_key' => $projectKey,
            'strategies' => KbPiiSetting::STRATEGIES,
            'effective' => $resolver->resolve($tenantId, $projectKey),
        ]);
    }
}
