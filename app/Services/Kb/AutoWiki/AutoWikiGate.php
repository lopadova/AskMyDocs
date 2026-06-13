<?php

declare(strict_types=1);

namespace App\Services\Kb\AutoWiki;

use App\Models\KbAnalysisSetting;

/**
 * v8.11 — the single decision point for "should the AutoWikiCompiler run for
 * THIS (tenant, project, doc)?".
 *
 * Mirrors {@see \App\Services\Kb\Analysis\ChangeAnalysisGate}: layered,
 * most-specific-wins, each NULL field inheriting the next level up:
 *   1. `config('kb.autowiki.*')`                      — the global default
 *   2. `kb_analysis_settings` row (tenant, '*')       — tenant-wide override
 *   3. `kb_analysis_settings` row (tenant, project)   — exact-project override
 *
 * The per-(tenant,project) override reuses the existing `kb_analysis_settings`
 * table (nullable `autowiki_*` columns added in v8.11); a NULL inherits. The
 * config layer always applies, so the gate works even before any override row
 * exists. Default-ON (R43 both-states: OFF path = today's behaviour, no auto
 * tier produced).
 */
final class AutoWikiGate
{
    /**
     * Whether the auto-wiki compiler should run for the given document context.
     */
    public function allows(string $tenantId, string $projectKey, bool $isCanonical): bool
    {
        $resolved = $this->resolve($tenantId, $projectKey);

        if (! $resolved['enabled']) {
            return false;
        }

        return $isCanonical ? $resolved['canonical'] : $resolved['non_canonical'];
    }

    /**
     * The effective auto-wiki settings for a (tenant, project), after layering
     * the config defaults with the tenant-wide and exact-project overrides.
     *
     * @return array{enabled: bool, canonical: bool, non_canonical: bool}
     */
    public function resolve(string $tenantId, string $projectKey): array
    {
        $wildcard = $this->lookup($tenantId, KbAnalysisSetting::WILDCARD);

        if ($projectKey === KbAnalysisSetting::WILDCARD) {
            return $this->layer($wildcard);
        }

        return $this->layer($wildcard, $this->lookup($tenantId, $projectKey));
    }

    /**
     * Layer the config defaults with the given override rows (later wins, each
     * NULL field inheriting). Pure — takes already-loaded rows so a caller
     * listing many projects can resolve without an N+1 query. Null rows skipped.
     *
     * @return array{enabled: bool, canonical: bool, non_canonical: bool}
     */
    public function layer(?KbAnalysisSetting ...$rows): array
    {
        $effective = [
            'enabled' => (bool) config('kb.autowiki.enabled', true),
            'canonical' => (bool) config('kb.autowiki.canonical_default', true),
            'non_canonical' => (bool) config('kb.autowiki.non_canonical_default', true),
        ];

        $fieldMap = [
            'enabled' => 'autowiki_enabled',
            'canonical' => 'autowiki_canonical',
            'non_canonical' => 'autowiki_non_canonical',
        ];

        foreach ($rows as $row) {
            if ($row === null) {
                continue;
            }
            foreach ($fieldMap as $field => $column) {
                $value = $row->getAttribute($column);
                if ($value !== null) {
                    $effective[$field] = (bool) $value;
                }
            }
        }

        // Master switch OFF ⇒ no auto-wiki on any path; keep the dependent
        // knobs net-OFF so the admin "effective" display agrees with allows().
        if (! $effective['enabled']) {
            $effective['canonical'] = false;
            $effective['non_canonical'] = false;
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
