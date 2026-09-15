<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Pii;

use App\Ai\EmbeddingsResponse;
use App\Jobs\ReembedDocumentJob;
use App\Models\KbPiiSetting;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\Pii\ReembedProjectService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Padosoft\PiiRedactor\RedactorEngine;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;
use Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory;
use Padosoft\PiiRedactor\TokenStore\TokenStore;
use Tests\TestCase;

/**
 * v8.23 (Ciclo 4, PR5) — re-embed on PII-policy change: the force-reembed seam
 * replaces a document's chunks under the new policy; the service fans out one
 * job per project document; the job re-derives a document from disk.
 */
final class ReembedTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'mario.rossi@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        config(['kb.sources.disk' => 'kb', 'kb.sources.path_prefix' => '']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function fakeEmbeddingCache(): void
    {
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(fn (array $texts) => new EmbeddingsResponse(
            embeddings: array_map(static fn () => [0.1, 0.2, 0.3], $texts),
            provider: 'openai',
            model: 'text-embedding-3-small',
        ));
        $this->app->instance(EmbeddingCacheService::class, $cache);
    }

    private function setPolicy(string $strategy): void
    {
        config([
            'pii-redactor.enabled' => true,
            'pii-redactor.salt' => 'reembed-salt',
            'pii-redactor.token_store.driver' => 'database',
            'kb.pii_redactor.enabled' => true,
            'kb.pii_redactor.redact_inline_ingest' => true,
            'kb.pii_redactor.ingest_strategy' => $strategy,
        ]);
        foreach ([RedactorEngine::class, RedactionStrategyFactory::class, RedactionStrategy::class, TokenStore::class] as $a) {
            $this->app->forgetInstance($a);
        }
    }

    private function markdown(): string
    {
        return "# Ticket\n\nContact Mario Rossi at ".self::EMAIL.".\n";
    }

    private function ingest(): KnowledgeDocument
    {
        $this->fakeEmbeddingCache();

        return app(DocumentIngestor::class)->ingestMarkdown('support', 'tickets/1.md', 'Ticket', $this->markdown());
    }

    public function test_force_reembed_replaces_chunks_under_the_new_policy(): void
    {
        Queue::fake();

        // First ingest under MASK → chunk text masked.
        $this->setPolicy('mask');
        $doc = $this->ingest();
        $masked = KnowledgeChunk::where('knowledge_document_id', $doc->id)->get();
        $this->assertGreaterThan(0, $masked->count());
        $maskedCount = $masked->count();
        $this->assertStringContainsString('[REDACTED]', $masked->pluck('chunk_text')->implode("\n"));

        // Switch policy to TOKENISE and force re-embed the SAME content.
        $this->setPolicy('tokenise');
        $this->fakeEmbeddingCache();
        app(DocumentIngestor::class)->ingest(
            projectKey: 'support',
            source: new \App\Services\Kb\Pipeline\SourceDocument(
                sourcePath: 'tickets/1.md', mimeType: 'text/markdown', bytes: $this->markdown(),
                externalUrl: null, externalId: null, connectorType: 'local', metadata: [],
            ),
            title: 'Ticket',
            forceReembed: true,
        );

        $after = KnowledgeChunk::where('knowledge_document_id', $doc->id)->get();
        $text = $after->pluck('chunk_text')->implode("\n");
        // Chunks REPLACED (not accumulated) and now tokenised, not masked.
        $this->assertSame($maskedCount, $after->count());
        $this->assertStringNotContainsString('[REDACTED]', $text);
        $this->assertStringNotContainsString(self::EMAIL, $text);
        $this->assertMatchesRegularExpression('/\[tok:[A-Za-z0-9_]+:[0-9a-f]+\]/', $text);
        $this->assertDatabaseHas('pii_token_maps', ['tenant_id' => 'test-tenant', 'original' => self::EMAIL]);
    }

    public function test_service_queues_one_job_per_live_document_tenant_scoped(): void
    {
        Queue::fake();

        $this->makeDoc('test-tenant', 'support', 'a.md');
        $this->makeDoc('test-tenant', 'support', 'b.md');
        $this->makeDoc('test-tenant', 'other', 'c.md');     // different project
        $this->makeDoc('globex', 'support', 'd.md');     // different tenant

        $queued = app(ReembedProjectService::class)->reembedProject('test-tenant', 'support');

        $this->assertSame(2, $queued);
        Queue::assertPushed(ReembedDocumentJob::class, 2);
    }

    public function test_job_reembeds_a_document_from_disk_under_the_current_policy(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('tickets/1.md', $this->markdown());
        $this->fakeEmbeddingCache();

        // Seed a document (version_hash = hash of its disk markdown, as a real
        // ingest would store) + a masked chunk as if ingested under the OLD policy.
        $hash = hash('sha256', $this->markdown());
        $doc = KnowledgeDocument::create([
            'tenant_id' => 'test-tenant', 'project_key' => 'support', 'source_type' => 'markdown',
            'title' => 'Ticket', 'source_path' => 'tickets/1.md', 'language' => 'en',
            'access_scope' => 'internal', 'status' => 'active',
            'document_hash' => $hash, 'version_hash' => $hash,
        ]);
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id, 'project_key' => 'support', 'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'old'), 'heading_path' => '', 'chunk_text' => 'old [REDACTED] chunk',
            'metadata' => [], 'embedding' => [0.1, 0.2, 0.3],
        ]);

        $this->setPolicy('tokenise');
        $this->fakeEmbeddingCache();

        (new ReembedDocumentJob($doc->id, 'test-tenant'))->handle(app(TenantContext::class), app(DocumentIngestor::class));

        $text = KnowledgeChunk::where('knowledge_document_id', $doc->id)->get()->pluck('chunk_text')->implode("\n");
        $this->assertStringNotContainsString('[REDACTED]', $text);
        $this->assertMatchesRegularExpression('/\[tok:[A-Za-z0-9_]+:[0-9a-f]+\]/', $text);
    }

    /**
     * v8.36 / ADR 0030 §5 — a `markdown_only` row whose original was dropped
     * is re-embedded from its stored artifact WITHOUT a converter: the row's
     * mime is a binary format (PDF) whose converter would fail on Markdown
     * bytes or start an OCR run; the chunks are replaced, the row keeps its
     * identity, no new version appears.
     */
    public function test_job_reembeds_a_markdown_only_row_from_its_artifact_without_a_converter(): void
    {
        Storage::fake('kb');
        config(['kb.ocr.enabled' => false, 'kb.pdf.pdftotext_bin' => '/nonexistent/pdftotext']);
        $this->fakeEmbeddingCache();
        // A PDF/OCR artifact carries the `## Page N` markers the PDF chunker slices on.
        $markdown = "# 1.pdf\n\n## Page 1\n\nContact Mario Rossi at ".self::EMAIL.".\n";
        $hash = hash('sha256', $markdown);
        $store = app(\App\Services\Kb\Versioning\ConversionArtifactStore::class);
        $artifactPath = $store->pathFor('test-tenant', 'support', 'scans/1.pdf', $hash);
        Storage::disk('kb')->put($artifactPath, $markdown);

        $doc = KnowledgeDocument::create([
            'tenant_id' => 'test-tenant', 'project_key' => 'support', 'source_type' => 'pdf', 'mime_type' => 'application/pdf',
            'title' => 'Scan', 'source_path' => 'scans/1.pdf', 'language' => 'en',
            'access_scope' => 'internal', 'status' => 'active',
            'document_hash' => $hash, 'version_hash' => $hash, 'content_hash' => $hash,
            'markdown_path' => $artifactPath,
            'metadata' => ['disk' => 'kb', 'prefix' => '', 'source_dropped' => true, 'converter' => ['converter' => 'pdf-converter']],
        ]);
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id, 'project_key' => 'support', 'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'old'), 'heading_path' => '', 'chunk_text' => 'old [REDACTED] chunk',
            'metadata' => [], 'embedding' => [0.1, 0.2, 0.3],
        ]);

        $this->setPolicy('tokenise');
        $this->fakeEmbeddingCache();
        // The original is NOT on the disk: only the artifact is.
        Storage::disk('kb')->assertMissing('scans/1.pdf');

        (new ReembedDocumentJob($doc->id, 'test-tenant'))->handle(app(TenantContext::class), app(DocumentIngestor::class));

        $this->assertSame(1, KnowledgeDocument::withoutGlobalScopes()->where('source_path', 'scans/1.pdf')->count(), 'no new version');
        $fresh = $doc->fresh();
        $this->assertSame('application/pdf', $fresh->mime_type);
        $this->assertSame('pdf', $fresh->source_type);
        $this->assertSame($hash, $fresh->version_hash);
        $text = KnowledgeChunk::where('knowledge_document_id', $doc->id)->get()->pluck('chunk_text')->implode("\n");
        $this->assertStringNotContainsString('[REDACTED]', $text);
        $this->assertStringNotContainsString(self::EMAIL, $text);
        $this->assertMatchesRegularExpression('/\[tok:[A-Za-z0-9_]+:[0-9a-f]+\]/', $text);
    }

    /**
     * R14 — an artifact the row's source-type chunker cannot slice (no
     * `## Page N` markers on a PDF row) still re-embeds through the generic
     * Markdown chunker instead of replacing the live chunks with nothing.
     */
    public function test_job_falls_back_to_the_markdown_chunker_when_the_artifact_has_no_page_markers(): void
    {
        Storage::fake('kb');
        config(['kb.ocr.enabled' => false, 'kb.pdf.pdftotext_bin' => '/nonexistent/pdftotext']);
        $this->fakeEmbeddingCache();
        $markdown = "# Scan\n\nContact Mario Rossi at ".self::EMAIL.".\n";
        $hash = hash('sha256', $markdown);
        $store = app(\App\Services\Kb\Versioning\ConversionArtifactStore::class);
        $artifactPath = $store->pathFor('test-tenant', 'support', 'scans/3.pdf', $hash);
        Storage::disk('kb')->put($artifactPath, $markdown);
        $doc = KnowledgeDocument::create([
            'tenant_id' => 'test-tenant', 'project_key' => 'support', 'source_type' => 'pdf', 'mime_type' => 'application/pdf',
            'title' => 'Scan', 'source_path' => 'scans/3.pdf', 'language' => 'en',
            'access_scope' => 'internal', 'status' => 'active',
            'document_hash' => $hash, 'version_hash' => $hash, 'content_hash' => $hash,
            'markdown_path' => $artifactPath, 'metadata' => ['disk' => 'kb', 'prefix' => '', 'source_dropped' => true],
        ]);
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id, 'project_key' => 'support', 'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'old'), 'heading_path' => '', 'chunk_text' => 'old [REDACTED] chunk',
            'metadata' => [], 'embedding' => [0.1, 0.2, 0.3],
        ]);
        $this->setPolicy('tokenise');
        $this->fakeEmbeddingCache();

        (new ReembedDocumentJob($doc->id, 'test-tenant'))->handle(app(TenantContext::class), app(DocumentIngestor::class));

        $chunks = KnowledgeChunk::where('knowledge_document_id', $doc->id)->get();
        $this->assertGreaterThan(0, $chunks->count(), 'the live chunks must never be replaced by nothing');
        $text = $chunks->pluck('chunk_text')->implode("\n");
        $this->assertStringNotContainsString('[REDACTED]', $text);
        $this->assertStringNotContainsString(self::EMAIL, $text);
        $this->assertMatchesRegularExpression('/\[tok:[A-Za-z0-9_]+:[0-9a-f]+\]/', $text);
    }

    /** The artifact is read from the disk the version RECORDED, not from the connector's current one. */
    public function test_job_reads_the_artifact_from_the_versions_recorded_disk(): void
    {
        Storage::fake('kb');
        Storage::fake('kb-archive');
        config(['kb.ocr.enabled' => false, 'kb.pdf.pdftotext_bin' => '/nonexistent/pdftotext', 'kb.sources.disk' => 'kb']);
        $this->fakeEmbeddingCache();
        $markdown = "# 4.pdf\n\n## Page 1\n\nContact Mario Rossi at ".self::EMAIL.".\n";
        $hash = hash('sha256', $markdown);
        $store = app(\App\Services\Kb\Versioning\ConversionArtifactStore::class);
        $artifactPath = $store->pathFor('test-tenant', 'support', 'scans/4.pdf', $hash);
        Storage::disk('kb-archive')->put($artifactPath, $markdown); // only on the recorded disk
        $doc = KnowledgeDocument::create([
            'tenant_id' => 'test-tenant', 'project_key' => 'support', 'source_type' => 'pdf', 'mime_type' => 'application/pdf',
            'title' => 'Scan', 'source_path' => 'scans/4.pdf', 'language' => 'en',
            'access_scope' => 'internal', 'status' => 'active',
            'document_hash' => $hash, 'version_hash' => $hash, 'content_hash' => $hash,
            'markdown_path' => $artifactPath, 'metadata' => ['disk' => 'kb-archive', 'prefix' => '', 'source_dropped' => true],
        ]);
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id, 'project_key' => 'support', 'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'old'), 'heading_path' => '', 'chunk_text' => 'old [REDACTED] chunk',
            'metadata' => [], 'embedding' => [0.1, 0.2, 0.3],
        ]);
        $this->setPolicy('tokenise');
        $this->fakeEmbeddingCache();

        (new ReembedDocumentJob($doc->id, 'test-tenant'))->handle(app(TenantContext::class), app(DocumentIngestor::class));

        $text = KnowledgeChunk::where('knowledge_document_id', $doc->id)->get()->pluck('chunk_text')->implode("\n");
        $this->assertStringNotContainsString('[REDACTED]', $text);
        $this->assertMatchesRegularExpression('/\[tok:[A-Za-z0-9_]+:[0-9a-f]+\]/', $text);
    }

    /** The source is read from the namespace the version RECORDED: a different file at the connector's current path never mints a new version. */
    public function test_job_reads_the_source_from_the_versions_recorded_namespace(): void
    {
        Storage::fake('kb');
        Storage::fake('kb-archive');
        config(['kb.sources.disk' => 'kb', 'kb.sources.path_prefix' => '']);
        $this->fakeEmbeddingCache();
        $recorded = "# Recorded\n\nContact Mario Rossi at ".self::EMAIL.".\n";
        Storage::disk('kb-archive')->put('old/notes/5.md', $recorded);   // the version's own bytes, under its recorded prefix
        Storage::disk('kb')->put('notes/5.md', "# Another file entirely\n\nWritten later at the current path.\n");
        $hash = hash('sha256', $recorded);
        $doc = KnowledgeDocument::create([
            'tenant_id' => 'test-tenant', 'project_key' => 'support', 'source_type' => 'markdown', 'mime_type' => 'text/markdown',
            'title' => 'Notes', 'source_path' => 'notes/5.md', 'language' => 'en',
            'access_scope' => 'internal', 'status' => 'active',
            'document_hash' => $hash, 'version_hash' => $hash,
            'metadata' => ['disk' => 'kb-archive', 'prefix' => 'old'],
        ]);
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id, 'project_key' => 'support', 'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'old'), 'heading_path' => '', 'chunk_text' => 'old [REDACTED] chunk',
            'metadata' => [], 'embedding' => [0.1, 0.2, 0.3],
        ]);
        $this->setPolicy('tokenise');
        $this->fakeEmbeddingCache();

        (new ReembedDocumentJob($doc->id, 'test-tenant'))->handle(app(TenantContext::class), app(DocumentIngestor::class));

        $this->assertSame(1, KnowledgeDocument::withoutGlobalScopes()->where('source_path', 'notes/5.md')->count(), 'no new version from the file at the current path');
        $text = KnowledgeChunk::where('knowledge_document_id', $doc->id)->get()->pluck('chunk_text')->implode("\n");
        $this->assertStringNotContainsString('Another file entirely', $text);
        $this->assertStringNotContainsString('[REDACTED]', $text);
        $this->assertMatchesRegularExpression('/\[tok:[A-Za-z0-9_]+:[0-9a-f]+\]/', $text);
    }

    /** R14 — an artifact that no longer hashes to the version is a logged skip, never a new version or a converter run. */
    public function test_job_skips_a_corrupt_artifact(): void
    {
        Storage::fake('kb');
        config(['kb.ocr.enabled' => false, 'kb.pdf.pdftotext_bin' => '/nonexistent/pdftotext']);
        $this->fakeEmbeddingCache();
        $hash = hash('sha256', 'the original markdown');
        $store = app(\App\Services\Kb\Versioning\ConversionArtifactStore::class);
        $artifactPath = $store->pathFor('test-tenant', 'support', 'scans/2.pdf', $hash);
        Storage::disk('kb')->put($artifactPath, 'tampered');
        $doc = KnowledgeDocument::create([
            'tenant_id' => 'test-tenant', 'project_key' => 'support', 'source_type' => 'pdf', 'mime_type' => 'application/pdf',
            'title' => 'Scan', 'source_path' => 'scans/2.pdf', 'language' => 'en',
            'access_scope' => 'internal', 'status' => 'active',
            'document_hash' => $hash, 'version_hash' => $hash, 'content_hash' => $hash,
            'markdown_path' => $artifactPath, 'metadata' => ['disk' => 'kb', 'prefix' => ''],
        ]);
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id, 'project_key' => 'support', 'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'kept'), 'heading_path' => '', 'chunk_text' => 'kept chunk',
            'metadata' => [], 'embedding' => [0.1, 0.2, 0.3],
        ]);

        (new ReembedDocumentJob($doc->id, 'test-tenant'))->handle(app(TenantContext::class), app(DocumentIngestor::class));

        $this->assertSame('kept chunk', KnowledgeChunk::where('knowledge_document_id', $doc->id)->value('chunk_text'));
        $this->assertSame(1, KnowledgeDocument::withoutGlobalScopes()->where('source_path', 'scans/2.pdf')->count());
    }

    public function test_job_skips_cleanly_when_the_source_is_missing_on_disk(): void
    {
        Storage::fake('kb'); // empty disk — the source file does not exist
        $doc = $this->makeDoc('test-tenant', 'support', 'tickets/missing.md');
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id, 'project_key' => 'support', 'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'kept'), 'heading_path' => '', 'chunk_text' => 'kept chunk',
            'metadata' => [], 'embedding' => [0.1, 0.2, 0.3],
        ]);

        // Must NOT throw (logged skip), and the existing chunk is left intact.
        (new ReembedDocumentJob($doc->id, 'test-tenant'))->handle(app(TenantContext::class), app(DocumentIngestor::class));

        $this->assertSame('kept chunk', KnowledgeChunk::where('knowledge_document_id', $doc->id)->value('chunk_text'));
    }

    private function makeDoc(string $tenant, string $project, string $path): KnowledgeDocument
    {
        $tenants = app(TenantContext::class);
        $previous = $tenants->current();
        $tenants->set($tenant);
        try {
            return KnowledgeDocument::create([
                'tenant_id' => $tenant,
                'project_key' => $project,
                'source_type' => 'markdown',
                'title' => 'Doc',
                'source_path' => $path,
                'language' => 'en',
                'access_scope' => 'internal',
                'status' => 'active',
                'document_hash' => hash('sha256', $tenant.$path),
                'version_hash' => hash('sha256', $tenant.$path.'v'),
            ]);
        } finally {
            $tenants->set($previous);
        }
    }
}
