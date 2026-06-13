<?php

declare(strict_types=1);

namespace App\Services\Kb\AutoWiki;

use App\Models\KbCanonicalAudit;
use App\Models\KnowledgeDocument;
use App\Support\Canonical\EvidenceTier;
use App\Support\TenantContext;

/**
 * v8.11/P1b (AutoSci #67) — the single core for the evidence-tier capability.
 *
 * Thin layers consume this ONE service across all three surfaces (R44): the
 * Artisan command `kb:evidence-tier` (PHP), the admin HTTP endpoints, and the
 * `KbSetEvidenceTierTool` MCP tool. Setting a tier is a human override of the
 * auto-derived value (firewall: a human-set tier outranks the LLM's), audited.
 */
final class EvidenceTierService
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Human override of a document's evidence tier. Audited (event 'updated').
     * Returns the freshly-loaded document.
     */
    public function setTier(KnowledgeDocument $document, EvidenceTier $tier, string $actor): KnowledgeDocument
    {
        $previous = $document->evidence_tier;
        $document->forceFill(['evidence_tier' => $tier->value])->save();

        if ((bool) config('kb.canonical.audit_enabled', true)) {
            KbCanonicalAudit::create([
                // Explicit tenant_id from the document (R30 defense-in-depth).
                'tenant_id' => (string) $document->tenant_id,
                'project_key' => (string) $document->project_key,
                'doc_id' => $document->doc_id,
                'slug' => $document->slug,
                'event_type' => 'updated',
                'actor' => $actor,
                'before_json' => ['evidence_tier' => $previous],
                'after_json' => ['evidence_tier' => $tier->value],
                'metadata_json' => ['field' => 'evidence_tier'],
            ]);
        }

        return $document->fresh() ?? $document;
    }

    /**
     * The evidence-tier taxonomy (value + rank + low-confidence flag) — consumed
     * by the HTTP API, the MCP tool, and the admin UI badge/legend.
     *
     * @return list<array{value: string, rank: int, low_confidence: bool}>
     */
    public function taxonomy(): array
    {
        return array_map(static fn (EvidenceTier $t): array => [
            'value' => $t->value,
            'rank' => $t->rank(),
            'low_confidence' => $t->isLowConfidence(),
        ], EvidenceTier::cases());
    }

    /** Resolve a document scoped to the active tenant (R30), or null. */
    public function findForTenant(int|string $id): ?KnowledgeDocument
    {
        return KnowledgeDocument::query()
            ->forTenant($this->tenants->current())
            ->find($id);
    }
}
