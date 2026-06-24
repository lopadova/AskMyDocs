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
        $this->assertDatabaseHas('pii_token_maps', ['tenant_id' => 'default', 'original' => self::EMAIL]);
    }

    public function test_service_queues_one_job_per_live_document_tenant_scoped(): void
    {
        Queue::fake();

        $this->makeDoc('default', 'support', 'a.md');
        $this->makeDoc('default', 'support', 'b.md');
        $this->makeDoc('default', 'other', 'c.md');     // different project
        $this->makeDoc('globex', 'support', 'd.md');     // different tenant

        $queued = app(ReembedProjectService::class)->reembedProject('default', 'support');

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
            'tenant_id' => 'default', 'project_key' => 'support', 'source_type' => 'markdown',
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

        (new ReembedDocumentJob($doc->id, 'default'))->handle(app(TenantContext::class), app(DocumentIngestor::class));

        $text = KnowledgeChunk::where('knowledge_document_id', $doc->id)->get()->pluck('chunk_text')->implode("\n");
        $this->assertStringNotContainsString('[REDACTED]', $text);
        $this->assertMatchesRegularExpression('/\[tok:[A-Za-z0-9_]+:[0-9a-f]+\]/', $text);
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
