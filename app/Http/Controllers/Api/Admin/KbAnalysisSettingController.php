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
        $wildcard = $overrides->get(KbAnalysisSetting::WILDCARD);

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
            // Resolve effective values from the ALREADY-LOADED override rows so
            // a tenant with many projects doesn't trigger an N+1 (Copilot
            // review) — the gate's pure layer() takes the preloaded rows.
            'wildcard' => $this->entry(KbAnalysisSetting::WILDCARD, $wildcard, $wildcard),
            'projects' => array_map(
                fn (string $key): array => $this->entry($key, $overrides->get($key), $wildcard),
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
        $projectKey = (string) $data['project_key'];

        // Only touch flags the client actually SENT. An OMITTED field is left
        // unchanged (partial update); an EXPLICIT null clears it to inherit.
        // This stops a client changing one knob from wiping the others
        // (Copilot review).
        $update = [];
        foreach (['enabled', 'canonical', 'non_canonical', 'delete_enabled'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $update[$flag] = $data[$flag];
            }
        }

        $row = KbAnalysisSetting::updateOrCreate(
            ['tenant_id' => $tenantId, 'project_key' => $projectKey],
            $update,
        );

        // Resolve effective using the (possibly distinct) tenant-wide row.
        $wildcard = $projectKey === KbAnalysisSetting::WILDCARD
            ? $row
            : KbAnalysisSetting::query()->forTenant($tenantId)->where('project_key', KbAnalysisSetting::WILDCARD)->first();

        return response()->json([
            'ok' => true,
            'setting' => $this->entry($projectKey, $row, $wildcard),
        ]);
    }

    /**
     * Shape one project's override + effective resolved values, computed from
     * the already-loaded override rows (no per-row DB query).
     */
    private function entry(string $projectKey, ?KbAnalysisSetting $override, ?KbAnalysisSetting $wildcard): array
    {
        // The wildcard entry layers only its own row; a project entry layers
        // the tenant-wide '*' then its own override on top.
        $effective = $projectKey === KbAnalysisSetting::WILDCARD
            ? $this->gate->layer($override)
            : $this->gate->layer($wildcard, $override);

        return [
            'project_key' => $projectKey,
            'override' => $override === null ? null : [
                'enabled' => $override->enabled,
                'canonical' => $override->canonical,
                'non_canonical' => $override->non_canonical,
                'delete_enabled' => $override->delete_enabled,
            ],
            'effective' => $effective,
        ];
    }
}
