<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\UpsertAnalysisSettingRequest;
use App\Models\KbAnalysisSetting;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Analysis\ChangeAnalysisGate;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * v8.8/W3 — Admin surface for the per-(tenant, project) AI deep-analysis gate.
 *
 * Lets an operator turn the deep-analysis on/off per project (or tenant-wide
 * via `project_key='*'`), independently for the change path, the canonical /
 * non-canonical split, and the on-delete path. Reads/writes are tenant-scoped
 * (R30); the project list is DERIVED from the tenant's real documents (R18 —
 * never a hard-coded subset).
 *
 * Auth: `auth:sanctum` + `role:admin|super-admin` (route group). R32 — covered
 * by the AdminAuthorizationMatrix (`/api/admin/kb/analysis-settings`).
 */
final class KbAnalysisSettingController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ChangeAnalysisGate $gate,
    ) {}

    /**
     * GET /api/admin/kb/analysis-settings
     *
     * Returns the config defaults, the tenant-wide ('*') row, and one entry
     * per real project in the tenant — each with its raw override (if any) and
     * the EFFECTIVE resolved values the gate would apply.
     */
    public function index(): JsonResponse
    {
        $tenantId = $this->tenant->current();

        $overrides = KbAnalysisSetting::query()
            ->forTenant($tenantId)
            ->get()
            ->keyBy('project_key');

        // R18 — derive the project list from the real domain, not a literal.
        $projectKeys = KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->select('project_key')
            ->distinct()
            ->orderBy('project_key')
            ->pluck('project_key')
            ->all();

        return response()->json([
            'defaults' => [
                'enabled' => (bool) config('kb.change_analysis.enabled', true),
                'canonical' => (bool) config('kb.change_analysis.canonical_default', true),
                'non_canonical' => (bool) config('kb.change_analysis.non_canonical_default', false),
                'delete_enabled' => (bool) config('kb.change_analysis.delete_enabled', true),
            ],
            'wildcard' => $this->entry(KbAnalysisSetting::WILDCARD, $overrides->get(KbAnalysisSetting::WILDCARD)),
            'projects' => array_map(
                fn (string $key): array => $this->entry($key, $overrides->get($key)),
                $projectKeys,
            ),
        ]);
    }

    /**
     * PUT /api/admin/kb/analysis-settings
     *
     * Upsert the override for one project (or '*'). A null flag CLEARS that
     * field so the gate inherits the next level up.
     */
    public function upsert(UpsertAnalysisSettingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tenantId = $this->tenant->current();

        $row = KbAnalysisSetting::updateOrCreate(
            ['tenant_id' => $tenantId, 'project_key' => (string) $data['project_key']],
            [
                'enabled' => $data['enabled'] ?? null,
                'canonical' => $data['canonical'] ?? null,
                'non_canonical' => $data['non_canonical'] ?? null,
                'delete_enabled' => $data['delete_enabled'] ?? null,
            ],
        );

        return response()->json([
            'ok' => true,
            'setting' => $this->entry((string) $row->project_key, $row),
        ]);
    }

    /**
     * Shape one project's override + effective resolved values.
     */
    private function entry(string $projectKey, ?KbAnalysisSetting $override): array
    {
        return [
            'project_key' => $projectKey,
            'override' => $override === null ? null : [
                'enabled' => $override->enabled,
                'canonical' => $override->canonical,
                'non_canonical' => $override->non_canonical,
                'delete_enabled' => $override->delete_enabled,
            ],
            'effective' => $this->gate->resolve($this->tenant->current(), $projectKey),
        ];
    }
}
