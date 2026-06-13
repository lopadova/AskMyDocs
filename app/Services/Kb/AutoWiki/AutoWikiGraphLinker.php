<?php

declare(strict_types=1);

namespace App\Services\Kb\AutoWiki;

use App\Models\KbCanonicalAudit;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use App\Support\Canonical\CanonicalType;
use App\Support\Canonical\EdgeType;
use App\Support\Canonical\GenerationSource;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * v8.11/P2 — graph canonicalization for the auto tier (AutoSci edges).
 *
 * Turns the LLM-inferred `frontmatter_json._autowiki.cross_references`
 * (produced by {@see AutoWikiCompiler}, allow-listed to real neighbours) into
 * real {@see KbEdge} rows + the {@see KbNode}s they connect, so an auto-tier
 * document becomes NAVIGABLE in the same graph the canonical layer uses.
 *
 * Enterprise scope (Lorenzo, 2026-06-13): EVERY auto document participates in
 * the graph. A graph node is keyed on `slug` (`node_uid`), so a raw document
 * that has no slug is given a stable, collision-safe, per-project slug here
 * before it is linked — the whole corpus becomes navigable, not just the
 * canonical-shaped subset.
 *
 * Boundaries that keep this safe and reversible:
 *   - Firewall: never touches a human-curated canonical doc (same rule as the
 *     compiler) — the authoritative tier keeps its human gate.
 *   - It REPLACES only its OWN edges (provenance='inferred') outgoing from the
 *     doc's slug; frontmatter/wikilink edges built by the CanonicalIndexFlow are
 *     left intact.
 *   - Auto-assigned slugs never collide with a human slug — the per-project
 *     uniqueness loop skips any taken (project_key, slug).
 *   - Every pass writes a `graph_rebuild` audit row (actor system:autowiki).
 *   - Tenant-scoped throughout (R30); slug/edge uniqueness is per (tenant,
 *     project) (R10).
 *
 * Thin layers consume this ONE core across all three surfaces (R44): the
 * `kb:wiki-link` Artisan command, the admin re-link HTTP endpoint, and the
 * `KbRebuildWikiLinksTool` MCP tool.
 *
 * KNOWN LIMITATION (pre-existing schema, not introduced here): the
 * `kb_nodes`/`kb_edges` uniques + composite FKs are keyed on `(project_key, …)`
 * only, NOT `(tenant_id, project_key, …)`. Two DIFFERENT tenants that share a
 * `project_key` therefore share graph rows. All reads here are tenant-scoped
 * (R30) so within a tenant this is correct; the residual cross-tenant overlap
 * needs a canonical-layer schema migration (tenant-scope those uniques/FKs) and
 * is tracked as a follow-up, out of scope for P2.
 */
