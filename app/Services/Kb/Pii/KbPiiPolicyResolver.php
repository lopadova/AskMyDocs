<?php

declare(strict_types=1);

namespace App\Services\Kb\Pii;

use App\Models\KbPiiSetting;

/**
 * v8.23 (Ciclo 4) — the single decision point for "which PII posture applies
 * to ingestion for THIS (tenant, project)?".
 *
 * Resolution is layered, most-specific-wins, each NULL field inheriting the
 * next level up (mirrors {@see \App\Services\Kb\Analysis\ChangeAnalysisGate}):
 *   1. `config('kb.pii_redactor.*')`                  — the global default
 *   2. `kb_pii_settings` row (tenant, '*')            — tenant-wide override
 *   3. `kb_pii_settings` row (tenant, project)        — exact-project override
 *
 * Consumed by {@see \App\Services\Kb\DocumentIngestor} (inline HTTP/CLI path)
 * to decide whether — and with which strategy — to redact chunk text before it
 * reaches the embedding provider + the vector store, and surfaced read-only via
 * the HTTP policy endpoint + the {@see \App\Mcp\Tools\KbPiiPolicyTool} MCP tool
 * (R44 tri-surface).
 *
 * NOTE: an effective `redact_enabled=true` is necessary but NOT sufficient —
 * the master engine flags (`pii-redactor.enabled` + `kb.pii_redactor.enabled`)
 * still gate whether any redaction runs. This resolver answers the per-project
 * policy question only; the master kill-switches are checked at the call site.
 */
final class KbPiiPolicyResolver
{
    /**
     * The effective ingestion PII policy for a (tenant, project), after
     * layering the config defaults with the tenant-wide and exact-project
     * overrides.
     *
     * @return array{redact_enabled: bool, strategy: string}
     */
    public function resolve(string $tenantId, string $projectKey): array
    {
        $wildcard = $this->lookup($tenantId, KbPiiSetting::WILDCARD);

        // When the caller asks for the wildcard itself, the single '*' row is
        // the only override; otherwise '*' is the base and the exact project
        // wins on top.
        if ($projectKey === KbPiiSetting::WILDCARD) {
            return $this->layer($wildcard);
        }

        return $this->layer($wildcard, $this->lookup($tenantId, $projectKey));
    }

    /**
     * Layer the config defaults with the given override rows (in order, later
     * wins, each NULL field inheriting). Pure — takes already-loaded rows so a
     * caller listing many projects can resolve without an N+1 query. Null rows
     * are skipped.
     *
     * @return array{redact_enabled: bool, strategy: string}
     */
    public function layer(?KbPiiSetting ...$rows): array
    {
        $effective = [
            'redact_enabled' => (bool) config('kb.pii_redactor.redact_inline_ingest', false),
            'strategy' => $this->normalizeStrategy(config('kb.pii_redactor.ingest_strategy', 'mask')),
        ];

        foreach ($rows as $row) {
            if ($row === null) {
                continue;
            }
            if ($row->redact_enabled !== null) {
                $effective['redact_enabled'] = (bool) $row->redact_enabled;
            }
            // A blank/whitespace strategy override is treated as "inherit"
            // rather than silently selecting an invalid strategy.
            $strategy = is_string($row->strategy) ? trim($row->strategy) : '';
            if ($strategy !== '') {
                $effective['strategy'] = $this->normalizeStrategy($strategy);
            }
        }

        return $effective;
    }

    /**
     * Coerce a strategy value to a known token. An unrecognised value falls
     * back to the safe one-way default (`mask`) rather than reaching the
     * strategy factory with garbage — the strict-strategy throw is reserved
     * for the explicit env/config knob ({@see IngestStrategyResolver}), not a
     * (possibly legacy/corrupt) DB row.
     */
    private function normalizeStrategy(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return in_array($value, KbPiiSetting::STRATEGIES, true) ? $value : 'mask';
    }

    private function lookup(string $tenantId, string $projectKey): ?KbPiiSetting
    {
        return KbPiiSetting::query()
            ->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->first();
    }
}
