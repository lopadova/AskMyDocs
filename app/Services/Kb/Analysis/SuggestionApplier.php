<?php

declare(strict_types=1);

namespace App\Services\Kb\Analysis;

use App\Models\KbCanonicalAudit;
use App\Models\KbDocAnalysis;
use App\Models\KbDocAnalysisApplication;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use App\Support\Canonical\EdgeType;
use App\Support\Canonical\GenerationSource;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v8.11/P8 — apply engine: turn a `kb_doc_analyses` suggestion into a concrete,
 * audited, reversible mutation.
 *
 * Two suggestion types:
 *   - cross_reference → add an inferred edge from the analyzed doc to a
 *     suggested neighbour (the doc gains a navigable link);
 *   - impacted        → mark an impacted doc `deprecated` (a change made it stale).
 *
 * Every application is validated against the analysis (you can only apply a
 * suggestion the analysis actually produced — no arbitrary mutation), recorded
 * in {@see KbDocAnalysisApplication} (with before/after for reversibility) AND
 * {@see KbCanonicalAudit}, and tenant-scoped (R30).
 *
 * Firewall (ADR 0003): a human-curated `accepted` canonical doc is NEVER mutated
 * by an AUTO apply. Manual apply (an explicit human action) may touch it.
 *
 * Tri-surface (R44): `kb:apply-suggestion`, the admin HTTP endpoint, and the
 * `KbApplySuggestionTool` MCP tool. Opt-in auto-apply (default-OFF,
 * `kb.change_analysis.autoapply_enabled`) routes eligible suggestions through
 * {@see autoApply()} from the analysis job.
 */
