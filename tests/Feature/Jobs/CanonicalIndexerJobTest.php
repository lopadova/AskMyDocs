<?php

namespace Tests\Feature\Jobs;

use App\Jobs\CanonicalIndexerJob;
use App\Models\KbCanonicalAudit;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalIndexerJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_upserts_self_node_for_a_canonical_decision(): void
    {
        $doc = $this->seedCanonicalDoc('acme', 'dec-cache-v2', 'decision', 'Cache invalidation v2');

        (new CanonicalIndexerJob($doc->id))->handle();

        $this->assertDatabaseHas('kb_nodes', [
            'node_uid' => 'dec-cache-v2',
            'node_type' => 'decision',
            'project_key' => 'acme',
            'label' => 'Cache invalidation v2',
            'source_doc_id' => $doc->doc_id,
        ]);
    }

    public function test_creates_edges_for_frontmatter_related_slugs(): void
    {
        $doc = $this->seedCanonicalDoc('acme', 'dec-cache-v2', 'decision', 'Cache v2', [
            '_derived' => ['related_slugs' => ['module-cache', 'runbook-purge'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]);

        (new CanonicalIndexerJob($doc->id))->handle();

        $this->assertDatabaseHas('kb_edges', [
            'from_node_uid' => 'dec-cache-v2',
            'to_node_uid' => 'module-cache',
            'edge_type' => 'related_to',
            'project_key' => 'acme',
            'provenance' => 'frontmatter_related',
        ]);
        $this->assertDatabaseHas('kb_edges', [
            'from_node_uid' => 'dec-cache-v2',
            'to_node_uid' => 'runbook-purge',
            'edge_type' => 'related_to',
            'project_key' => 'acme',
        ]);
    }

    public function test_creates_edges_for_wikilinks_in_chunks(): void
    {
        $doc = $this->seedCanonicalDoc('acme', 'dec-x', 'decision', 'X');
        $this->seedChunk($doc, 0, 'See [[module-a]].', ['module-a']);
        $this->seedChunk($doc, 1, 'Also [[module-b]] and [[module-c]].', ['module-b', 'module-c']);

        (new CanonicalIndexerJob($doc->id))->handle();

        foreach (['module-a', 'module-b', 'module-c'] as $target) {
            $this->assertDatabaseHas('kb_edges', [
                'from_node_uid' => 'dec-x',
                'to_node_uid' => $target,
                'provenance' => 'wikilink',
                'project_key' => 'acme',
            ]);
        }
    }

    public function test_creates_dangling_target_nodes_for_unknown_slugs(): void
    {
        $doc = $this->seedCanonicalDoc('acme', 'dec-x', 'decision', 'X', [
            '_derived' => ['related_slugs' => ['not-yet-canonicalized'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]);

        (new CanonicalIndexerJob($doc->id))->handle();

        $target = KbNode::where('node_uid', 'not-yet-canonicalized')->where('project_key', 'acme')->first();
        $this->assertNotNull($target);
        $this->assertTrue($target->payload_json['dangling'] ?? false);
    }

    public function test_preserves_existing_canonicalized_target_node(): void
    {
        // Pre-existing canonical node in the same project — indexer must NOT
        // mark it as dangling (would overwrite real metadata).
        KbNode::create([
            'node_uid' => 'module-cache',
            'node_type' => 'module',
            'label' => 'Existing module',
            'project_key' => 'acme',
            'source_doc_id' => 'MOD-0001',
            'payload_json' => ['dangling' => false],
        ]);

        $doc = $this->seedCanonicalDoc('acme', 'dec-x', 'decision', 'X', [
            '_derived' => ['related_slugs' => ['module-cache'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]);

        (new CanonicalIndexerJob($doc->id))->handle();

        $existing = KbNode::where('node_uid', 'module-cache')->first();
        $this->assertSame('Existing module', $existing->label);
        $this->assertSame('MOD-0001', $existing->source_doc_id);
        $this->assertFalse($existing->payload_json['dangling']);
    }

    public function test_replaces_previous_edges_on_reindex(): void
    {
        // First run: edges to A and B.
        $doc = $this->seedCanonicalDoc('acme', 'dec-x', 'decision', 'X', [
            '_derived' => ['related_slugs' => ['a', 'b'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]);
        (new CanonicalIndexerJob($doc->id))->handle();
        $this->assertSame(2, KbEdge::where('from_node_uid', 'dec-x')->count());

        // E.2 — the job dispatches with idempotencyKey
        // `canonical-index:{tenantId}:{documentId}`, so re-dispatching the
        // SAME doc id under the SAME tenant short-circuits at the engine
        // level (existing FlowRun returned, no re-execution). This is the
        // intended dedup behaviour for the production path: each new
        // version of the canonical doc lands as a new KnowledgeDocument
        // row (= new id, = new idempotency key) and re-indexes
        // automatically. Mutating frontmatter_json in place on the same
        // row, then re-dispatching the same id, would NOT re-execute —
        // and that's correct.
        //
        // To exercise the inner step's edge-replace logic against the
        // same row, we must therefore drive the CanonicalIndexFlow
        // directly (the engine bypasses the idempotency check when the
        // key is null) instead of going through the job wrapper.
        $fresh = KnowledgeDocument::find($doc->id);
        $fresh->frontmatter_json = ['_derived' => ['related_slugs' => ['a', 'c'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []]];
        $fresh->save();

        \Padosoft\LaravelFlow\Facades\Flow::execute(
            \App\Flow\Definitions\CanonicalIndexFlow::NAME,
            ['tenant_id' => 'default', 'document_id' => $doc->id],
            \Padosoft\LaravelFlow\FlowExecutionOptions::make(correlationId: 'default'),
        );

        $targets = KbEdge::where('from_node_uid', 'dec-x')->pluck('to_node_uid')->all();
        sort($targets);
        $this->assertSame(['a', 'c'], $targets);
    }

    public function test_re_dispatching_same_document_id_short_circuits_via_idempotency_key(): void
    {
        // E.2 — the job's idempotencyKey is
        // `canonical-index:{tenantId}:{documentId}`. Re-dispatching the
        // SAME doc id under the SAME tenant must return the existing
        // FlowRun row instead of re-executing, mirroring
        // IngestDocumentJob's pattern. Concurrent re-dispatches are
        // therefore deduped at the engine level.
        $doc = $this->seedCanonicalDoc('acme', 'dec-y', 'decision', 'Y', [
            '_derived' => ['related_slugs' => ['target-1'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]);

        (new CanonicalIndexerJob($doc->id))->handle();
        $auditCount1 = KbCanonicalAudit::where('slug', 'dec-y')->count();
        $this->assertSame(1, $auditCount1);

        // Second dispatch under same (tenant, document_id): engine should
        // return the existing FlowRun without re-executing the saga; the
        // graph_rebuild audit row count must stay at 1.
        (new CanonicalIndexerJob($doc->id))->handle();
        $auditCount2 = KbCanonicalAudit::where('slug', 'dec-y')->count();
        $this->assertSame(1, $auditCount2, 'idempotencyKey must dedupe re-dispatches; audit row should not double');
    }

    public function test_supersedes_generates_typed_edge(): void
    {
        $doc = $this->seedCanonicalDoc('acme', 'dec-v2', 'decision', 'V2', [
            '_derived' => ['related_slugs' => [], 'supersedes_slugs' => ['dec-v1'], 'superseded_by_slugs' => []],
        ]);

        (new CanonicalIndexerJob($doc->id))->handle();

        $this->assertDatabaseHas('kb_edges', [
            'from_node_uid' => 'dec-v2',
            'to_node_uid' => 'dec-v1',
            'edge_type' => 'supersedes',
            'provenance' => 'frontmatter_supersedes',
        ]);
    }

    public function test_writes_audit_row_on_success(): void
    {
        // v4.2/W2 PR #116 — the canonical indexer is now Flow-orchestrated
        // and the authoritative audit event for a (re)indexing pass is
        // `graph_rebuild` (was the misnamed `promoted` in the legacy job).
        $doc = $this->seedCanonicalDoc('acme', 'dec-x', 'decision', 'X');
        (new CanonicalIndexerJob($doc->id))->handle();

        $this->assertDatabaseHas('kb_canonical_audit', [
            'project_key' => 'acme',
            'doc_id' => $doc->doc_id,
            'slug' => 'dec-x',
            'event_type' => 'graph_rebuild',
        ]);
    }

    public function test_no_op_when_document_is_archived(): void
    {
        // Regression for Copilot PR #10 comment: an archived row must not
        // rebuild the graph from stale content.
        $doc = $this->seedCanonicalDoc('acme', 'dec-x', 'decision', 'X', [
            '_derived' => ['related_slugs' => ['m1'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]);
        $doc->update(['status' => 'archived']);

        (new CanonicalIndexerJob($doc->id))->handle();

        $this->assertSame(0, KbNode::count());
        $this->assertSame(0, KbEdge::count());
        $this->assertSame(0, KbCanonicalAudit::count());
    }

    public function test_ensureTargetNode_is_race_safe_under_concurrent_runs(): void
    {
        // Simulate two workers that both tried to create the same target
        // node. The second call must NOT raise — firstOrCreate short-circuits
        // on the composite unique.
        $docA = $this->seedCanonicalDoc('acme', 'dec-a', 'decision', 'A', [
            '_derived' => ['related_slugs' => ['shared-target'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]);
        $docB = $this->seedCanonicalDoc('acme', 'dec-b', 'decision', 'B', [
            '_derived' => ['related_slugs' => ['shared-target'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]);

        (new CanonicalIndexerJob($docA->id))->handle();
        (new CanonicalIndexerJob($docB->id))->handle();

        $this->assertSame(1, KbNode::where('node_uid', 'shared-target')->count());
        // Both edges reach the same target.
        $this->assertSame(2, KbEdge::where('to_node_uid', 'shared-target')->count());
    }

    public function test_no_op_for_non_canonical_document(): void
    {
        $doc = KnowledgeDocument::create([
            'project_key' => 'acme',
            'source_type' => 'markdown',
            'title' => 'Plain doc',
            'source_path' => 'docs/plain.md',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_repeat('z', 64),
            'version_hash' => str_repeat('z', 64),
            'is_canonical' => false,
        ]);

        (new CanonicalIndexerJob($doc->id))->handle();

        $this->assertSame(0, KbNode::count());
        $this->assertSame(0, KbEdge::count());
        $this->assertSame(0, KbCanonicalAudit::count());
    }

    public function test_idempotency_key_is_same_for_unchanged_content(): void
    {
        // Iter5 (PR #116) — same (tenant, document_id, version_hash) MUST
        // produce the same engine-level idempotency key. This is what
        // makes the regular ingest path safe under concurrent re-dispatch.
        $doc = $this->seedCanonicalDoc('acme', 'dec-key-1', 'decision', 'K1');

        $jobA = new CanonicalIndexerJob($doc->id);
        $jobB = new CanonicalIndexerJob($doc->id);

        $this->assertSame($jobA->buildIdempotencyKey(), $jobB->buildIdempotencyKey());
        $this->assertStringContainsString((string) $doc->id, $jobA->buildIdempotencyKey());
        $this->assertStringContainsString($doc->version_hash, $jobA->buildIdempotencyKey());
    }

    public function test_idempotency_key_changes_when_version_hash_changes(): void
    {
        // Iter5 (PR #116) — Copilot finding: a key based ONLY on
        // (tenant, document_id) would not change after re-ingest with
        // new content, breaking re-execution after schema/content
        // updates. With version_hash in the key, a new version => a new
        // key => natural re-execution.
        $doc = $this->seedCanonicalDoc('acme', 'dec-key-2', 'decision', 'K2');
        $keyV1 = (new CanonicalIndexerJob($doc->id))->buildIdempotencyKey();

        // Mutate version_hash (simulates content re-ingest landing as a
        // new version on the same row id).
        $doc->update(['version_hash' => str_repeat('e', 64)]);
        $keyV2 = (new CanonicalIndexerJob($doc->id))->buildIdempotencyKey();

        $this->assertNotSame($keyV1, $keyV2, 'Different version_hash MUST yield different idempotency keys');
    }

    public function test_force_reindex_bypasses_idempotency_with_nonce(): void
    {
        // Iter5 (PR #116) — Copilot finding: kb:rebuild-graph after a
        // graph truncate must be able to FORCE re-execution against
        // unchanged content. The forceReindex flag salts the key with
        // a unix-millis nonce so the engine sees a fresh key every
        // time. Two consecutive forceReindex jobs for the same doc
        // produce DIFFERENT keys — proving the engine cannot dedup them.
        $doc = $this->seedCanonicalDoc('acme', 'dec-key-3', 'decision', 'K3');

        $base = (new CanonicalIndexerJob($doc->id, 'default', false))->buildIdempotencyKey();
        $forced1 = (new CanonicalIndexerJob($doc->id, 'default', true))->buildIdempotencyKey();
        // hrtime() returns monotonic nanoseconds; even back-to-back calls produce a fresh value.
        $forced2 = (new CanonicalIndexerJob($doc->id, 'default', true))->buildIdempotencyKey();

        $this->assertNotSame($base, $forced1, 'forceReindex key MUST differ from default key');
        $this->assertNotSame($forced1, $forced2, 'two forced rebuilds MUST produce distinct keys');
        $this->assertStringContainsString(':rebuild-', $forced1);
        $this->assertStringContainsString(':rebuild-', $forced2);
    }

    public function test_force_reindex_actually_re_executes_after_truncate(): void
    {
        // Iter5 (PR #116) — END-TO-END proof. Without forceReindex,
        // re-running CanonicalIndexerJob after a graph truncate would
        // hit the engine cache and short-circuit, leaving kb_nodes empty.
        // With forceReindex, the indexer re-runs against the same row
        // and re-populates kb_nodes/kb_edges from frontmatter_json.
        $doc = $this->seedCanonicalDoc('acme', 'dec-rebuild-x', 'decision', 'X', [
            '_derived' => ['related_slugs' => ['mod-1', 'mod-2'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]);

        // First indexer run populates the graph.
        (new CanonicalIndexerJob($doc->id))->handle();
        $this->assertSame(2, KbEdge::where('from_node_uid', 'dec-rebuild-x')->count());

        // Operator-driven truncate (mirrors what kb:rebuild-graph does).
        KbEdge::query()->delete();
        KbNode::query()->delete();
        $this->assertSame(0, KbNode::count());

        // A NON-forced re-dispatch would short-circuit (engine cache
        // returns the prior FlowRun). The forced re-dispatch MUST
        // re-execute and re-populate the graph.
        (new CanonicalIndexerJob($doc->id, 'default', true))->handle();

        $targets = KbEdge::where('from_node_uid', 'dec-rebuild-x')->pluck('to_node_uid')->all();
        sort($targets);
        $this->assertSame(['mod-1', 'mod-2'], $targets, 'forceReindex must re-populate kb_edges after truncate');
    }

    public function test_multi_tenant_isolation(): void
    {
        // Same slug in two projects, each with their own target wikilink.
        $docA = $this->seedCanonicalDoc('acme', 'dec-x', 'decision', 'X-acme', [
            '_derived' => ['related_slugs' => ['mod-a'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]);
        $docB = $this->seedCanonicalDoc('beta', 'dec-x', 'decision', 'X-beta', [
            '_derived' => ['related_slugs' => ['mod-b'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
        ]);

        (new CanonicalIndexerJob($docA->id))->handle();
        (new CanonicalIndexerJob($docB->id))->handle();

        // Self nodes coexist in both projects.
        $this->assertSame(2, KbNode::where('node_uid', 'dec-x')->count());
        // Targets are tenant-isolated.
        $this->assertSame(1, KbNode::where('node_uid', 'mod-a')->count());
        $this->assertSame(1, KbNode::where('node_uid', 'mod-b')->count());
        // Edges never cross tenants.
        $acmeEdges = KbEdge::where('project_key', 'acme')->pluck('to_node_uid')->all();
        $betaEdges = KbEdge::where('project_key', 'beta')->pluck('to_node_uid')->all();
        $this->assertSame(['mod-a'], $acmeEdges);
        $this->assertSame(['mod-b'], $betaEdges);
    }

    // -------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------

    private function seedCanonicalDoc(
        string $projectKey,
        string $slug,
        string $type,
        string $title,
        array $frontmatterJson = []
    ): KnowledgeDocument {
        static $counter = 0;
        $counter++;
        return KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => $title,
            'source_path' => "{$type}s/{$slug}.md",
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_pad((string) $counter, 64, 'a'),
            'version_hash' => str_pad((string) $counter, 64, 'b'),
            'doc_id' => strtoupper(substr($type, 0, 3)) . '-' . str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
            'slug' => $slug,
            'canonical_type' => $type,
            'canonical_status' => 'accepted',
            'is_canonical' => true,
            'retrieval_priority' => 70,
            'frontmatter_json' => $frontmatterJson,
        ]);
    }

    private function seedChunk(KnowledgeDocument $doc, int $order, string $text, array $wikilinks): void
    {
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => $doc->project_key,
            'chunk_order' => $order,
            'chunk_hash' => hash('sha256', $text . $order),
            'heading_path' => null,
            'chunk_text' => $text,
            'metadata' => ['wikilinks' => $wikilinks, 'strategy' => 'section_aware'],
            'embedding' => array_fill(0, 1536, 0.0),
        ]);
    }
}
