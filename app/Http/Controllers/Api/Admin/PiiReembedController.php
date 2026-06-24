<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Services\Kb\Pii\ReembedProjectService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.23 (Ciclo 4, PR5) — HTTP surface to re-embed a project's documents under
 * the current PII ingestion policy (e.g. after switching mask ⇄ tokenise).
 *
 * POST /api/admin/pii/reembed  { "project_key": "acme-support" }
 *
 * Mounted in the `admin/pii` group; the route adds `can:manageKbPiiPolicy`
 * (dpo / super-admin) since re-embedding is a policy-governance action. Queues
 * one re-embed job per live document (tenant-scoped, R30) and returns the count.
 */
final class PiiReembedController extends Controller
{
    public function __construct(
        private readonly ReembedProjectService $service,
        private readonly TenantContext $tenant,
    ) {}

    public function reembed(Request $request): JsonResponse
    {
        $data = $request->validate([
            // regex:/\S/ rejects whitespace-only keys (which would queue 0 docs
            // and answer a misleading 200) — tri-surface parity with the MCP tool.
            'project_key' => ['required', 'string', 'max:120', 'regex:/\S/'],
        ]);

        $tenantId = $this->tenant->current();
        $queued = $this->service->reembedProject($tenantId, (string) $data['project_key']);

        return response()->json([
            'tenant_id' => $tenantId,
            'project_key' => $data['project_key'],
            'queued' => $queued,
        ]);
    }
}
