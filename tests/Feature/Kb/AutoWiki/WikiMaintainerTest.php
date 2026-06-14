<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Jobs\AutoWikiCompilerJob;
use App\Models\KbNode;
use App\Models\KbWikiIndex;
use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\WikiMaintainer;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** v8.11/P9 — WikiMaintainer: scheduled sweep (index rebuild + lint + backfill). */
final class WikiMaintainerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
    }

    private function doc(bool $enriched, string $tenant = 'default', string $project = 'docs-v3'): KnowledgeDocument
    {
        static $n = 0;
        $n++;

        return KnowledgeDocument::create([
            'tenant_id' => $tenant, 'project_key' => $project, 'source_type' => 'markdown',
            'title' => "Doc {$n}", 'source_path' => "docs/m-{$tenant}-{$n}.md", 'mime_type' => 'text/markdown',
            'status' => 'active', 'document_hash' => str_repeat('a', 64), 'version_hash' => 'v'.$tenant.$n,
            'is_canonical' => true, 'slug' => "doc-{$tenant}-{$n}", 'canonical_type' => 'decision',
            'generation_source' => $enriched ? 'auto' : 'human',
            'frontmatter_json' => $enriched ? ['_autowiki' => ['tags' => ['x']]] : null,
        ]);
    }

    private function maintainer(): WikiMaintainer
    {
        return app(WikiMaintainer::class);
    }

    public function test_maintain_rebuilds_index_and_backfills_unenriched(): void
    {
        Queue::fake();
        $enriched = $this->doc(true);
        $un1 = $this->doc(false);
        $un2 = $this->doc(false);

        $result = $this->maintainer()->maintain('default', 'docs-v3');

        $this->assertSame(['docs-v3'], $result['projects']);
        $this->assertSame(2, $result['backfilled']);

        // Index rows built (P4).
        $this->assertDatabaseHas('kb_wiki_indices', ['project_key' => 'docs-v3', 'index_type' => 'project']);
        $this->assertDatabaseHas('kb_wiki_indices', ['project_key' => '*', 'index_type' => 'tenant_hub']);

        // Compiler dispatched only for the un-enriched docs.
        Queue::assertPushed(AutoWikiCompilerJob::class, 2);
        Queue::assertPushed(AutoWikiCompilerJob::class, fn ($job) => in_array($job->documentId, [$un1->id, $un2->id], true));
        Queue::assertNotPushed(AutoWikiCompilerJob::class, fn ($job) => $job->documentId === $enriched->id);
    }

    public function test_backfill_matches_doc_with_frontmatter_but_no_autowiki_key(): void
    {
        Queue::fake();
        // The common real-world un-enriched shape: frontmatter present (e.g. a
        // canonical doc's YAML) but no `_autowiki` block yet.
        $doc = KnowledgeDocument::create([
            'tenant_id' => 'default', 'project_key' => 'docs-v3', 'source_type' => 'markdown',
            'title' => 'Has FM', 'source_path' => 'docs/fm.md', 'mime_type' => 'text/markdown',
            'status' => 'active', 'document_hash' => str_repeat('a', 64), 'version_hash' => 'vfm',
            'is_canonical' => true, 'slug' => 'has-fm', 'canonical_type' => 'decision',
            'generation_source' => 'human', 'frontmatter_json' => ['title' => 'Has FM', 'tags' => ['x']],
        ]);

        $result = $this->maintainer()->maintain('default', 'docs-v3');

        $this->assertSame(1, $result['backfilled']);
        Queue::assertPushed(AutoWikiCompilerJob::class, fn ($job) => $job->documentId === $doc->id);
    }

    public function test_backfill_respects_limit(): void
    {
        Queue::fake();
        $this->doc(false);
        $this->doc(false);
        $this->doc(false);

        $result = $this->maintainer()->maintain('default', 'docs-v3', false, 1);

        $this->assertSame(1, $result['backfilled']);
        Queue::assertPushed(AutoWikiCompilerJob::class, 1);
    }

    public function test_fix_prunes_dangling(): void
    {
        Queue::fake();
        KbNode::create([
            'tenant_id' => 'default', 'project_key' => 'docs-v3', 'node_uid' => 'leftover',
            'node_type' => 'unknown', 'label' => 'leftover', 'payload_json' => ['dangling' => true],
        ]);

        $result = $this->maintainer()->maintain('default', 'docs-v3', true, 0);

        $this->assertSame(1, $result['fixed']);
        $this->assertDatabaseMissing('kb_nodes', ['node_uid' => 'leftover']);
    }

    public function test_is_tenant_scoped(): void
    {
        Queue::fake();
        $this->doc(false, tenant: 'other');

        $result = $this->maintainer()->maintain('default', 'docs-v3');

        $this->assertSame(0, $result['backfilled']);
        Queue::assertNotPushed(AutoWikiCompilerJob::class);
    }
}
