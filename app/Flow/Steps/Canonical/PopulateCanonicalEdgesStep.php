<?php

declare(strict_types=1);

namespace App\Flow\Steps\Canonical;

use App\Flow\Steps\StepTenantBinder;
use App\Models\KbCanonicalAudit;
use App\Models\KbEdge;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Support\Canonical\EdgeType;
use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 3 of {@see \App\Flow\Definitions\CanonicalIndexFlow}.
 *
 * Mutates {@see KbEdge}: deletes the prior set of outgoing edges from
 * this document's slug (idempotent re-index) and inserts the fresh set
 * derived from frontmatter + chunk wikilinks. Wraps the
 * delete-then-insert pair in a single transaction so concurrent indexer
 * runs converge.
 *
 * The composite FK on `kb_edges.(project_key, from/to_node_uid)` →
 * `kb_nodes.(project_key, node_uid)` cascades on node deletion, so the
 * compensator on the prior step (rolling back created nodes) implicitly
 * unwinds the edges this step inserted. No separate edge compensator is
 * required.
 *
 * Also writes a `graph_rebuild` audit row to {@see KbCanonicalAudit} so
 * the editorial trail records every (re-)indexing pass.
 *
 * Dry-run skipped: this step's only artefact is the DB write.
 */
final class PopulateCanonicalEdgesStep implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        if ($context->dryRun) {
            return FlowStepResult::dryRunSkipped();
        }

        $loadOutput = $context->stepOutputs['load-document'] ?? null;
        if (! is_array($loadOutput)) {
            throw new RuntimeException(
                'PopulateCanonicalEdgesStep: missing prior step output [load-document].'
            );
        }
        if (! ($loadOutput['indexable'] ?? false)) {
            return FlowStepResult::success(
                output: ['skipped' => true, 'reason' => $loadOutput['reason'] ?? 'not_indexable'],
                businessImpact: ['edges_created' => 0],
            );
        }

        $documentId = (int) $loadOutput['document_id'];
        // R30 — explicit tenant scope on every read; trait only auto-fills
        // tenant_id on CREATE.
        $tenantId = (string) $context->input['tenant_id'];
        $document = KnowledgeDocument::query()->forTenant($tenantId)->find($documentId);
        if ($document === null) {
            throw new RuntimeException(
                "PopulateCanonicalEdgesStep: KnowledgeDocument [{$documentId}] vanished mid-flow."
            );
        }

        $createdEdgeIds = [];

        DB::transaction(function () use ($document, $tenantId, &$createdEdgeIds): void {
            $this->replaceEdgesFor($document, $tenantId);
            $this->createEdgesFromFrontmatter($document, $tenantId, $createdEdgeIds);
            $this->createEdgesFromChunks($document, $tenantId, $createdEdgeIds);
        });

        // E.1 — clarify the audit-write ownership. The FlowServiceProvider
        // bridge listener does NOT write a `graph_rebuild` row for this
        // step; it only bridges the `kb.promote` approval-gate
        // FlowStepFailed event into a `rejected_promotion` audit row.
        // This step is therefore the SOLE writer of the `graph_rebuild`
        // audit row, gated on the `kb.canonical.audit_enabled` config knob.
        $this->writeGraphRebuildAudit($document);

        return FlowStepResult::success(
            output: [
                'document_id' => $documentId,
                'project_key' => (string) $document->project_key,
                'slug' => (string) $document->slug,
                'doc_id' => $document->doc_id,
                'created_edge_ids' => $createdEdgeIds,
                'edges_created' => count($createdEdgeIds),
            ],
            businessImpact: [
                'edges_created' => count($createdEdgeIds),
                'project_key' => (string) $document->project_key,
                'slug' => (string) $document->slug,
                'doc_id' => $document->doc_id,
            ],
        );
    }

    private function replaceEdgesFor(KnowledgeDocument $doc, string $tenantId): void
    {
        // R30 — explicit tenant scope on the delete (BelongsToTenant trait
        // does NOT add a global read scope — only auto-fills tenant_id on
        // CREATE). The composite FK on
        // `kb_edges.(project_key, from_node_uid)` → `kb_nodes.(project_key,
        // node_uid)` enforces the tenant boundary STRUCTURALLY because edges
        // only resolve within their project_key, but we apply the explicit
        // tenant filter as defence-in-depth (and so the EXPLAIN plan shows
        // the tenant + project filter prominently).
        KbEdge::query()->forTenant($tenantId)
            ->where('project_key', $doc->project_key)
            ->where('from_node_uid', $doc->slug)
            ->delete();
    }

    /**
     * @param  list<int>  $createdEdgeIds
     */
    private function createEdgesFromFrontmatter(KnowledgeDocument $doc, string $tenantId, array &$createdEdgeIds): void
    {
        $derived = $this->derivedSlugLists($doc);

        foreach ($derived['related_slugs'] as $target) {
            $this->createEdge($doc, $tenantId, $target, EdgeType::RelatedTo, 'frontmatter_related', $createdEdgeIds);
        }
        foreach ($derived['supersedes_slugs'] as $target) {
            $this->createEdge($doc, $tenantId, $target, EdgeType::Supersedes, 'frontmatter_supersedes', $createdEdgeIds);
        }
        foreach ($derived['superseded_by_slugs'] as $target) {
            $this->createEdge($doc, $tenantId, $target, EdgeType::InvalidatedBy, 'frontmatter_superseded_by', $createdEdgeIds);
        }
    }

    /**
     * @param  list<int>  $createdEdgeIds
     */
    private function createEdgesFromChunks(KnowledgeDocument $doc, string $tenantId, array &$createdEdgeIds): void
    {
        $seen = [];
        // R30 — KnowledgeChunk is tenant-aware; explicit tenant scope on
        // the chunk iterator.
        KnowledgeChunk::query()->forTenant($tenantId)
            ->where('knowledge_document_id', $doc->id)
            ->chunkById(200, function ($chunks) use ($doc, $tenantId, &$seen, &$createdEdgeIds) {
                foreach ($chunks as $chunk) {
                    foreach ($this->extractWikilinks($chunk->metadata) as $target) {
                        if (isset($seen[$target])) {
                            continue;
                        }
                        $seen[$target] = true;
                        $this->createEdge($doc, $tenantId, $target, EdgeType::RelatedTo, 'wikilink', $createdEdgeIds);
                    }
                }
            });
    }

    /**
     * @param  list<int>  $createdEdgeIds
     */
    private function createEdge(
        KnowledgeDocument $doc,
        string $tenantId,
        string $targetSlug,
        EdgeType $edgeType,
        string $provenance,
        array &$createdEdgeIds,
    ): void {
        if ($targetSlug === $doc->slug || $targetSlug === '') {
            return;
        }

        // R30 — BelongsToTenant does NOT add a global read scope, so a
        // bare updateOrCreate by (project_key, edge_uid) could match /
        // update another tenant's edge if the same project_key is shared
        // (per CLAUDE.md R10: project_key is NOT globally unique across
        // tenants). Scope the lookup explicitly via forTenant() AND mirror
        // tenant_id in the create payload as defence-in-depth so the
        // explicit value wins even if forTenant() were ever scoped to
        // reads only.
        //
        // Iteration 3 (PR #116) — Copilot flagged this as a missed
        // R30 sweep site. Pairs with the existing replaceEdgesFor()
        // tenant-scoped delete above.
        $edge = KbEdge::query()
            ->forTenant($tenantId)
            ->updateOrCreate(
                [
                    'project_key' => (string) $doc->project_key,
                    'edge_uid' => "{$doc->slug}->{$targetSlug}:{$edgeType->value}",
                ],
                [
                    'tenant_id' => $tenantId,
                    'from_node_uid' => (string) $doc->slug,
                    'to_node_uid' => $targetSlug,
                    'edge_type' => $edgeType->value,
                    'source_doc_id' => $doc->doc_id,
                    'weight' => $edgeType->defaultWeight(),
                    'provenance' => $provenance,
                    'payload_json' => null,
                ]
            );

        if ($edge->wasRecentlyCreated) {
            $createdEdgeIds[] = (int) $edge->id;
        }
    }

    private function writeGraphRebuildAudit(KnowledgeDocument $doc): void
    {
        if (! (bool) config('kb.canonical.audit_enabled', true)) {
            return;
        }
        KbCanonicalAudit::create([
            'project_key' => (string) $doc->project_key,
            'doc_id' => $doc->doc_id,
            'slug' => $doc->slug,
            'event_type' => 'graph_rebuild',
            'actor' => 'flow:kb.canonical-index',
            'before_json' => null,
            'after_json' => [
                'canonical_type' => $doc->canonical_type,
                'canonical_status' => $doc->canonical_status,
                'retrieval_priority' => $doc->retrieval_priority,
            ],
            'metadata_json' => null,
        ]);
    }

    /**
     * @return array{related_slugs: list<string>, supersedes_slugs: list<string>, superseded_by_slugs: list<string>}
     */
    private function derivedSlugLists(KnowledgeDocument $doc): array
    {
        $empty = ['related_slugs' => [], 'supersedes_slugs' => [], 'superseded_by_slugs' => []];
        $fm = $doc->frontmatter_json;
        if (! is_array($fm)) {
            return $empty;
        }
        $derived = $fm['_derived'] ?? null;
        if (! is_array($derived)) {
            return $empty;
        }
        return [
            'related_slugs' => $this->asSlugList($derived['related_slugs'] ?? []),
            'supersedes_slugs' => $this->asSlugList($derived['supersedes_slugs'] ?? []),
            'superseded_by_slugs' => $this->asSlugList($derived['superseded_by_slugs'] ?? []),
        ];
    }

    /**
     * @return list<string>
     */
    private function extractWikilinks(mixed $metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }
        return $this->asSlugList($metadata['wikilinks'] ?? []);
    }

    /**
     * @return list<string>
     */
    private function asSlugList(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }
        return array_values(array_filter($input, static fn ($v) => is_string($v) && $v !== ''));
    }
}
