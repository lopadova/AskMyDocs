<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\UpsertAutoWikiSettingRequest;
use App\Models\KbAnalysisSetting;
use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\AutoWikiGate;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * v8.11/P10 — Admin surface for the per-(tenant, project) Auto-Wiki gate.
 *
 * Lets an operator turn the auto-wiki compiler on/off per project (or tenant-wide
 * via `project_key='*'`), independently for the canonical / non-canonical split.
 * Reuses the `kb_analysis_settings.autowiki_*` columns + {@see AutoWikiGate} for
 * effective resolution. Reads/writes are tenant-scoped (R30); the project list is
 * DERIVED from the tenant's real documents (R18). R43: both states (the OFF path
 * = no auto tier produced) are reachable from this screen.
 *
 * Auth: `auth:sanctum` + `role:admin|super-admin` (route group). R32 — covered by
 * the AdminAuthorizationMatrix (`/api/admin/kb/autowiki-settings`).
 */
final class KbAutoWikiSettingController extends Controller
{
    /** @var list<string> */
    private const FLAGS = ['autowiki_enabled', 'autowiki_canonical', 'autowiki_non_canonical'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AutoWikiGate $gate,
    ) {}

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
                'enabled' => (bool) config('kb.autowiki.enabled', true),
                'canonical' => (bool) config('kb.autowiki.canonical_default', true),
                'non_canonical' => (bool) config('kb.autowiki.non_canonical_default', true),
            ],
            'wildcard' => $this->entry(KbAnalysisSetting::WILDCARD, $wildcard, $wildcard),
            'projects' => array_map(
                fn (string $key): array => $this->entry($key, $overrides->get($key), $wildcard),
                $projectKeys,
            ),
        ]);
    }

    public function upsert(UpsertAutoWikiSettingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tenantId = $this->tenant->current();
        $projectKey = (string) $data['project_key'];

        // Only touch flags the client actually SENT (partial update); an EXPLICIT
        // null clears that field so the gate inherits the next level up.
        $update = [];
        foreach (self::FLAGS as $flag) {
            if (array_key_exists($flag, $data)) {
                $update[$flag] = $data[$flag];
            }
        }

        $row = KbAnalysisSetting::updateOrCreate(
            ['tenant_id' => $tenantId, 'project_key' => $projectKey],
            $update,
        );

        $wildcard = $projectKey === KbAnalysisSetting::WILDCARD
            ? $row
            : KbAnalysisSetting::query()->forTenant($tenantId)->where('project_key', KbAnalysisSetting::WILDCARD)->first();

        return response()->json([
            'ok' => true,
            'setting' => $this->entry($projectKey, $row, $wildcard),
        ]);
    }

    /**
     * Shape one project's override + effective resolved values, computed from the
     * already-loaded override rows (no per-row DB query — N+1 safe).
     *
     * @return array<string, mixed>
     */
    private function entry(string $projectKey, ?KbAnalysisSetting $override, ?KbAnalysisSetting $wildcard): array
    {
        $effective = $projectKey === KbAnalysisSetting::WILDCARD
            ? $this->gate->layer($override)
            : $this->gate->layer($wildcard, $override);

        return [
            'project_key' => $projectKey,
            'override' => $override === null ? null : [
                'enabled' => $override->autowiki_enabled,
                'canonical' => $override->autowiki_canonical,
                'non_canonical' => $override->autowiki_non_canonical,
            ],
            'effective' => $effective,
        ];
    }
}