class AutoWikiGraphLinker
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Materialise the auto cross-references of a document into graph nodes +
     * inferred edges. Idempotent: re-running replaces the inferred edge set.
     *
     * @return array{linked: bool, reason?: string, slug?: string, slug_assigned?: bool, nodes_created?: int, edges_created?: int}
     */
    public function link(KnowledgeDocument $document): array
    {
        if (! (bool) config('kb.autowiki.graph_enabled', true)) {
            return ['linked' => false, 'reason' => 'disabled'];
        }

        // Firewall — never auto-edit the human-vouched authoritative tier.
        if ((bool) $document->is_canonical
            && (string) ($document->generation_source ?? GenerationSource::Human->value) === GenerationSource::Human->value) {
            return ['linked' => false, 'reason' => 'human_curated'];
        }

        $tenantId = (string) $document->tenant_id;
        $crossReferences = $this->crossReferences($document);

        $nodesCreated = 0;
        $edgesCreated = 0;
        $slugAssigned = false;
        $slug = '';

        // One transaction for the whole pass: slug assignment + nodes + edges
        // commit or roll back together, so a mid-pass failure never leaves a
        // freshly-assigned slug stranded without its graph.
        DB::transaction(function () use ($document, $tenantId, $crossReferences, &$nodesCreated, &$edgesCreated, &$slugAssigned, &$slug): void {
            // Ensure the doc has a slug so it can be a graph node (Option 2 —
            // enterprise: the whole corpus is navigable). Assign one only when
            // missing; once set it is stable across re-compiles.
            $slugAssigned = $this->ensureSlug($document);
            $slug = (string) $document->slug;
            if ($slug === '') {
                return;
            }

            $nodesCreated += $this->upsertSelfNode($document, $tenantId, $slug);

            foreach ($crossReferences as $ref) {
                $nodesCreated += $this->ensureTargetNode($tenantId, (string) $document->project_key, $ref['slug']);
            }

            // Replace only OUR inferred edge set; leave frontmatter/wikilink
            // edges (built by CanonicalIndexFlow) untouched.
            $this->replaceInferredEdges($tenantId, (string) $document->project_key, $slug);

            foreach ($crossReferences as $ref) {
                $edgesCreated += $this->createInferredEdge($document, $tenantId, $slug, $ref);
            }
        });

        if ($slug === '') {
            return ['linked' => false, 'reason' => 'no_slug'];
        }

        $this->writeAudit($document, $slug, $nodesCreated, $edgesCreated, $slugAssigned);

        return [
            'linked' => true,
            'slug' => $slug,
            'slug_assigned' => $slugAssigned,
            'nodes_created' => $nodesCreated,
            'edges_created' => $edgesCreated,
        ];
    }

    /**
     * Assign a stable, collision-safe, per-project slug when the document has
     * none. Returns true when a slug was newly assigned. The slug is persisted
     * + flagged in `_autowiki.slug_auto_assigned` so the provenance is auditable
     * and the value is reused (not re-derived) on the next pass.
     */
    private function ensureSlug(KnowledgeDocument $document): bool
    {
        $current = trim((string) ($document->slug ?? ''));
        if ($current !== '') {
            return false;
        }

        $slug = $this->deriveUniqueSlug($document);

        $frontmatter = is_array($document->frontmatter_json) ? $document->frontmatter_json : [];
        $autowiki = is_array($frontmatter['_autowiki'] ?? null) ? $frontmatter['_autowiki'] : [];
        $autowiki['slug_auto_assigned'] = true;
        $frontmatter['_autowiki'] = $autowiki;

        $document->forceFill([
            'slug' => $slug,
            'frontmatter_json' => $frontmatter,
        ])->save();

        return true;
    }

    /**
     * Derive a unique slug within the document's (tenant, project).
     *
     * Auto-assigned slugs are NAMESPACED with an `auto-` prefix so they never
     * squat on the HUMAN canonical slug namespace: a human canonical doc
     * declares a clean slug in its frontmatter, and if an auto doc had grabbed
     * that same title-derived slug, the human doc's ingest would fail the
     * `uq_kb_doc_slug (project_key, slug)` unique. The prefix also means an
     * `auto-*` graph node can only pre-exist when this very doc already owned
     * the slug, so {@see upsertSelfNode()} never hijacks a node it didn't make.
     *
     * Base is `auto-` + the slugified title (falling back to `auto-doc-{id}`);
     * a numeric suffix is appended until the (project_key, slug) pair is free,
     * checked against BOTH `knowledge_documents` (incl. soft-deleted) AND the
     * `kb_nodes.node_uid` namespace where the slug is also used as the key.
     */
    private function deriveUniqueSlug(KnowledgeDocument $document): string
    {
        $titleSlug = Str::slug((string) ($document->title ?? ''));
        $base = $titleSlug !== '' ? 'auto-'.$titleSlug : 'auto-doc-'.(int) $document->id;
        // Bound the length so the suffix always fits inside the 255-char column.
        $base = Str::limit($base, 200, '');

        $candidate = $base;
        $suffix = 2;
        while ($this->slugTaken($document, $candidate)) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * A slug is taken if another document holds it (incl. soft-deleted, so a
     * restore can't resurrect a collision) OR a kb_node owned by a DIFFERENT
     * document already uses it as node_uid. A dangling node (source_doc_id null)
     * does NOT count as taken — {@see upsertSelfNode()} legitimately resolves it
     * to this doc.
     */
    private function slugTaken(KnowledgeDocument $document, string $slug): bool
    {
        $docTaken = KnowledgeDocument::query()
            ->withTrashed()
            ->forTenant((string) $document->tenant_id)
            ->where('project_key', (string) $document->project_key)
            ->where('slug', $slug)
            ->whereKeyNot($document->id)
            ->exists();
        if ($docTaken) {
            return true;
        }

        return KbNode::query()
            ->forTenant((string) $document->tenant_id)
            ->where('project_key', (string) $document->project_key)
            ->where('node_uid', $slug)
            ->whereNotNull('source_doc_id')
            ->where('source_doc_id', '!=', (string) ($document->doc_id ?? ''))
            ->exists();
    }

    private function upsertSelfNode(KnowledgeDocument $document, string $tenantId, string $slug): int
    {
        $type = CanonicalType::tryFrom((string) $document->canonical_type);
        $nodeType = $type !== null ? $type->nodeType() : 'domain-concept';

        $payload = [
            'dangling' => false,
            'generation_source' => GenerationSource::Auto->value,
            'canonical_status' => $document->canonical_status,
        ];

        $existing = KbNode::query()->forTenant($tenantId)
            ->where('project_key', $document->project_key)
            ->where('node_uid', $slug)
            ->first();

        if ($existing !== null) {
            $existing->update([
                'node_type' => $nodeType,
                'label' => (string) ($document->title ?? $slug),
                'source_doc_id' => $document->doc_id,
                'payload_json' => $payload,
            ]);

            return 0;
        }

        KbNode::create([
            'project_key' => (string) $document->project_key,
            'node_uid' => $slug,
            'node_type' => $nodeType,
            'label' => (string) ($document->title ?? $slug),
            'source_doc_id' => $document->doc_id,
            'payload_json' => $payload,
        ]);

        return 1;
    }

    private function ensureTargetNode(string $tenantId, string $projectKey, string $slug): int
    {
        $node = KbNode::query()->forTenant($tenantId)->firstOrCreate(
            [
                'project_key' => $projectKey,
                'node_uid' => $slug,
            ],
            [
                'node_type' => 'unknown',
                'label' => $slug,
                'source_doc_id' => null,
                'payload_json' => ['dangling' => true],
            ],
        );

        return $node->wasRecentlyCreated ? 1 : 0;
    }

    private function replaceInferredEdges(string $tenantId, string $projectKey, string $slug): void
    {
        KbEdge::query()->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->where('from_node_uid', $slug)
            ->where('provenance', 'inferred')
            ->delete();
    }

    /**
     * @param  array{slug: string, edge_type: string}  $ref
     */
    private function createInferredEdge(KnowledgeDocument $document, string $tenantId, string $slug, array $ref): int
    {
        $target = $ref['slug'];
        if ($target === '' || $target === $slug) {
            return 0;
        }

        $edgeType = EdgeType::tryFrom($ref['edge_type']) ?? EdgeType::RelatedTo;

        $edge = KbEdge::query()->forTenant($tenantId)->updateOrCreate(
            [
                'project_key' => (string) $document->project_key,
                'edge_uid' => "{$slug}->{$target}:{$edgeType->value}",
            ],
            [
                'tenant_id' => $tenantId,
                'from_node_uid' => $slug,
                'to_node_uid' => $target,
                'edge_type' => $edgeType->value,
                'source_doc_id' => $document->doc_id,
                'weight' => $edgeType->defaultWeight(),
                'provenance' => 'inferred',
                'payload_json' => null,
            ],
        );

        return $edge->wasRecentlyCreated ? 1 : 0;
    }

    private function writeAudit(KnowledgeDocument $document, string $slug, int $nodesCreated, int $edgesCreated, bool $slugAssigned): void
    {
        if (! (bool) config('kb.canonical.audit_enabled', true)) {
            return;
        }

        KbCanonicalAudit::create([
            'tenant_id' => (string) $document->tenant_id,
            'project_key' => (string) $document->project_key,
            'doc_id' => $document->doc_id,
            'slug' => $slug,
            'event_type' => 'graph_rebuild',
            'actor' => 'system:autowiki',
            'after_json' => [
                'nodes_created' => $nodesCreated,
                'edges_created' => $edgesCreated,
                'slug_auto_assigned' => $slugAssigned,
            ],
            'metadata_json' => ['source' => 'autowiki_graph_linker'],
        ]);
    }

    /**
     * The allow-listed cross-references the compiler stored. Each entry is a
     * normalised {slug, edge_type}; non-array / malformed payloads degrade to
     * an empty list so a corrupted `_autowiki` can never crash linking.
     *
     * @return list<array{slug: string, edge_type: string}>
     */
    private function crossReferences(KnowledgeDocument $document): array
    {
        $frontmatter = is_array($document->frontmatter_json) ? $document->frontmatter_json : [];
        $autowiki = is_array($frontmatter['_autowiki'] ?? null) ? $frontmatter['_autowiki'] : [];
        $refs = $autowiki['cross_references'] ?? null;
        if (! is_array($refs)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($refs as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $slug = is_string($ref['slug'] ?? null) ? trim($ref['slug']) : '';
            if ($slug === '') {
                continue;
            }
            $edgeType = is_string($ref['edge_type'] ?? null) ? trim($ref['edge_type']) : 'related_to';
            // Dedupe by (slug, edge_type): the edge_uid is keyed on this pair, so
            // a duplicate would just updateOrCreate the same row — keep the count
            // + audit honest by collapsing it here.
            $key = $slug.'|'.$edgeType;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['slug' => $slug, 'edge_type' => $edgeType];
        }

        return $out;
    }
}
