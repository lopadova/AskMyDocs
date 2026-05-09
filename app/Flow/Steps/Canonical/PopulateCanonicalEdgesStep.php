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
        $document = KnowledgeDocument::find($documentId);
        if ($document === null) {
            throw new RuntimeException(
                "PopulateCanonicalEdgesStep: KnowledgeDocument [{$documentId}] vanished mid-flow."
            );
        }

        $createdEdgeIds = [];

        DB::transaction(function () use ($document, &$createdEdgeIds): void {
            $this->replaceEdgesFor($document);
            $this->createEdgesFromFrontmatter($document, $createdEdgeIds);
            $this->createEdgesFromChunks($document, $createdEdgeIds);
        });

        // Per-step authoritative audit write: keep the kb_canonical_audit
        // row owned by the step that actually mutated the graph so the
        // editorial trail stays correct even when the FlowServiceProvider
        // bridge listener is disabled. The bridge writes a SECOND row with
        // a different `actor` (`flow:kb.canonical-index:populate-edges`)
        // when active; consumers filter by `event_type` and tolerate
        // both rows.
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

    private function replaceEdgesFor(KnowledgeDocument $doc): void
    {
        // R30 — KbEdge BelongsToTenant; the global query scope filters by
        // tenant. The composite FK enforces the tenant boundary structurally
        // anyway, but we keep the explicit project_key clause so the EXPLAIN
        // plan shows the tenant + project filter prominently.
        KbEdge::where('project_key', $doc->project_key)
            ->where('from_node_uid', $doc->slug)
            ->delete();
    }

    /**
     * @param  list<int>  $createdEdgeIds
     */
    private function createEdgesFromFrontmatter(KnowledgeDocument $doc, array &$createdEdgeIds): void
    {
        $derived = $this->derivedSlugLists($doc);

        foreach ($derived['related_slugs'] as $target) {
            $this->createEdge($doc, $target, EdgeType::RelatedTo, 'frontmatter_related', $createdEdgeIds);
        }
        foreach ($derived['supersedes_slugs'] as $target) {
            $this->createEdge($doc, $target, EdgeType::Supersedes, 'frontmatter_supersedes', $createdEdgeIds);
        }
        foreach ($derived['superseded_by_slugs'] as $target) {
            $this->createEdge($doc, $target, EdgeType::InvalidatedBy, 'frontmatter_superseded_by', $createdEdgeIds);
        }
    }

    /**
     * @param  list<int>  $createdEdgeIds
     */
    private function createEdgesFromChunks(KnowledgeDocument $doc, array &$createdEdgeIds): void
    {
        $seen = [];
        KnowledgeChunk::where('knowledge_document_id', $doc->id)
            ->chunkById(200, function ($chunks) use ($doc, &$seen, &$createdEdgeIds) {
                foreach ($chunks as $chunk) {
                    foreach ($this->extractWikilinks($chunk->metadata) as $target) {
                        if (isset($seen[$target])) {
                            continue;
                        }
                        $seen[$target] = true;
                        $this->createEdge($doc, $target, EdgeType::RelatedTo, 'wikilink', $createdEdgeIds);
                    }
                }
            });
    }

    /**
     * @param  list<int>  $createdEdgeIds
     */
    private function createEdge(
        KnowledgeDocument $doc,
        string $targetSlug,
        EdgeType $edgeType,
        string $provenance,
        array &$createdEdgeIds,
    ): void {
        if ($targetSlug === $doc->slug || $targetSlug === '') {
            return;
        }

        // Existence check + insert — updateOrCreate would silently bump an
        // existing row's columns, but the prior replaceEdgesFor() call
        // already wiped the outgoing set so a found row here would mean
        // a concurrent indexer raced us; in that case let the unique
        // constraint resolve the winner.
        $edge = KbEdge::updateOrCreate(
            [
                'project_key' => (string) $doc->project_key,
                'edge_uid' => "{$doc->slug}->{$targetSlug}:{$edgeType->value}",
            ],
            [
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
