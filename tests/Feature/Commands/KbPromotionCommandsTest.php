<?php

namespace Tests\Feature\Commands;

use App\Jobs\CanonicalIndexerJob;
use App\Jobs\IngestDocumentJob;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KbPromotionCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
    }

    // -------------------------------------------------------------
    // kb:promote
    // -------------------------------------------------------------

    public function test_kb_promote_writes_file_and_dispatches_ingest(): void
    {
        Queue::fake();
        $path = $this->writeTempMarkdown(<<<'MD'
---
id: DEC-2026-0001
slug: dec-x
type: decision
status: accepted
---

# Decision X
MD);

        $this->artisan('kb:promote', ['path' => $path, '--project' => 'acme'])
            ->assertExitCode(0);

        Storage::disk('kb')->assertExists('decisions/dec-x.md');
        Queue::assertPushed(IngestDocumentJob::class);
    }

    public function test_kb_promote_fails_when_project_option_missing(): void
    {
        $path = $this->writeTempMarkdown("---\nslug: x\ntype: decision\nstatus: accepted\n---\n\n# x");
        $this->artisan('kb:promote', ['path' => $path])
            ->assertExitCode(2);  // Command::INVALID
    }

    public function test_kb_promote_fails_when_file_missing(): void
    {
        $this->artisan('kb:promote', ['path' => '/tmp/does-not-exist.md', '--project' => 'acme'])
            ->assertExitCode(1);
    }

    public function test_kb_promote_fails_on_invalid_frontmatter(): void
    {
        Queue::fake();
        $path = $this->writeTempMarkdown("---\ntype: decision\nstatus: accepted\n---\n\n# No slug");

        $this->artisan('kb:promote', ['path' => $path, '--project' => 'acme'])
            ->expectsOutputToContain('[slug]')
            ->assertExitCode(1);

        Queue::assertNotPushed(IngestDocumentJob::class);
    }

    public function test_kb_promote_dry_run_does_not_write(): void
    {
        Queue::fake();
        $path = $this->writeTempMarkdown("---\nslug: dec-dry\ntype: decision\nstatus: accepted\n---\n\n# Dry");

        $this->artisan('kb:promote', ['path' => $path, '--project' => 'acme', '--dry-run' => true])
            ->assertExitCode(0);

        Storage::disk('kb')->assertMissing('decisions/dec-dry.md');
        Queue::assertNotPushed(IngestDocumentJob::class);
    }

    // -------------------------------------------------------------
    // kb:validate-canonical (DB mode — default)
    // -------------------------------------------------------------

    public function test_kb_validate_canonical_reports_zero_errors_for_valid_docs(): void
    {
        KnowledgeDocument::create($this->validCanonicalRow('acme', 'dec-a', 'DEC-0001'));

        $this->artisan('kb:validate-canonical')
            ->expectsOutputToContain('1 OK, 0 error')
            ->assertExitCode(0);
    }

    public function test_kb_validate_canonical_scope_by_project(): void
    {
        KnowledgeDocument::create($this->validCanonicalRow('acme', 'dec-a', 'DEC-0001'));
        KnowledgeDocument::create($this->validCanonicalRow('beta', 'dec-b', 'DEC-0002'));

        $this->artisan('kb:validate-canonical', ['--project' => 'acme'])
            ->expectsOutputToContain('1 OK, 0 error')
            ->assertExitCode(0);
    }

    // -------------------------------------------------------------
    // kb:rebuild-graph
    // -------------------------------------------------------------

    public function test_kb_rebuild_graph_dispatches_one_job_per_canonical_doc(): void
    {
        Queue::fake();
        KnowledgeDocument::create($this->validCanonicalRow('acme', 'dec-a', 'DEC-0001'));
        KnowledgeDocument::create($this->validCanonicalRow('acme', 'dec-b', 'DEC-0002'));
        KnowledgeDocument::create($this->validCanonicalRow('beta', 'dec-c', 'DEC-0003'));

        $this->artisan('kb:rebuild-graph')->assertExitCode(0);

        Queue::assertPushed(CanonicalIndexerJob::class, 3);
    }

    public function test_kb_rebuild_graph_scopes_to_single_project(): void
    {
        Queue::fake();
        KnowledgeDocument::create($this->validCanonicalRow('acme', 'dec-a', 'DEC-0001'));
        KnowledgeDocument::create($this->validCanonicalRow('beta', 'dec-b', 'DEC-0002'));

        $this->artisan('kb:rebuild-graph', ['--project' => 'acme'])->assertExitCode(0);

        Queue::assertPushed(CanonicalIndexerJob::class, 1);
    }

    public function test_kb_rebuild_graph_truncates_existing_nodes_and_edges_by_default(): void
    {
        Queue::fake();
        KnowledgeDocument::create($this->validCanonicalRow('acme', 'dec-a', 'DEC-0001'));
        KbNode::create(['node_uid' => 'orphan', 'node_type' => 'decision', 'label' => 'Orphan', 'project_key' => 'acme']);
        KbNode::create(['node_uid' => 'orphan2', 'node_type' => 'module', 'label' => 'O2', 'project_key' => 'acme']);
        KbEdge::create([
            'edge_uid' => 'orphan->orphan2:related_to',
            'from_node_uid' => 'orphan',
            'to_node_uid' => 'orphan2',
            'edge_type' => 'related_to',
            'project_key' => 'acme',
            'source_doc_id' => 'OLD',
            'weight' => 0.5,
            'provenance' => 'inferred',
        ]);

        $this->artisan('kb:rebuild-graph', ['--project' => 'acme'])->assertExitCode(0);

        $this->assertSame(0, KbNode::where('project_key', 'acme')->count());
        $this->assertSame(0, KbEdge::where('project_key', 'acme')->count());
    }

    public function test_kb_rebuild_graph_is_noop_when_no_canonical_docs(): void
    {
        Queue::fake();

        $this->artisan('kb:rebuild-graph')
            ->expectsOutputToContain('Nothing to do')
            ->assertExitCode(0);

        Queue::assertNotPushed(CanonicalIndexerJob::class);
    }

    public function test_kb_rebuild_graph_sync_mode_runs_indexer_inline(): void
    {
        $doc = KnowledgeDocument::create(array_merge($this->validCanonicalRow('acme', 'dec-x', 'DEC-0001'), [
            'frontmatter_json' => [
                '_derived' => ['related_slugs' => ['mod-a'], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
            ],
        ]));

        $this->artisan('kb:rebuild-graph', ['--project' => 'acme', '--sync' => true])->assertExitCode(0);

        // Sync mode executes the job inline → the self node exists.
        $this->assertSame(1, KbNode::where('node_uid', 'dec-x')->count());
    }

    // -------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validCanonicalRow(string $projectKey, string $slug, string $docId): array
    {
        static $counter = 0;
        $counter++;
        return [
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => "Title {$counter}",
            'source_path' => "decisions/{$slug}.md",
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_pad((string) $counter, 64, 'a'),
            'version_hash' => str_pad((string) $counter, 64, 'b'),
            'doc_id' => $docId,
            'slug' => $slug,
            'canonical_type' => 'decision',
            'canonical_status' => 'accepted',
            'is_canonical' => true,
            'retrieval_priority' => 70,
            'frontmatter_json' => [
                'slug' => $slug,
                'type' => 'decision',
                'status' => 'accepted',
                '_derived' => ['related_slugs' => [], 'supersedes_slugs' => [], 'superseded_by_slugs' => []],
            ],
        ];
    }

    private function writeTempMarkdown(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kb_promote_') . '.md';
        file_put_contents($path, $content);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));
        return $path;
    }
}
