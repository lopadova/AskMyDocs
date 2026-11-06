<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Embedding;

use App\Jobs\ReembedDocumentJob;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Admin\IngestionObservabilityService;
use App\Services\Kb\Embedding\EmbeddingInputTemplate;
use App\Services\Kb\Embedding\ReembedStaleService;
use App\Services\Kb\EmbeddingCacheService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * v8.42/W2 (ADR 0035 §D2) — `kb:reembed-stale` and its read surface, tenant
 * scoped (R30), in both template states. Documents/chunks are inserted
 * directly so each fingerprint is controlled by the test.
 */
final class ReembedStaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('activeTarget')->andReturn(['provider' => 'openai', 'model' => 'text-embedding-3-small']);
        $this->app->instance(EmbeddingCacheService::class, $cache);

        config(['kb.embedding_input.contextual' => false]);
        app(TenantContext::class)->set('default');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function active(): string
    {
        return app(ReembedStaleService::class)->activeFingerprint();
    }

    private function doc(string $tenant, string $project, string $path, string $status = 'active'): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'tenant_id' => $tenant,
            'project_key' => $project,
            'source_type' => 'markdown',
            'title' => $path,
            'source_path' => $path,
            'mime_type' => 'text/markdown',
            'status' => $status,
            'document_hash' => hash('sha256', $tenant.$path),
            'version_hash' => hash('sha256', $tenant.$path),
            'metadata' => [],
        ]);
    }

    private function chunk(KnowledgeDocument $doc, int $order, ?string $fingerprint, string $text = 'abcdefgh'): KnowledgeChunk
    {
        return KnowledgeChunk::create([
            'tenant_id' => $doc->tenant_id,
            'knowledge_document_id' => $doc->id,
            'project_key' => $doc->project_key,
            'chunk_order' => $order,
            'chunk_hash' => hash('sha256', $doc->id.'-'.$order),
            'heading_path' => '',
            'chunk_text' => $text,
            'metadata' => [],
            'embedding' => [0.1, 0.2, 0.3],
            'embedding_fingerprint' => $fingerprint,
        ]);
    }

    public function test_it_selects_null_and_mismatching_fingerprints_and_ignores_current_ones(): void
    {
        $a = $this->doc('default', 'ops', 'a.md');
        $b = $this->doc('default', 'ops', 'b.md');
        $c = $this->doc('default', 'ops', 'c.md');
        $this->chunk($a, 0, null);                   // legacy: recipe unknown → stale
        $this->chunk($b, 0, 'deadbeefdeadbeef');     // other recipe → stale
        $this->chunk($c, 0, $this->active());        // current → fresh

        $s = app(ReembedStaleService::class)->summary('default');

        $this->assertSame(3, $s['total_chunks']);
        $this->assertSame(2, $s['stale_chunks']);
        $this->assertSame(2, $s['stale_documents']);
    }

    public function test_a_document_with_one_stale_chunk_counts_once_and_is_queued_once(): void
    {
        $d = $this->doc('default', 'ops', 'mixed.md');
        $this->chunk($d, 0, $this->active());
        $this->chunk($d, 1, null);
        $this->chunk($d, 2, 'deadbeefdeadbeef');

        $result = app(ReembedStaleService::class)->run('default');

        $this->assertSame(1, $result['summary']['stale_documents']);
        $this->assertSame(2, $result['summary']['stale_chunks']);
        $this->assertSame(1, $result['queued']);
        Queue::assertPushed(ReembedDocumentJob::class, 1);
        Queue::assertPushed(ReembedDocumentJob::class, fn (ReembedDocumentJob $j) => $j->documentId === $d->id && $j->tenantId === 'default');
    }

    public function test_dry_run_reports_and_queues_nothing(): void
    {
        $this->chunk($this->doc('default', 'ops', 'a.md'), 0, null, str_repeat('x', 400));

        $result = app(ReembedStaleService::class)->run('default', dryRun: true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, $result['queued']);
        $this->assertSame(1, $result['summary']['stale_chunks']);
        $this->assertSame(100, $result['summary']['estimated_tokens'], '400 chars ≈ 100 tokens');
        $this->assertNull($result['summary']['estimated_cost'], 'no price configured → no invented cost');
        Queue::assertNothingPushed();
    }

    public function test_cost_is_estimated_only_when_a_price_is_configured(): void
    {
        $this->chunk($this->doc('default', 'ops', 'a.md'), 0, null, str_repeat('x', 4_000_000));
        config(['kb.embedding_input.price_per_million_tokens' => 0.02]);

        $s = app(ReembedStaleService::class)->summary('default');

        $this->assertSame(1_000_000, $s['estimated_tokens']);
        $this->assertEqualsWithDelta(0.02, $s['estimated_cost'], 1e-9);
    }

    public function test_it_never_crosses_the_tenant_boundary(): void
    {
        $mine = $this->doc('default', 'ops', 'mine.md');
        $theirs = $this->doc('other', 'ops', 'theirs.md');
        $this->chunk($mine, 0, null);
        $this->chunk($theirs, 0, null);

        $result = app(ReembedStaleService::class)->run('default');

        $this->assertSame(1, $result['queued']);
        Queue::assertPushed(ReembedDocumentJob::class, fn (ReembedDocumentJob $j) => $j->documentId === $mine->id);
        Queue::assertNotPushed(ReembedDocumentJob::class, fn (ReembedDocumentJob $j) => $j->documentId === $theirs->id);
    }

    public function test_project_filter_and_archived_documents_are_respected(): void
    {
        $this->chunk($this->doc('default', 'ops', 'a.md'), 0, null);
        $this->chunk($this->doc('default', 'hr', 'b.md'), 0, null);
        $this->chunk($this->doc('default', 'ops', 'old.md', 'archived'), 0, null);

        $this->assertSame(1, app(ReembedStaleService::class)->run('default', 'ops')['queued'], 'only the active ops doc');
        $this->assertSame(1, app(ReembedStaleService::class)->summary('default', 'hr')['stale_documents']);
    }

    public function test_flipping_the_template_makes_every_chunk_stale_and_re_embedding_is_resumable(): void
    {
        $d = $this->doc('default', 'ops', 'a.md');
        $this->chunk($d, 0, $this->active());
        $this->assertSame(0, app(ReembedStaleService::class)->summary('default')['stale_chunks']);

        config(['kb.embedding_input.contextual' => true]);

        $this->assertSame(1, app(ReembedStaleService::class)->summary('default')['stale_chunks'], 'a template flip is drift');
    }

    public function test_cli_dry_run_prints_the_summary_and_queues_nothing(): void
    {
        $this->chunk($this->doc('default', 'ops', 'a.md'), 0, null);

        $this->artisan('kb:reembed-stale', ['--dry-run' => true])
            ->expectsOutputToContain('1 stale chunk(s) in 1 document(s)')
            ->expectsOutputToContain('Dry run — nothing queued.')
            ->assertExitCode(0);
        Queue::assertNothingPushed();
    }

    public function test_cli_queues_and_restores_the_tenant(): void
    {
        $this->chunk($this->doc('default', 'ops', 'a.md'), 0, null);

        $this->artisan('kb:reembed-stale')->expectsOutputToContain('Queued 1 document(s)')->assertExitCode(0);
        Queue::assertPushed(ReembedDocumentJob::class, 1);
        $this->assertSame('default', app(TenantContext::class)->current());
    }

    public function test_ingestion_status_exposes_the_stale_block_additively(): void
    {
        $this->chunk($this->doc('default', 'ops', 'a.md'), 0, null);

        $block = app(IngestionObservabilityService::class)->staleEmbeddings();

        $this->assertIsArray($block);
        $this->assertSame(1, $block['stale_chunks']);
        $this->assertSame(app(EmbeddingInputTemplate::class)->fingerprint('openai', 'text-embedding-3-small'), $block['active_fingerprint']);
    }

    public function test_ingestion_status_degrades_to_null_when_the_provider_cannot_be_resolved(): void
    {
        $broken = Mockery::mock(EmbeddingCacheService::class);
        $broken->shouldReceive('activeTarget')->andThrow(new \RuntimeException('no embeddings provider configured'));
        $this->app->instance(EmbeddingCacheService::class, $broken);
        $this->app->forgetInstance(ReembedStaleService::class);

        $this->assertNull(app(IngestionObservabilityService::class)->staleEmbeddings(), 'unknown, not a 500 (R14)');
    }
}
