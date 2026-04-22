<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KbCanonicalAudit;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Support\Canonical\CanonicalType;
use App\Support\Canonical\EdgeType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Populates {@see \App\Models\KbNode} and {@see \App\Models\KbEdge} from a
 * canonical document's frontmatter + chunk wikilinks. Idempotent: on
 * re-indexing it first removes any previous edges it created for this
 * document, then rebuilds fresh.
 *
 * Inputs consumed on the document:
 *   - `frontmatter_json._derived.related_slugs`        → edge_type=related_to,  provenance=frontmatter_related
 *   - `frontmatter_json._derived.supersedes_slugs`     → edge_type=supersedes,  provenance=frontmatter_supersedes
 *   - `frontmatter_json._derived.superseded_by_slugs`  → edge_type=invalidated_by, provenance=frontmatter_superseded_by
 *   - each chunk's `metadata.wikilinks`                → edge_type=related_to,  provenance=wikilink
 *
 * Tenant scope is enforced structurally via the composite FK on
 * `kb_edges.(project_key, from/to_node_uid)` → `kb_nodes.(project_key,
 * node_uid)`. Cross-project edges are impossible; this job only needs to
 * ensure target nodes exist in the same project (created as "dangling"
 * when not yet canonicalized).
 */
class CanonicalIndexerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public readonly int $documentId)
    {
        $this->onQueue(config('kb.ingest.queue', 'kb-ingest'));
    }

    public function handle(): void
    {
        $doc = KnowledgeDocument::find($this->documentId);
        if ($doc === null) {
            return;
        }
        if (! $doc->is_canonical) {
            return;
        }
        if ($doc->slug === null || $doc->canonical_type === null) {
            return;
        }
        // Do not index a doc that has already been archived — the ingest
        // pipeline marks prior versions as `archived` when a newer version
        // takes over, and retrieval filters them out. Indexing the archived
        // row would rebuild the graph from stale content.
        if ($doc->status === 'archived') {
            return;
        }

        DB::transaction(function () use ($doc) {
            $this->replaceEdgesFor($doc);
            $this->upsertSelfNode($doc);
            $this->createEdgesFromFrontmatter($doc);
            $this->createEdgesFromChunks($doc);
            $this->writeAuditRow($doc);
        });
    }

    // -----------------------------------------------------------------
    // idempotent cleanup
    // -----------------------------------------------------------------

    private function replaceEdgesFor(KnowledgeDocument $doc): void
    {
        KbEdge::where('project_key', $doc->project_key)
            ->where('from_node_uid', $doc->slug)
            ->delete();
    }

    // -----------------------------------------------------------------
    // self node (upsert without overwriting a pre-existing dangling marker
    // of a different doc — but this doc IS the source, so it's always
    // non-dangling after this call)
    // -----------------------------------------------------------------

    private function upsertSelfNode(KnowledgeDocument $doc): void
    {
        $type = CanonicalType::tryFrom((string) $doc->canonical_type);
        $nodeType = $type !== null ? $type->nodeType() : (string) $doc->canonical_type;

        KbNode::updateOrCreate(
            [
                'project_key' => $doc->project_key,
                'node_uid' => $doc->slug,
            ],
            [
                'node_type' => $nodeType,
                'label' => $doc->title,
                'source_doc_id' => $doc->doc_id,
                'payload_json' => [
                    'dangling' => false,
                    'canonical_status' => $doc->canonical_status,
                ],
            ]
        );
    }

    // -----------------------------------------------------------------
    // frontmatter-driven edges
    // -----------------------------------------------------------------

    private function createEdgesFromFrontmatter(KnowledgeDocument $doc): void
    {
        $derived = $this->derivedSlugLists($doc);

        foreach ($derived['related_slugs'] as $target) {
            $this->createEdge($doc, $target, EdgeType::RelatedTo, 'frontmatter_related');
        }
        foreach ($derived['supersedes_slugs'] as $target) {
            $this->createEdge($doc, $target, EdgeType::Supersedes, 'frontmatter_supersedes');
        }
        foreach ($derived['superseded_by_slugs'] as $target) {
            $this->createEdge($doc, $target, EdgeType::InvalidatedBy, 'frontmatter_superseded_by');
        }
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
     * @param  mixed  $input
     * @return list<string>
     */
    private function asSlugList(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }
        return array_values(array_filter($input, static fn ($v) => is_string($v) && $v !== ''));
    }

    // -----------------------------------------------------------------
    // chunk-wikilink-driven edges
    // -----------------------------------------------------------------

    private function createEdgesFromChunks(KnowledgeDocument $doc): void
    {
        $seen = [];
        KnowledgeChunk::where('knowledge_document_id', $doc->id)
            ->chunkById(200, function ($chunks) use ($doc, &$seen) {
                foreach ($chunks as $chunk) {
                    $this->collectWikilinksFromChunk($chunk, $doc, $seen);
                }
            });
    }

    /**
     * @param  array<string, true>  $seen
     */
    private function collectWikilinksFromChunk(KnowledgeChunk $chunk, KnowledgeDocument $doc, array &$seen): void
    {
        $wikilinks = $this->extractWikilinks($chunk->metadata);
        foreach ($wikilinks as $target) {
            if (isset($seen[$target])) {
                continue;
            }
            $seen[$target] = true;
            $this->createEdge($doc, $target, EdgeType::RelatedTo, 'wikilink');
        }
    }

    /**
     * @param  mixed  $metadata
     * @return list<string>
     */
    private function extractWikilinks(mixed $metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }
        $links = $metadata['wikilinks'] ?? [];
        return $this->asSlugList($links);
    }

    // -----------------------------------------------------------------
    // edge creation (with target-node upsert)
    // -----------------------------------------------------------------

    private function createEdge(
        KnowledgeDocument $doc,
        string $targetSlug,
        EdgeType $edgeType,
        string $provenance,
    ): void {
        if ($targetSlug === $doc->slug) {
            return;
        }

        $this->ensureTargetNode($doc->project_key, $targetSlug);

        KbEdge::updateOrCreate(
            [
                'project_key' => $doc->project_key,
                'edge_uid' => "{$doc->slug}->{$targetSlug}:{$edgeType->value}",
            ],
            [
                'from_node_uid' => $doc->slug,
                'to_node_uid' => $targetSlug,
                'edge_type' => $edgeType->value,
                'source_doc_id' => $doc->doc_id,
                'weight' => $edgeType->defaultWeight(),
                'provenance' => $provenance,
                'payload_json' => null,
            ]
        );
    }

    /**
     * Create a target node as "dangling" if it doesn't exist yet. Atomic
     * under concurrent indexer runs — `firstOrCreate` uses the composite
     * unique `uq_kb_nodes_project_uid(project_key, node_uid)` as the
     * existence check, so two workers trying to insert the same target
     * converge safely (one wins, the other gets the existing row). Never
     * overwrites an already-canonicalized node.
     */
    private function ensureTargetNode(string $projectKey, string $slug): void
    {
        KbNode::firstOrCreate(
            [
                'project_key' => $projectKey,
                'node_uid' => $slug,
            ],
            [
                'node_type' => 'unknown',
                'label' => $slug,
                'source_doc_id' => null,
                'payload_json' => ['dangling' => true],
            ]
        );
    }

    // -----------------------------------------------------------------
    // audit trail
    // -----------------------------------------------------------------

    private function writeAuditRow(KnowledgeDocument $doc): void
    {
        if (! (bool) config('kb.canonical.audit_enabled', true)) {
            return;
        }
        KbCanonicalAudit::create([
            'project_key' => $doc->project_key,
            'doc_id' => $doc->doc_id,
            'slug' => $doc->slug,
            'event_type' => 'promoted',
            'actor' => 'canonical-indexer-job',
            'before_json' => null,
            'after_json' => [
                'canonical_type' => $doc->canonical_type,
                'canonical_status' => $doc->canonical_status,
                'retrieval_priority' => $doc->retrieval_priority,
            ],
            'metadata_json' => null,
        ]);
    }
}
