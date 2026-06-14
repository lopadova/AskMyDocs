<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KbWikiIndex;
use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\WikiNavigator;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v8.11/P6 — WikiNavigator: multi-hop BFS over the wiki graph, anchor-driven
 * discovery, cycle-safe, budget-bounded, tenant-scoped.
 */
final class WikiNavigatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
    }

    private function node(string $uid, string $tenant = 'default', string $project = 'docs-v3'): void
    {
        KbNode::create([
            'tenant_id' => $tenant, 'project_key' => $project, 'node_uid' => $uid,
            'node_type' => 'domain-concept', 'label' => $uid, 'payload_json' => ['dangling' => false],
        ]);
    }

    private function edge(string $from, string $to, float $weight = 0.5, string $tenant = 'default', string $project = 'docs-v3'): void
    {
        KbEdge::create([
            'tenant_id' => $tenant, 'project_key' => $project,
            'edge_uid' => "{$from}->{$to}:related_to", 'from_node_uid' => $from, 'to_node_uid' => $to,
            'edge_type' => 'related_to', 'weight' => $weight, 'provenance' => 'inferred',
        ]);
    }

    private function nav(): WikiNavigator
    {
        return app(WikiNavigator::class);
    }

    public function test_multi_hop_bfs_respects_depth(): void
    {
        $this->node('a');
        $this->node('b');
        $this->node('c');
        $this->edge('a', 'b');
        $this->edge('b', 'c');

        $d1 = $this->nav()->navigate('default', 'docs-v3', ['a'], 1);
        $this->assertSame(['b'], array_column($d1['reached'], 'slug'));

        $d2 = $this->nav()->navigate('default', 'docs-v3', ['a'], 2);
        $this->assertSame(['b', 'c'], array_column($d2['reached'], 'slug'));
        $this->assertSame(2, $d2['reached'][1]['hop']);
        $this->assertSame('b', $d2['reached'][1]['from']);
    }

    public function test_cycles_do_not_loop(): void
    {
        $this->node('a');
        $this->node('b');
        $this->edge('a', 'b');
        $this->edge('b', 'a');

        $result = $this->nav()->navigate('default', 'docs-v3', ['a'], 5);
        // 'a' is a seed (visited); only 'b' is reached, no infinite loop.
        $this->assertSame(['b'], array_column($result['reached'], 'slug'));
    }

    public function test_max_nodes_budget_truncates(): void
    {
        $this->node('a');
        foreach (['b', 'c', 'd'] as $t) {
            $this->node($t);
            $this->edge('a', $t);
        }

        $result = $this->nav()->navigate('default', 'docs-v3', ['a'], 2, 2);
        $this->assertCount(2, $result['reached']);
        $this->assertTrue($result['truncated']);
    }

    public function test_resolves_docs_and_flags_dangling_targets(): void
    {
        $this->node('a');
        $this->node('real');
        $this->node('ghost');
        $this->edge('a', 'real');
        $this->edge('a', 'ghost');
        KnowledgeDocument::create([
            'tenant_id' => 'default', 'project_key' => 'docs-v3', 'source_type' => 'markdown',
            'title' => 'Real Page', 'source_path' => 'docs/real.md', 'mime_type' => 'text/markdown',
            'status' => 'active', 'document_hash' => str_repeat('a', 64), 'version_hash' => 'v1',
            'is_canonical' => true, 'slug' => 'real', 'canonical_type' => 'decision',
        ]);

        $result = $this->nav()->navigate('default', 'docs-v3', ['a'], 1);
        $bySlug = collect($result['reached'])->keyBy('slug');
        $this->assertTrue($bySlug['real']['exists']);
        $this->assertSame('Real Page', $bySlug['real']['title']);
        $this->assertFalse($bySlug['ghost']['exists']);
    }

    public function test_anchors_come_from_the_project_index(): void
    {
        KbWikiIndex::create([
            'tenant_id' => 'default', 'project_key' => 'docs-v3', 'index_type' => 'project',
            'payload_json' => ['recently_changed' => [['slug' => 'x'], ['slug' => 'y']]],
        ]);

        $this->assertSame(['x', 'y'], $this->nav()->anchors('default', 'docs-v3'));
    }

    public function test_navigate_from_anchors_uses_index_then_bfs(): void
    {
        KbWikiIndex::create([
            'tenant_id' => 'default', 'project_key' => 'docs-v3', 'index_type' => 'project',
            'payload_json' => ['recently_changed' => [['slug' => 'a']]],
        ]);
        $this->node('a');
        $this->node('b');
        $this->edge('a', 'b');

        $result = $this->nav()->navigateFromAnchors('default', 'docs-v3', 1);
        $this->assertSame(['a'], $result['anchors']);
        $this->assertSame(['b'], array_column($result['reached'], 'slug'));
    }

    public function test_navigation_is_tenant_scoped(): void
    {
        $this->node('a', tenant: 'other');
        $this->node('b', tenant: 'other');
        $this->edge('a', 'b', tenant: 'other');

        $result = $this->nav()->navigate('default', 'docs-v3', ['a'], 2);
        $this->assertSame([], $result['reached']);
    }
}
