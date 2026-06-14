<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KbWikiIndex;
use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\WikiLinter;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v8.11/P5 — WikiLinter: deterministic wiki-health checks + safe auto-fix.
 */
final class WikiLinterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
    }

    private function node(string $uid, bool $dangling, string $tenant = 'default', string $project = 'docs-v3'): KbNode
    {
        return KbNode::create([
            'tenant_id' => $tenant, 'project_key' => $project, 'node_uid' => $uid,
            'node_type' => 'domain-concept', 'label' => $uid,
            'payload_json' => ['dangling' => $dangling],
        ]);
    }

    private function edge(string $from, string $to, string $tenant = 'default', string $project = 'docs-v3'): KbEdge
    {
        return KbEdge::create([
            'tenant_id' => $tenant, 'project_key' => $project,
            'edge_uid' => "{$from}->{$to}:related_to", 'from_node_uid' => $from, 'to_node_uid' => $to,
            'edge_type' => 'related_to', 'weight' => 0.5, 'provenance' => 'inferred',
        ]);
    }

    private function doc(string $slug, string $status, ?string $deletedAt = null, string $tenant = 'default', string $project = 'docs-v3'): KnowledgeDocument
    {
        static $n = 0;
        $n++;
        $doc = KnowledgeDocument::create([
            'tenant_id' => $tenant, 'project_key' => $project, 'source_type' => 'markdown',
            'title' => $slug, 'source_path' => "docs/{$n}.md", 'mime_type' => 'text/markdown',
            'status' => 'active', 'document_hash' => str_repeat('a', 64), 'version_hash' => 'v'.$n,
            'is_canonical' => true, 'slug' => $slug, 'canonical_type' => 'decision', 'canonical_status' => $status,
        ]);
        if ($deletedAt !== null) {
            $doc->delete(); // soft delete
        }

        return $doc;
    }

    private function linter(): WikiLinter
    {
        return app(WikiLinter::class);
    }

    public function test_detects_dangling_nodes(): void
    {
        $this->node('owner', false);
        $this->node('missing-target', true);
        $this->edge('owner', 'missing-target');

        $report = $this->linter()->lint('default', 'docs-v3');

        $this->assertContains('missing-target', $report['findings']['dangling']);
        $this->assertSame(1, $report['counts']['dangling']);
    }

    public function test_detects_orphan_nodes(): void
    {
        $this->node('connected-a', false);
        $this->node('connected-b', false);
        $this->edge('connected-a', 'connected-b');
        $this->node('lonely', false); // no edges, not dangling → orphan

        $report = $this->linter()->lint('default', 'docs-v3');

        $this->assertSame(['lonely'], $report['findings']['orphan']);
    }

    public function test_detects_stale_cross_reference_to_deprecated_and_deleted(): void
    {
        $this->node('src', false);
        $this->node('dep-target', false);
        $this->node('del-target', false);
        $this->node('ok-target', false);
        $this->edge('src', 'dep-target');
        $this->edge('src', 'del-target');
        $this->edge('src', 'ok-target');
        $this->doc('dep-target', 'deprecated');
        $this->doc('del-target', 'accepted', deletedAt: 'now');
        $this->doc('ok-target', 'accepted');

        $report = $this->linter()->lint('default', 'docs-v3');

        $reasons = collect($report['findings']['stale_cross_ref'])->keyBy('target')->map->reason;
        $this->assertSame('deprecated', $reasons['dep-target']);
        $this->assertSame('deleted', $reasons['del-target']);
        $this->assertArrayNotHasKey('ok-target', $reasons->all());
    }

    public function test_flags_missing_index(): void
    {
        $this->node('a', false);

        $report = $this->linter()->lint('default', 'docs-v3');
        $this->assertTrue($report['findings']['missing_index']);

        KbWikiIndex::create(['tenant_id' => 'default', 'project_key' => 'docs-v3', 'index_type' => 'project', 'payload_json' => []]);
        $report2 = $this->linter()->lint('default', 'docs-v3');
        $this->assertFalse($report2['findings']['missing_index']);
    }

    public function test_healthy_project_reports_healthy(): void
    {
        $this->node('a', false);
        $this->node('b', false);
        $this->edge('a', 'b');
        $this->edge('b', 'a');
        KbWikiIndex::create(['tenant_id' => 'default', 'project_key' => 'docs-v3', 'index_type' => 'project', 'payload_json' => []]);

        $report = $this->linter()->lint('default', 'docs-v3');
        $this->assertTrue($report['healthy']);
    }

    public function test_fix_prunes_leftover_dangling_but_keeps_referenced(): void
    {
        // 'referenced' dangling still has an incoming edge → kept.
        $this->node('owner', false);
        $this->node('referenced', true);
        $this->edge('owner', 'referenced');
        // 'leftover' dangling has no incoming edge → pruned.
        $this->node('leftover', true);

        $result = $this->linter()->fix('default', 'docs-v3');

        $this->assertSame(['leftover'], $result['pruned']);
        $this->assertDatabaseMissing('kb_nodes', ['project_key' => 'docs-v3', 'node_uid' => 'leftover']);
        $this->assertDatabaseHas('kb_nodes', ['project_key' => 'docs-v3', 'node_uid' => 'referenced']);
    }

    public function test_lint_is_tenant_scoped(): void
    {
        $this->node('lonely', false, tenant: 'other');

        $report = $this->linter()->lint('default', 'docs-v3');
        $this->assertSame([], $report['findings']['orphan']);
        $this->assertTrue($report['healthy'] || $report['findings']['missing_index']); // no 'other' data leaks in
    }
}
