<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Retrieval;

use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Retrieval\RelatedGraphService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v8.8/W6 — `RelatedGraphService`: 1-hop kb_edges neighbours of cited
 * canonical docs (both directions), tenant + project scoped (R30),
 * config-gated, empty when no canonical graph exists.
 */
final class RelatedGraphServiceTest extends TestCase
{
    use RefreshDatabase;

    private RelatedGraphService $service;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        $this->service = app(RelatedGraphService::class);
        config()->set('kb.graph.expansion_enabled', true);
    }

    private function seedDoc(string $slug, string $title, string $project = 'eng', string $tenant = 'default'): void
    {
        KnowledgeDocument::create([
            'tenant_id' => $tenant,
            'project_key' => $project,
            'source_type' => 'markdown',
            'title' => $title,
            'source_path' => "decisions/$slug.md",
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', $tenant.$slug),
            'version_hash' => hash('sha256', $tenant.$slug.'v'),
            'doc_id' => strtoupper($slug),
            'slug' => $slug,
            'canonical_type' => 'decision',
            'canonical_status' => 'accepted',
            'is_canonical' => true,
        ]);

        // The kb_edges composite FK references kb_nodes(project_key, node_uid),
        // so every edge endpoint needs its node row (R10).
        KbNode::create([
            'tenant_id' => $tenant,
            'node_uid' => $slug,
            'node_type' => 'decision',
            'label' => $title,
            'project_key' => $project,
            'source_doc_id' => strtoupper($slug),
            'payload_json' => ['dangling' => false],
        ]);
    }

    private function seedEdge(string $from, string $to, string $type = 'depends_on', float $weight = 0.8, string $project = 'eng', string $tenant = 'default'): void
    {
        KbEdge::create([
            'tenant_id' => $tenant,
            'edge_uid' => "$from->$to:$type",
            'from_node_uid' => $from,
            'to_node_uid' => $to,
            'edge_type' => $type,
            'project_key' => $project,
            'source_doc_id' => strtoupper($from),
            'weight' => $weight,
            'provenance' => 'wikilink',
        ]);
    }

    public function test_returns_outgoing_and_incoming_neighbours(): void
    {
        $this->seedDoc('dec-cache', 'Cache decision');
        $this->seedDoc('dec-redis', 'Redis decision');
        $this->seedDoc('runbook-cache', 'Cache runbook');
        // dec-cache depends_on dec-redis (outgoing); runbook-cache documents dec-cache (incoming).
        $this->seedEdge('dec-cache', 'dec-redis', 'depends_on', 0.9);
        $this->seedEdge('runbook-cache', 'dec-cache', 'documented_by', 0.5);

        $related = $this->service->relatedTo(['dec-cache'], 'eng');

        $slugs = array_column($related, 'slug');
        $this->assertEqualsCanonicalizing(['dec-redis', 'runbook-cache'], $slugs);

        $byslug = collect($related)->keyBy('slug');
        $this->assertSame('outgoing', $byslug['dec-redis']['direction']);
        $this->assertSame('Redis decision', $byslug['dec-redis']['title']);
        $this->assertSame('incoming', $byslug['runbook-cache']['direction']);
    }

    public function test_excludes_seed_and_dedupes(): void
    {
        $this->seedDoc('a', 'A');
        $this->seedDoc('b', 'B');
        // Two edges to the same neighbour → one entry; seed never appears.
        $this->seedEdge('a', 'b', 'depends_on', 0.9);
        $this->seedEdge('a', 'b', 'related_to', 0.4);

        $related = $this->service->relatedTo(['a'], 'eng');

        $this->assertCount(1, $related);
        $this->assertSame('b', $related[0]['slug']);
    }

    public function test_empty_when_no_graph_exists(): void
    {
        $this->seedDoc('lonely', 'Lonely doc');

        $this->assertSame([], $this->service->relatedTo(['lonely'], 'eng'));
    }

    public function test_empty_when_expansion_disabled(): void
    {
        config()->set('kb.graph.expansion_enabled', false);
        $this->seedDoc('a', 'A');
        $this->seedDoc('b', 'B');
        $this->seedEdge('a', 'b');

        $this->assertSame([], $this->service->relatedTo(['a'], 'eng'));
    }

    public function test_is_tenant_scoped(): void
    {
        // Same project + slugs in another tenant must not leak.
        $this->seedDoc('a', 'A (other)', 'eng', 'other');
        $this->seedDoc('b', 'B (other)', 'eng', 'other');
        $this->seedEdge('a', 'b', 'depends_on', 0.9, 'eng', 'other');

        $this->assertSame([], $this->service->relatedTo(['a'], 'eng'), 'default tenant sees no other-tenant edges');

        app(TenantContext::class)->set('other');
        $this->assertCount(1, $this->service->relatedTo(['a'], 'eng'));
    }
}
