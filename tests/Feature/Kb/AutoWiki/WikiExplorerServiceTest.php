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
