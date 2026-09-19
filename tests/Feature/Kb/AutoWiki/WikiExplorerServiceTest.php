<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Models\KbCanonicalAudit;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\WikiExplorerService;
use App\Support\Canonical\GenerationSource;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v8.11/P10 — the shared core behind the Wiki Explorer: tier-filtered listing
 * with edge counts, promote (auto→human), and discard (soft-delete) — both
 * writes audited, reversible, tenant-scoped (R30), and firewalled to auto pages.
 */
final class WikiExplorerServiceTest extends TestCase
{
    use RefreshDatabase;

    private WikiExplorerService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(WikiExplorerService::class);
        app(TenantContext::class)->set('default');
    }

    private function doc(array $over = []): KnowledgeDocument
    {
        return KnowledgeDocument::create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'eng',
            'source_type' => 'markdown',
            'source_path' => 'decisions/'.($over['slug'] ?? 'dec-x').'.md',
            'title' => 'Doc',
            'mime_type' => 'text/markdown',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => bin2hex(random_bytes(16)),
            'is_canonical' => true,
            'doc_id' => $over['slug'] ?? 'dec-x',
            'slug' => $over['slug'] ?? 'dec-x',
            'canonical_type' => 'decision',
            'canonical_status' => 'accepted',
            'generation_source' => GenerationSource::Human->value,
        ], $over));
    }

    public function test_list_filters_by_tier(): void
    {
        $this->doc(['slug' => 'human-a', 'generation_source' => 'human']);
        $this->doc(['slug' => 'auto-a', 'generation_source' => 'auto']);

        $all = $this->svc->list('default', 'eng', 'all');
        $this->assertSame(2, $all['total']);

        $auto = $this->svc->list('default', 'eng', 'auto');
        $this->assertSame(1, $auto['total']);
        $this->assertSame('auto-a', $auto['pages'][0]['slug']);

        $human = $this->svc->list('default', 'eng', 'human');
        $this->assertSame(1, $human['total']);
        $this->assertSame('human-a', $human['pages'][0]['slug']);
    }

    public function test_list_reports_edge_counts(): void
    {
        $this->doc(['slug' => 'auto-a', 'generation_source' => 'auto']);
        KbNode::create(['tenant_id' => 'default', 'project_key' => 'eng', 'node_uid' => 'auto-a', 'node_type' => 'decision', 'label' => 'A']);
        KbNode::create(['tenant_id' => 'default', 'project_key' => 'eng', 'node_uid' => 'other', 'node_type' => 'decision', 'label' => 'O']);
        KbEdge::create(['tenant_id' => 'default', 'project_key' => 'eng', 'edge_uid' => 'auto-a->other:related_to', 'from_node_uid' => 'auto-a', 'to_node_uid' => 'other', 'edge_type' => 'related_to', 'weight' => 1, 'provenance' => 'inferred']);
        KbEdge::create(['tenant_id' => 'default', 'project_key' => 'eng', 'edge_uid' => 'other->auto-a:related_to', 'from_node_uid' => 'other', 'to_node_uid' => 'auto-a', 'edge_type' => 'related_to', 'weight' => 1, 'provenance' => 'inferred']);

        $page = $this->svc->list('default', 'eng', 'auto')['pages'][0];
        $this->assertSame(1, $page['outgoing_edges']);
        $this->assertSame(1, $page['backlinks']);
    }

    public function test_promote_flips_auto_to_human_and_audits(): void
    {
        $doc = $this->doc(['slug' => 'auto-a', 'generation_source' => 'auto', 'canonical_status' => 'review']);

        $result = $this->svc->promote($doc, 'admin:1');

        $this->assertTrue($result['promoted']);
        $doc->refresh();
        $this->assertSame('human', $doc->generation_source);
        $this->assertSame('accepted', $doc->canonical_status);
        $this->assertDatabaseHas('kb_canonical_audit', [
            'tenant_id' => 'default', 'slug' => 'auto-a', 'event_type' => 'promoted', 'actor' => 'admin:1',
        ]);
    }

    public function test_promote_refuses_a_human_doc(): void
    {
        $doc = $this->doc(['slug' => 'human-a', 'generation_source' => 'human']);

        $result = $this->svc->promote($doc, 'admin:1');

        $this->assertFalse($result['promoted']);
        $this->assertSame('not_auto', $result['reason']);
        $doc->refresh();
        $this->assertSame('human', $doc->generation_source);
    }

    /**
     * Copilot PR #494 round 6 (must-fix) — the numeric-ID HTTP/CLI Wiki
     * Explorer adapters resolve a tenant-scoped document WITHOUT requiring
     * `is_canonical`, so an auto OCR'd scan or AutoWiki-enriched raw
     * document could reach this method and receive a FALSE canonical fact
     * (`canonical_status: 'accepted'`) it was never meant to carry. A
     * non-canonical `auto` document must refuse, exactly like the
     * `not_auto` case, and leave BOTH columns untouched.
     */
    public function test_promote_refuses_a_non_canonical_auto_doc(): void
    {
        $doc = $this->doc([
            'slug' => null,
            'doc_id' => null,
            'canonical_type' => null,
            'canonical_status' => null,
            'is_canonical' => false,
            'generation_source' => 'auto',
        ]);

        $result = $this->svc->promote($doc, 'admin:1');

        $this->assertFalse($result['promoted']);
        $this->assertSame('not_canonical', $result['reason']);
        $doc->refresh();
        $this->assertSame('auto', $doc->generation_source, 'a non-canonical doc must never be silently flipped to human');
        $this->assertNull($doc->canonical_status, 'a non-canonical doc must never receive a false canonical_status');
        // a refused promotion must never write an audit row
        $this->assertDatabaseCount('kb_canonical_audit', 0);
    }

    /**
     * Copilot PR #494 round 5 (must-fix) — save() returns false when a model
     * event vetoes the write. Before this fix, the ignored return value let
     * the transaction still write the 'promoted' audit row and this method
     * return promoted=true while generation_source/canonical_status stayed
     * untouched on disk. A `saving` listener scoped to THIS document's id
     * simulates the veto; the whole transaction must roll back, so neither
     * the flip nor its audit row survives.
     */
    public function test_promote_throws_and_writes_nothing_when_the_save_is_vetoed(): void
    {
        $doc = $this->doc(['slug' => 'auto-a', 'generation_source' => 'auto', 'canonical_status' => 'review']);

        KnowledgeDocument::saving(fn (KnowledgeDocument $model): bool => $model->getKey() !== $doc->id);

        $thrown = null;

        try {
            try {
                $this->svc->promote($doc, 'admin:1');
            } catch (\RuntimeException $e) {
                $thrown = $e;
            }
        } finally {
            \Illuminate\Support\Facades\Event::forget('eloquent.saving: '.KnowledgeDocument::class);
        }

        $this->assertNotNull($thrown, 'a vetoed save must surface as a thrown exception, not a silent promoted:true');
        $doc->refresh();
        $this->assertSame('auto', $doc->generation_source, 'a vetoed save must leave generation_source untouched');
        $this->assertSame('review', $doc->canonical_status, 'a vetoed save must leave canonical_status untouched');
        $this->assertDatabaseCount('kb_canonical_audit', 0);
    }

    /**
     * Copilot PR #494 round 9 (must-fix, 2 findings) — promote() now locks
     * the document row INSIDE its transaction and re-reads
     * is_canonical/generation_source from that locked row rather than the
     * CALLER'S copy. Mirrors
     * `KbReviewServiceTest::test_approve_decides_from_a_fresh_read_not_the_callers_stale_document_instance`
     * (SQLite cannot enforce real `lockForUpdate()` blocking, so a true
     * two-connection interleaving isn't stageable here — see that test's
     * docblock for the full rationale): simulate "a concurrent promote
     * already committed" by updating the DB directly, bypassing the `$doc`
     * instance in memory (which still reports the stale 'auto' value), and
     * writing that winner's audit row. Pre-fix, promote() decided from
     * `$doc->generation_source` (the stale in-memory 'auto') and would
     * flip the row AGAIN, producing a second `promoted` audit row for
     * exactly the race the direct Wiki Explorer HTTP/CLI/MCP adapters and
     * `KbReviewService::approve()`'s canonical branch can hit concurrently.
     * Post-fix, promote() re-queries inside its own transaction, sees the
     * DB's 'human', and returns the safe not_auto no-op.
     */
    public function test_promote_decides_from_a_fresh_read_not_the_callers_stale_document_instance(): void
    {
        $doc = $this->doc(['slug' => 'auto-a', 'generation_source' => 'auto', 'canonical_status' => 'review']);

        // A concurrent promote() call that already committed, bypassing
        // the in-memory $doc instance entirely.
        \Illuminate\Support\Facades\DB::table('knowledge_documents')
            ->where('id', $doc->id)
            ->update(['generation_source' => GenerationSource::Human->value, 'canonical_status' => 'accepted']);
        KbCanonicalAudit::create([
            'tenant_id' => 'default',
            'project_key' => (string) $doc->project_key,
            'doc_id' => $doc->doc_id,
            'slug' => $doc->slug,
            'event_type' => 'promoted',
            'actor' => 'admin:2',
            'before_json' => ['generation_source' => 'auto', 'canonical_status' => 'review'],
            'after_json' => ['generation_source' => 'human', 'canonical_status' => 'accepted'],
            'metadata_json' => ['source' => 'concurrent_winner'],
        ]);

        $this->assertSame('auto', $doc->generation_source, 'the in-memory $doc instance must still report the stale value — the concurrent write bypassed it');

        $result = $this->svc->promote($doc, 'admin:1');

        $this->assertFalse($result['promoted'], 'a fresh read must see the concurrent winner\'s human state');
        $this->assertSame('not_auto', $result['reason']);
        // exactly the concurrent winner's audit row must exist, never a second one
        $this->assertDatabaseCount('kb_canonical_audit', 1);
    }

    public function test_discard_soft_deletes_an_auto_doc_and_audits(): void
    {
        $doc = $this->doc(['slug' => 'auto-a', 'generation_source' => 'auto']);

        $result = $this->svc->discard($doc, 'admin:1');

        $this->assertTrue($result['discarded']);
        $this->assertSoftDeleted('knowledge_documents', ['id' => $doc->id]);
        $this->assertDatabaseHas('kb_canonical_audit', [
            'tenant_id' => 'default', 'slug' => 'auto-a', 'event_type' => 'deprecated', 'actor' => 'admin:1',
        ]);
    }

    public function test_discard_refuses_a_human_doc(): void
    {
        $doc = $this->doc(['slug' => 'human-a', 'generation_source' => 'human']);

        $result = $this->svc->discard($doc, 'admin:1');

        $this->assertFalse($result['discarded']);
        $this->assertSame('not_auto', $result['reason']);
        $this->assertDatabaseHas('knowledge_documents', ['id' => $doc->id, 'deleted_at' => null]);
    }

    public function test_list_is_tenant_scoped(): void
    {
        $this->doc(['slug' => 'auto-a', 'generation_source' => 'auto']);
        // A different tenant's auto page must not leak.
        KnowledgeDocument::create([
            'tenant_id' => 'other', 'project_key' => 'eng', 'source_type' => 'markdown',
            'source_path' => 'decisions/leak.md', 'title' => 'Leak', 'mime_type' => 'text/markdown',
            'status' => 'active', 'document_hash' => str_repeat('b', 64), 'version_hash' => bin2hex(random_bytes(16)),
            'is_canonical' => true, 'doc_id' => 'leak', 'slug' => 'leak', 'canonical_type' => 'decision',
            'canonical_status' => 'accepted', 'generation_source' => 'auto',
        ]);

        $page = $this->svc->list('default', null, 'all');
        $this->assertSame(1, $page['total']);
        $this->assertSame('auto-a', $page['pages'][0]['slug']);
    }
}
