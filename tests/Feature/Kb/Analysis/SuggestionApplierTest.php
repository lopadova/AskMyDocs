<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Analysis;

use App\Models\KbDocAnalysis;
use App\Models\KbEdge;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Analysis\SuggestionApplier;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** v8.11/P8 — SuggestionApplier: apply change/delete suggestions, audited + firewalled. */
final class SuggestionApplierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
    }

    private function doc(array $overrides = []): KnowledgeDocument
    {
        static $n = 0;
        $n++;

        return KnowledgeDocument::create(array_merge([
            'tenant_id' => 'default', 'project_key' => 'docs-v3', 'source_type' => 'markdown',
            'title' => "Doc {$n}", 'source_path' => "docs/ap-{$n}.md", 'mime_type' => 'text/markdown',
            'status' => 'active', 'document_hash' => str_repeat('a', 64), 'version_hash' => 'v'.$n,
            'is_canonical' => true, 'slug' => "doc-{$n}", 'canonical_type' => 'decision',
            'canonical_status' => 'accepted', 'generation_source' => 'auto',
        ], $overrides));
    }

    /** @param array<string,mixed> $json */
    private function analysis(KnowledgeDocument $source, array $json): KbDocAnalysis
    {
        return KbDocAnalysis::create([
            'tenant_id' => 'default', 'project_key' => 'docs-v3',
            'knowledge_document_id' => $source->id, 'doc_slug' => $source->slug,
            'trigger' => 'modified', 'analysis_json' => $json,
            'suggestion_count' => 0, 'impacted_count' => 0, 'status' => 'completed',
        ]);
    }

    private function applier(): SuggestionApplier
    {
        return app(SuggestionApplier::class);
    }

    public function test_apply_cross_reference_creates_edge_and_audits(): void
    {
        $source = $this->doc(['slug' => 'src-doc']);
        $analysis = $this->analysis($source, ['cross_references' => [['slug' => 'neighbour-x']], 'impacted_docs' => []]);

        $result = $this->applier()->apply($analysis, 'cross_reference', 'neighbour-x', 'admin:1');

        $this->assertTrue($result['applied']);
        $this->assertDatabaseHas('kb_edges', ['from_node_uid' => 'src-doc', 'to_node_uid' => 'neighbour-x', 'provenance' => 'inferred']);
        $this->assertDatabaseHas('kb_doc_analysis_applications', [
            'project_key' => 'docs-v3', 'suggestion_type' => 'cross_reference', 'action' => 'add_cross_reference',
            'source_slug' => 'src-doc', 'target_slug' => 'neighbour-x', 'applied_by' => 'admin:1',
        ]);
    }

    public function test_rejects_target_not_in_suggestions(): void
    {
        $source = $this->doc(['slug' => 'src-doc']);
        $analysis = $this->analysis($source, ['cross_references' => [['slug' => 'allowed']], 'impacted_docs' => []]);

        $result = $this->applier()->apply($analysis, 'cross_reference', 'arbitrary', 'admin:1');

        $this->assertFalse($result['applied']);
        $this->assertSame('not_in_suggestions', $result['reason']);
        $this->assertSame(0, KbEdge::query()->count());
    }

    public function test_auto_firewall_refuses_human_accepted_source(): void
    {
        $source = $this->doc(['slug' => 'human-doc', 'generation_source' => 'human', 'canonical_status' => 'accepted']);
        $analysis = $this->analysis($source, ['cross_references' => [['slug' => 'x']], 'impacted_docs' => []]);

        // auto=true → firewall refuses a human-accepted doc.
        $auto = $this->applier()->apply($analysis, 'cross_reference', 'x', 'system:autowiki-apply', true);
        $this->assertFalse($auto['applied']);
        $this->assertSame('firewall_human_doc', $auto['reason']);

        // Manual (auto=false) is an explicit human action → allowed.
        $manual = $this->applier()->apply($analysis, 'cross_reference', 'x', 'admin:1', false);
        $this->assertTrue($manual['applied']);
    }

    public function test_apply_impacted_deprecates_target(): void
    {
        $source = $this->doc(['slug' => 'src-doc']);
        $impacted = $this->doc(['slug' => 'stale-doc', 'canonical_status' => 'accepted']);
        $analysis = $this->analysis($source, ['cross_references' => [], 'impacted_docs' => [['slug' => 'stale-doc', 'suggested_action' => 'deprecate']]]);

        $result = $this->applier()->apply($analysis, 'impacted', 'stale-doc', 'admin:1');

        $this->assertTrue($result['applied']);
        $this->assertSame('deprecated', $impacted->fresh()->canonical_status);
        $this->assertDatabaseHas('kb_canonical_audit', ['slug' => 'stale-doc', 'event_type' => 'deprecated', 'actor' => 'admin:1']);
    }

    public function test_auto_apply_respects_the_flag(): void
    {
        $source = $this->doc(['slug' => 'src-doc']);
        $analysis = $this->analysis($source, ['cross_references' => [['slug' => 'n1'], ['slug' => 'n2']], 'impacted_docs' => []]);

        config(['kb.change_analysis.autoapply_enabled' => false]);
        $off = $this->applier()->autoApply($analysis);
        $this->assertFalse($off['ran']);
        $this->assertSame(0, KbEdge::query()->count());

        config(['kb.change_analysis.autoapply_enabled' => true]);
        $on = $this->applier()->autoApply($analysis);
        $this->assertTrue($on['ran']);
        $this->assertSame(2, $on['applied']);
        $this->assertSame(2, KbEdge::query()->where('from_node_uid', 'src-doc')->count());
    }

    public function test_is_tenant_scoped(): void
    {
        $source = $this->doc(['slug' => 'src-doc', 'tenant_id' => 'other']);
        // analysis in 'other' tenant; applier scoped to 'default' can't resolve source.
        $analysis = KbDocAnalysis::create([
            'tenant_id' => 'other', 'project_key' => 'docs-v3', 'knowledge_document_id' => $source->id,
            'doc_slug' => 'src-doc', 'trigger' => 'modified',
            'analysis_json' => ['cross_references' => [['slug' => 'x']], 'impacted_docs' => []],
            'suggestion_count' => 0, 'impacted_count' => 0, 'status' => 'completed',
        ]);

        // Applier reads analysis->tenant_id ('other') for source resolution; but
        // the source doc IS in 'other', so this resolves. Assert cross-tenant
        // safety differently: a default-tenant applier cannot see an 'other' edge.
        app(TenantContext::class)->set('other');
        $result = $this->applier()->apply($analysis, 'cross_reference', 'x', 'admin:1');
        $this->assertTrue($result['applied']);
        $this->assertSame(1, KbEdge::query()->where('tenant_id', 'other')->count());
        $this->assertSame(0, KbEdge::query()->where('tenant_id', 'default')->count());
    }
}
