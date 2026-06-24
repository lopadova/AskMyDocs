<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\UpsertKbPiiSettingRequest;
use App\Models\KbPiiSetting;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Pii\KbPiiPolicyResolver;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * v8.23 (Ciclo 4) — Admin surface for the per-(tenant, project) PII ingestion
 * policy (`kb_pii_settings`).
 *
 * Lets an operator set, per project (or tenant-wide via `project_key='*'`),
 * whether the inline ingestion path redacts and with which strategy
 * (`mask` / `tokenise`). Reads/writes are tenant-scoped (R30); the project list
 * is DERIVED from the tenant's real documents (R18 — never a hard-coded
 * subset).
 *
 * Auth: the READ (`index`) rides `can:viewPiiRedactorAdmin` (admin / dpo /
 * super-admin); the WRITE (`upsert`) additionally requires
 * `can:manageKbPiiPolicy` (dpo / super-admin). R32 — both endpoints are covered
 * by the AdminAuthorizationMatrix.
 */
final class KbPiiSettingController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly KbPiiPolicyResolver $resolver,
    ) {}

    /**
     * GET /api/admin/pii/policy
     *
     * Returns the config defaults, the tenant-wide ('*') row, and one entry per
     * real project in the tenant — each with its raw override (if any) and the
     * EFFECTIVE resolved values the ingestion path would apply.
     */
    public function index(): JsonResponse
    {
        $tenantId = $this->tenant->current();

        $overrides = KbPiiSetting::query()
            ->forTenant($tenantId)
            ->get()
            ->keyBy('project_key');
        $wildcard = $overrides->get(KbPiiSetting::WILDCARD);

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
                'redact_enabled' => (bool) config('kb.pii_redactor.redact_inline_ingest', false),
                // Trimmed to match KbPiiPolicyResolver::layer() so the displayed
                // default agrees with the effective behaviour.
                'strategy' => trim((string) config('kb.pii_redactor.ingest_strategy', 'mask')),
            ],
            'strategies' => KbPiiSetting::STRATEGIES,
            // Resolve effective values from the ALREADY-LOADED override rows so a
            // tenant with many projects doesn't trigger an N+1 — the resolver's
            // pure layer() takes the preloaded rows.
            'wildcard' => $this->entry(KbPiiSetting::WILDCARD, $wildcard, $wildcard),
            'projects' => array_map(
                fn (string $key): array => $this->entry($key, $overrides->get($key), $wildcard),
                $projectKeys,
            ),
        ]);
    }

    /**
     * PUT /api/admin/pii/policy
     *
     * Upsert the override for one project (or '*'). A null field CLEARS it so
     * the resolver inherits the next level up; an omitted field is unchanged.
     */
    public function upsert(UpsertKbPiiSettingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tenantId = $this->tenant->current();
        $projectKey = (string) $data['project_key'];

        // Capture the EFFECTIVE policy before the change so we can tell the
        // caller whether existing chunks/embeddings are now stale (a strategy or
        // redact-toggle change means a re-embed is recommended).
        $before = $this->resolver->resolve($tenantId, $projectKey);

        // Only touch fields the client actually SENT. An OMITTED field is left
        // unchanged (partial update); an EXPLICIT null clears it to inherit.
        $update = [];
        foreach (['redact_enabled', 'strategy'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        $row = KbPiiSetting::updateOrCreate(
            ['tenant_id' => $tenantId, 'project_key' => $projectKey],
            $update,
        );

        // If clearing the last field left an all-NULL override (every column
        // inherits), the row is indistinguishable from "no row" — delete it so
        // no-op rows never accumulate, and the response reflects no override.
        $row = $this->discardEmptyOverride($row);

        // Resolve effective using the (possibly distinct) tenant-wide row.
        $wildcard = $projectKey === KbPiiSetting::WILDCARD
            ? $row
            : KbPiiSetting::query()->forTenant($tenantId)->where('project_key', KbPiiSetting::WILDCARD)->first();

        // A changed effective strategy / redact-toggle leaves prior chunks +
        // embeddings stale; hint the operator to run a re-embed (POST
        // /api/admin/pii/reembed) for the affected project. v8.23/PR5.
        $after = $this->resolver->resolve($tenantId, $projectKey);
        $reembedRecommended = $before !== $after;

        return response()->json([
            'ok' => true,
            'setting' => $this->entry($projectKey, $row, $wildcard),
            'reembed_recommended' => $reembedRecommended,
        ]);
    }

    /**
     * Delete a row whose every override column inherits (all NULL/blank) and
     * return null; otherwise return the row unchanged. Keeps the table free of
     * no-op rows that would otherwise surface as `override: {null, null}`.
     */
    private function discardEmptyOverride(KbPiiSetting $row): ?KbPiiSetting
    {
        $strategy = is_string($row->strategy) ? trim($row->strategy) : '';
        if ($row->redact_enabled === null && $strategy === '') {
            $row->delete();

            return null;
        }

        return $row;
    }

    /**
     * Shape one project's override + effective resolved values, computed from
     * the already-loaded override rows (no per-row DB query).
     */
    private function entry(string $projectKey, ?KbPiiSetting $override, ?KbPiiSetting $wildcard): array
    {
        // The wildcard entry layers only its own row; a project entry layers the
        // tenant-wide '*' then its own override on top.
        $effective = $projectKey === KbPiiSetting::WILDCARD
            ? $this->resolver->layer($override)
            : $this->resolver->layer($wildcard, $override);

        return [
            'project_key' => $projectKey,
            'override' => $override === null ? null : [
                'redact_enabled' => $override->redact_enabled,
                'strategy' => $override->strategy,
            ],
            'effective' => $effective,
        ];
    }
}
