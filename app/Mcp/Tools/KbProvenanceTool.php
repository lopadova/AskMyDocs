<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Admin\ProvenanceInsightsService;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * v8.32 / ADR 0028 phase 1 — MCP read surface (R44) for ingest provenance.
 *
 * The third surface over the same core as `GET /api/admin/kb/provenance` and
 * the `kb:provenance` command: how much of this tenant's corpus was authored
 * outside the organisation, overall and per project. Tenant-scoped (R30).
 *
 * Degrades cleanly on an empty corpus (R43): zero documents is a valid answer
 * with zero percentages, never an error.
 */
#[Description('Report how much of this tenant\'s knowledge base was authored outside the organisation. Returns document counts and percentages per provenance tier (trusted-internal / untrusted-external / machine-generated), plus how many documents carry an explicit connector declaration versus none. Optionally scoped to one project, or broken down per project. Read-only.')]
#[IsReadOnly]
#[IsIdempotent]
class KbProvenanceTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()
                ->description('Limit the summary to one project. Omit for the whole tenant.'),
            'per_project' => $schema->boolean()
                ->description('Also return a per-project breakdown, ordered by externally-authored count.')
                ->default(false),
            'limit' => $schema->integer()
                ->description('Max projects in the per-project breakdown (1–200).')
                ->default(50),
        ];
    }

    public function handle(
        Request $request,
        ProvenanceInsightsService $service,
        TenantContext $tenants,
    ): Response {
        $projectKey = $request->get('project_key');
        $projectKey = is_string($projectKey) && $projectKey !== '' ? $projectKey : null;

        $payload = [
            'tenant_id' => $tenants->current(),
            'summary' => $service->summary($projectKey),
        ];

        if ((bool) $request->get('per_project') === true) {
            $payload['per_project'] = $service->byProject(
                max(1, min(200, (int) ($request->get('limit') ?? 50))),
            );
        }

        return Response::json($payload);
    }
}
