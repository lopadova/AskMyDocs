<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\AutoWikiGraphLinker;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v8.11/P2 — AutoWikiGraphLinker: materialise auto cross-references into the
 * navigable graph (nodes + inferred edges), assigning a stable per-project slug
 * when the doc has none (enterprise scope).
 */
final class AutoWikiGraphLinkerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
    }

    /** @param array<string,mixed> $overrides */
    private function doc(array $overrides = []): KnowledgeDocument
    {
        static $n = 0;
        $n++;

        return KnowledgeDocument::create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'docs-v3',
            'source_type' => 'markdown',
            'title' => "Cache Strategy {$n}",
            'source_path' => "docs/cache-{$n}.md",
            'mime_type' => 'text/markdown',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => 'ver'.$n,
            'is_canonical' => false,
        ], $overrides));
    }

    /** @param list<array<string,string>> $crossRefs */
    private function withCrossRefs(KnowledgeDocument $doc, array $crossRefs): KnowledgeDocument
    {
        $fm = is_array($doc->frontmatter_json) ? $doc->frontmatter_json : [];
        $fm['_autowiki'] = array_merge($fm['_autowiki'] ?? [], ['cross_references' => $crossRefs]);
        $doc->forceFill(['frontmatter_json' => $fm])->save();

        return $doc->fresh();
    }

    private function linker(): AutoWikiGraphLinker
    {
        return app(AutoWikiGraphLinker::class);
    }

    public function test_assigns_slug_and_builds_self_node_and_inferred_edges(): void
    {
        $doc = $this->withCrossRefs($this->doc(['title' => 'Cache Eviction']), [
            ['slug' => 'dec-cache', 'title' => 'Cache decision', 'why' => 'depends', 'edge_type' => 'depends_on'],
        ]);
        $this->assertNull($doc->slug);

        $result = $this->linker()->link($doc);

        $this->assertTrue($result['linked']);
        $this->assertTrue($result['slug_assigned']);
        $this->assertSame('cache-eviction', $result['slug']);
        $this->assertSame('cache-eviction', $doc->fresh()->slug);

        // Self node + dangling target node both exist, tenant-scoped.
        $this->assertDatabaseHas('kb_nodes', ['tenant_id' => 'default', 'project_key' => 'docs-v3', 'node_uid' => 'cache-eviction']);
        $this->assertDatabaseHas('kb_nodes', ['project_key' => 'docs-v3', 'node_uid' => 'dec-cache']);

        // Inferred edge with the requested type + provenance.
        $edge = KbEdge::query()->where('from_node_uid', 'cache-eviction')->where('to_node_uid', 'dec-cache')->first();
        $this->assertNotNull($edge);
        $this->assertSame('depends_on', $edge->edge_type);
        $this->assertSame('inferred', $edge->provenance);

        $this->assertDatabaseHas('kb_canonical_audit', [
            'project_key' => 'docs-v3', 'event_type' => 'graph_rebuild', 'actor' => 'system:autowiki', 'slug' => 'cache-eviction',
        ]);
    }

    public function test_reuses_existing_slug_and_does_not_reassign(): void
    {
        $doc = $this->withCrossRefs($this->doc(['slug' => 'pre-set-slug']), [
            ['slug' => 'other', 'edge_type' => 'related_to'],
        ]);

        $result = $this->linker()->link($doc);

        $this->assertTrue($result['linked']);
        $this->assertFalse($result['slug_assigned']);
        $this->assertSame('pre-set-slug', $result['slug']);
    }

    public function test_slug_collision_appends_a_suffix(): void
    {
        // An existing doc already owns the title-slug in this project.
        $this->doc(['title' => 'Shared Title', 'slug' => 'shared-title']);
        $doc = $this->withCrossRefs($this->doc(['title' => 'Shared Title']), []);

        $result = $this->linker()->link($doc);

        $this->assertSame('shared-title-2', $result['slug']);
        $this->assertSame('shared-title-2', $doc->fresh()->slug);
    }

    public function test_replaces_only_inferred_edges_leaving_frontmatter_edges_intact(): void
    {
        $doc = $this->doc(['slug' => 'origin']);
        // Pre-existing graph: self node + two target nodes + one frontmatter edge.
        foreach (['origin', 'human-target', 'auto-target'] as $uid) {
            KbNode::create(['tenant_id' => 'default', 'project_key' => 'docs-v3', 'node_uid' => $uid, 'node_type' => 'domain-concept', 'label' => $uid, 'payload_json' => ['dangling' => false]]);
        }
        KbEdge::create([
            'tenant_id' => 'default', 'project_key' => 'docs-v3', 'edge_uid' => 'origin->human-target:related_to',
            'from_node_uid' => 'origin', 'to_node_uid' => 'human-target', 'edge_type' => 'related_to',
            'weight' => 0.5, 'provenance' => 'frontmatter_related',
        ]);
        $doc = $this->withCrossRefs($doc, [['slug' => 'auto-target', 'edge_type' => 'uses']]);

        $this->linker()->link($doc);

        // Frontmatter edge preserved; inferred edge added.
        $this->assertDatabaseHas('kb_edges', ['from_node_uid' => 'origin', 'to_node_uid' => 'human-target', 'provenance' => 'frontmatter_related']);
        $this->assertDatabaseHas('kb_edges', ['from_node_uid' => 'origin', 'to_node_uid' => 'auto-target', 'provenance' => 'inferred', 'edge_type' => 'uses']);
    }

    public function test_disabled_flag_is_a_clean_noop(): void
    {
        config(['kb.autowiki.graph_enabled' => false]);
        $doc = $this->withCrossRefs($this->doc(), [['slug' => 'x', 'edge_type' => 'related_to']]);

        $result = $this->linker()->link($doc);

        $this->assertFalse($result['linked']);
        $this->assertSame('disabled', $result['reason']);
        $this->assertSame(0, KbEdge::query()->count());
        $this->assertSame(0, KbNode::query()->count());
        $this->assertNull($doc->fresh()->slug); // no slug assigned when disabled
    }

    public function test_firewall_skips_human_curated_canonical_doc(): void
    {
        $doc = $this->withCrossRefs(
            $this->doc(['is_canonical' => true, 'generation_source' => 'human', 'slug' => 'human-doc']),
            [['slug' => 'x', 'edge_type' => 'related_to']],
        );

        $result = $this->linker()->link($doc);

        $this->assertFalse($result['linked']);
        $this->assertSame('human_curated', $result['reason']);
        $this->assertSame(0, KbEdge::query()->count());
    }

    public function test_rerun_is_idempotent_on_the_inferred_edge_set(): void
    {
        $doc = $this->withCrossRefs($this->doc(['slug' => 'idem']), [
            ['slug' => 'a', 'edge_type' => 'related_to'],
            ['slug' => 'b', 'edge_type' => 'uses'],
        ]);

        $this->linker()->link($doc);
        $this->linker()->link($doc->fresh());

        $this->assertSame(2, KbEdge::query()->where('from_node_uid', 'idem')->where('provenance', 'inferred')->count());
    }
}
