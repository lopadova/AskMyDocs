<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Models\KbCanonicalAudit;
use App\Models\KbWikiIndex;
use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\WikiIndexBuilder;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v8.11/P4 — WikiIndexBuilder: per-project roll-ups + per-tenant hub + the
 * auto-wiki operation log, deterministic + tenant-scoped.
 */
final class WikiIndexBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
    }

    private function doc(string $slug, string $type, string $gen = 'human', string $tenant = 'default', string $project = 'docs-v3'): KnowledgeDocument
    {
        static $n = 0;
        $n++;

        return KnowledgeDocument::create([
            'tenant_id' => $tenant,
            'project_key' => $project,
            'source_type' => 'markdown',
            'title' => ucfirst($slug),
            'source_path' => "docs/{$tenant}-{$n}.md",
            'mime_type' => 'text/markdown',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => 'v'.$tenant.$n,
            'is_canonical' => true,
            'slug' => $slug,
            'canonical_type' => $type,
            'generation_source' => $gen,
        ]);
    }

    private function builder(): WikiIndexBuilder
    {
        return app(WikiIndexBuilder::class);
    }

    public function test_build_project_index_counts_pages_by_type_and_tier(): void
    {
        $this->doc('dec-a', 'decision', 'human');
        $this->doc('dec-b', 'decision', 'human');
        $this->doc('concept-cache', 'domain-concept', 'auto');

        $payload = $this->builder()->buildProjectIndex('default', 'docs-v3');

        $this->assertSame(3, $payload['page_total']);
        $this->assertSame(2, $payload['page_counts_by_type']['decision']);
        $this->assertSame(1, $payload['page_counts_by_type']['domain-concept']);
        $this->assertSame(1, $payload['concept_count']);
        $this->assertSame(1, $payload['auto_count']);
        $this->assertSame(2, $payload['human_count']);
        $this->assertStringContainsString('Project index', $payload['rendered_markdown']);

        $this->assertDatabaseHas('kb_wiki_indices', [
            'tenant_id' => 'default', 'project_key' => 'docs-v3', 'index_type' => 'project',
        ]);
        $this->assertDatabaseHas('kb_canonical_audit', [
            'project_key' => 'docs-v3', 'event_type' => 'graph_rebuild', 'actor' => 'system:autowiki',
        ]);
    }

    public function test_build_tenant_hub_aggregates_projects(): void
    {
        $this->doc('a', 'decision', 'human', project: 'proj-a');
        $this->doc('b', 'domain-concept', 'auto', project: 'proj-b');

        $this->builder()->rebuild('default'); // all projects + hub

        $hub = KbWikiIndex::query()->where('index_type', 'tenant_hub')->where('project_key', '*')->first();
        $this->assertNotNull($hub);
        $this->assertSame(2, $hub->payload_json['project_count']);
        $this->assertSame(2, $hub->payload_json['total_pages']);
        $this->assertSame(1, $hub->payload_json['total_concepts']);
        $this->assertSame(['proj-a', 'proj-b'], array_column($hub->payload_json['projects'], 'project_key'));
    }

    public function test_hub_read_returns_hub_and_project_rows(): void
    {
        $this->doc('a', 'decision');
        $this->builder()->rebuild('default');

        $read = $this->builder()->hub('default');
        $this->assertNotNull($read['hub']);
        $this->assertCount(1, $read['projects']);
        $this->assertSame('docs-v3', $read['projects'][0]['project_key']);
    }

    public function test_rebuild_is_idempotent_one_row_per_project(): void
    {
        $this->doc('a', 'decision');
        $this->builder()->rebuild('default');
        $this->builder()->rebuild('default');

        $this->assertSame(1, KbWikiIndex::query()->where('index_type', 'project')->where('project_key', 'docs-v3')->count());
        $this->assertSame(1, KbWikiIndex::query()->where('index_type', 'tenant_hub')->count());
    }

    public function test_operation_log_returns_autowiki_audit_filtered(): void
    {
        // An auto-wiki op + a non-autowiki op; only the former shows.
        KbCanonicalAudit::create(['tenant_id' => 'default', 'project_key' => 'docs-v3', 'event_type' => 'updated', 'actor' => 'system:autowiki', 'metadata_json' => ['source' => 'compiler']]);
        KbCanonicalAudit::create(['tenant_id' => 'default', 'project_key' => 'docs-v3', 'event_type' => 'promoted', 'actor' => 'admin:1']);

        $log = $this->builder()->operationLog('default');

        $this->assertCount(1, $log);
        $this->assertSame('updated', $log[0]['event_type']);
    }

    public function test_builder_is_tenant_scoped(): void
    {
        $this->doc('a', 'decision', tenant: 'other', project: 'docs-v3');

        $payload = $this->builder()->buildProjectIndex('default', 'docs-v3');

        // The 'other' tenant's doc is not counted under 'default'.
        $this->assertSame(0, $payload['page_total']);
    }
}