class SuggestionApplier
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Apply one suggestion. `$type` is 'cross_reference' | 'impacted'.
     *
     * @return array{applied: bool, reason?: string, action?: string, source?: ?string, target?: string}
     */
    public function apply(KbDocAnalysis $analysis, string $type, string $targetSlug, string $actor, bool $auto = false): array
    {
        $targetSlug = trim($targetSlug);
        if ($targetSlug === '') {
            return ['applied' => false, 'reason' => 'empty_target'];
        }

        return match ($type) {
            'cross_reference' => $this->applyCrossReference($analysis, $targetSlug, $actor, $auto),
            'impacted' => $this->applyImpacted($analysis, $targetSlug, $actor, $auto),
            default => ['applied' => false, 'reason' => 'unknown_type'],
        };
    }

    /**
     * Opt-in auto-apply (default-OFF). Applies the SAFE subset automatically:
     * cross-references from an AUTO-tier analyzed doc. Returns the count applied.
     *
     * @return array{ran: bool, applied: int}
     */
    public function autoApply(KbDocAnalysis $analysis): array
    {
        if (! (bool) config('kb.change_analysis.autoapply_enabled', false)) {
            return ['ran' => false, 'applied' => 0];
        }

        $applied = 0;
        foreach ($this->suggestedSlugs($analysis, 'cross_references') as $slug) {
            $result = $this->applyCrossReference($analysis, $slug, 'system:autowiki-apply', true);
            if ($result['applied']) {
                $applied++;
            }
        }

        return ['ran' => true, 'applied' => $applied];
    }

    /**
     * @return array{applied: bool, reason?: string, action?: string, source?: ?string, target?: string}
     */
    private function applyCrossReference(KbDocAnalysis $analysis, string $targetSlug, string $actor, bool $auto): array
    {
        if (! in_array($targetSlug, $this->suggestedSlugs($analysis, 'cross_references'), true)) {
            return ['applied' => false, 'reason' => 'not_in_suggestions', 'target' => $targetSlug];
        }

        $tenantId = (string) $analysis->tenant_id;
        $source = $this->sourceDoc($analysis);
        if ($source === null || (string) ($source->slug ?? '') === '') {
            return ['applied' => false, 'reason' => 'source_unresolved', 'target' => $targetSlug];
        }
        if ($auto && $this->isHumanAccepted($source)) {
            return ['applied' => false, 'reason' => 'firewall_human_doc', 'target' => $targetSlug];
        }

        $sourceSlug = (string) $source->slug;
        $projectKey = (string) $analysis->project_key;

        // One transaction: the edge mutation + its application-audit row commit
        // or roll back together, so an edge can never exist without its forensic
        // record (this engine's contract is "audited + reversible").
        DB::transaction(function () use ($tenantId, $projectKey, $sourceSlug, $targetSlug, $source, $analysis, $actor): void {
            $this->ensureNode($tenantId, $projectKey, $sourceSlug, $source->doc_id, false);
            $this->ensureNode($tenantId, $projectKey, $targetSlug, null, true);
            KbEdge::query()->forTenant($tenantId)->updateOrCreate(
                ['project_key' => $projectKey, 'edge_uid' => "{$sourceSlug}->{$targetSlug}:related_to"],
                [
                    'tenant_id' => $tenantId, 'from_node_uid' => $sourceSlug, 'to_node_uid' => $targetSlug,
                    'edge_type' => EdgeType::RelatedTo->value, 'source_doc_id' => $source->doc_id,
                    'weight' => EdgeType::RelatedTo->defaultWeight(), 'provenance' => 'inferred', 'payload_json' => null,
                ],
            );
            $this->record($analysis, 'cross_reference', 'add_cross_reference', $sourceSlug, $targetSlug, $actor, null, ['edge' => "{$sourceSlug}->{$targetSlug}"]);
        });

        return ['applied' => true, 'action' => 'add_cross_reference', 'source' => $sourceSlug, 'target' => $targetSlug];
    }

    /**
     * @return array{applied: bool, reason?: string, action?: string, source?: ?string, target?: string}
     */
    private function applyImpacted(KbDocAnalysis $analysis, string $targetSlug, string $actor, bool $auto): array
    {
        if (! in_array($targetSlug, $this->suggestedSlugs($analysis, 'impacted_docs'), true)) {
            return ['applied' => false, 'reason' => 'not_in_suggestions', 'target' => $targetSlug];
        }

        $tenantId = (string) $analysis->tenant_id;
        $target = KnowledgeDocument::query()->forTenant($tenantId)
            ->where('project_key', $analysis->project_key)
            ->where('slug', $targetSlug)
            ->first();
        if ($target === null) {
            return ['applied' => false, 'reason' => 'target_unresolved', 'target' => $targetSlug];
        }
        if ($auto && $this->isHumanAccepted($target)) {
            return ['applied' => false, 'reason' => 'firewall_human_doc', 'target' => $targetSlug];
        }

        $before = (string) ($target->canonical_status ?? '');
        if ($before === 'deprecated') {
            return ['applied' => false, 'reason' => 'already_deprecated', 'target' => $targetSlug];
        }

        // Atomic: the status change + both audit rows (application + canonical)
        // commit or roll back together, preserving reversibility.
        DB::transaction(function () use ($target, $before, $analysis, $targetSlug, $actor): void {
            $target->forceFill(['canonical_status' => 'deprecated'])->save();
            $this->record($analysis, 'impacted', 'deprecate', null, $targetSlug, $actor, ['canonical_status' => $before], ['canonical_status' => 'deprecated']);
            $this->canonicalAudit($target, 'deprecated', $actor, ['from_status' => $before]);
        });

        return ['applied' => true, 'action' => 'deprecate', 'target' => $targetSlug];
    }

    private function sourceDoc(KbDocAnalysis $analysis): ?KnowledgeDocument
    {
        return KnowledgeDocument::query()->forTenant((string) $analysis->tenant_id)
            ->find((int) $analysis->knowledge_document_id);
    }

    private function isHumanAccepted(KnowledgeDocument $doc): bool
    {
        return (bool) $doc->is_canonical
            && (string) ($doc->generation_source ?? GenerationSource::Human->value) === GenerationSource::Human->value
            && (string) ($doc->canonical_status ?? '') === 'accepted';
    }

    /** @return list<string> slugs the analysis suggested for the given key */
    private function suggestedSlugs(KbDocAnalysis $analysis, string $key): array
    {
        $json = is_array($analysis->analysis_json) ? $analysis->analysis_json : [];
        $entries = $json[$key] ?? [];
        if (! is_array($entries)) {
            return [];
        }
        $out = [];
        foreach ($entries as $entry) {
            $slug = is_array($entry) ? ($entry['slug'] ?? null) : null;
            if (is_string($slug) && trim($slug) !== '' && ! in_array(trim($slug), $out, true)) {
                $out[] = trim($slug);
            }
        }

        return $out;
    }

    private function ensureNode(string $tenantId, string $projectKey, string $uid, ?string $sourceDocId, bool $dangling): void
    {
        $node = KbNode::query()->forTenant($tenantId)->firstOrCreate(
            ['project_key' => $projectKey, 'node_uid' => $uid],
            ['node_type' => $dangling ? 'unknown' : 'domain-concept', 'label' => $uid, 'source_doc_id' => $sourceDocId, 'payload_json' => ['dangling' => $dangling]],
        );
        if (! $node->wasRecentlyCreated && ! $dangling && $node->source_doc_id === null && $sourceDocId !== null) {
            $node->update(['source_doc_id' => $sourceDocId, 'payload_json' => ['dangling' => false]]);
        }
    }

    /**
     * @param  array<string,mixed>|null  $before
     * @param  array<string,mixed>|null  $after
     */
    private function record(KbDocAnalysis $analysis, string $type, string $action, ?string $sourceSlug, string $targetSlug, string $actor, ?array $before, ?array $after): void
    {
        KbDocAnalysisApplication::create([
            'tenant_id' => (string) $analysis->tenant_id,
            'project_key' => (string) $analysis->project_key,
            'analysis_id' => (int) $analysis->id,
            'suggestion_type' => $type,
            'action' => $action,
            'source_slug' => $sourceSlug,
            'target_slug' => $targetSlug,
            'before_json' => $before,
            'after_json' => $after,
            'applied_by' => $actor,
            'created_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $metadata */
    private function canonicalAudit(KnowledgeDocument $doc, string $event, string $actor, array $metadata): void
    {
        if (! (bool) config('kb.canonical.audit_enabled', true)) {
            return;
        }
        KbCanonicalAudit::create([
            'tenant_id' => (string) $doc->tenant_id,
            'project_key' => (string) $doc->project_key,
            'doc_id' => $doc->doc_id,
            'slug' => $doc->slug,
            'event_type' => $event,
            'actor' => $actor,
            'metadata_json' => array_merge(['source' => 'suggestion_applier'], $metadata),
        ]);
    }
}
