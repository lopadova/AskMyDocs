<?php

declare(strict_types=1);

namespace App\Services\Kb\Analysis;

use App\Models\KbAnalysisSetting;

/**
 * v8.8/W3 — the single decision point for "should the AI deep-analysis run
 * for THIS (tenant, project, doc)?".
 *
 * Resolution is layered, most-specific-wins, each NULL field inheriting the
 * next level up:
 *   1. `config('kb.change_analysis.*')`            — the global default
 *   2. `kb_analysis_settings` row (tenant, '*')    — tenant-wide override
 *   3. `kb_analysis_settings` row (tenant, project) — exact-project override
 *
 * Used by {@see \App\Jobs\AnalyzeDocumentChangeJob},
 * {@see \App\Jobs\AnalyzeDocumentDeletionJob}, and
 * {@see \App\Services\Kb\DocumentDeleter} so the gate lives in ONE place
 * (R10 — canonical/non-canonical handled deliberately; no hard-coded
 * "always on").
 */
final class ChangeAnalysisGate
{
    /**
     * Whether an analysis should run for the given document context.
     *
     * @param  bool  $isDelete  true for the delete-trigger path (adds the
     *                          `delete_enabled` knob on top of `enabled`).
     */
    public function allows(string $tenantId, string $projectKey, bool $isCanonical, bool $isDelete = false): bool
    {
        $resolved = $this->resolve($tenantId, $projectKey);

        if (! $resolved['enabled']) {
            return false;
        }
        if ($isDelete && ! $resolved['delete_enabled']) {
            return false;
        }

        return $isCanonical ? $resolved['canonical'] : $resolved['non_canonical'];
    }

    /**
     * The effective settings for a (tenant, project), after layering the
     * config defaults with the tenant-wide and exact-project overrides.
     *
     * @return array{enabled: bool, canonical: bool, non_canonical: bool, delete_enabled: bool}
     */
    public function resolve(string $tenantId, string $projectKey): array
    {
        $effective = [
            'enabled' => (bool) config('kb.change_analysis.enabled', true),
            'canonical' => (bool) config('kb.change_analysis.canonical_default', true),
            'non_canonical' => (bool) config('kb.change_analysis.non_canonical_default', false),
            'delete_enabled' => (bool) config('kb.change_analysis.delete_enabled', true),
        ];

        // tenant-wide ('*') first, then the exact project so it wins. When the
        // caller asks for the wildcard itself, the single '*' lookup suffices.
        $keys = $projectKey === KbAnalysisSetting::WILDCARD
            ? [KbAnalysisSetting::WILDCARD]
            : [KbAnalysisSetting::WILDCARD, $projectKey];

        foreach ($keys as $key) {
            $row = $this->lookup($tenantId, $key);
            if ($row === null) {
                continue;
            }
            foreach (array_keys($effective) as $field) {
                $value = $row->getAttribute($field);
                if ($value !== null) {
                    $effective[$field] = (bool) $value;
                }
            }
        }

        return $effective;
    }

    private function lookup(string $tenantId, string $projectKey): ?KbAnalysisSetting
    {
        return KbAnalysisSetting::query()
            ->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->first();
    }
}
