<?php

declare(strict_types=1);

namespace Tests\Feature\Flow;

use App\Ai\EmbeddingsResponse;
use App\Flow\Definitions\IngestDocumentFlow;
use App\Models\KnowledgeChunk;
use App\Services\Kb\EmbeddingCacheService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\PiiRedactor\RedactorEngine;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;
use Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory;
use Padosoft\PiiRedactor\TokenStore\TokenStore;
use Tests\TestCase;

/**
 * v8.23 (Ciclo 4, PR2) — PII redaction on the REAL inline ingestion path.
 *
 * The HTTP `POST /api/kb/ingest` batch and `kb:ingest-folder` CLI run via
 * IngestDocumentJob → {@see IngestDocumentFlow} (chunk-document → embed-chunks →
 * persist-chunks), NOT DocumentIngestor::ingest() directly. This asserts the
 * GDPR contract holds on THAT path: the embedding provider input + the persisted
 * `chunk_text` carry surrogates (never raw PII), the per-tenant vault keeps the
 * original, and a dry-run preview never mints tokens or stores raw PII.
 */
final class IngestDocumentFlowPiiTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'mario.rossi@example.com';

    /** @var list<string> texts handed to the embedding provider */
    private array $embeddedTexts = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');

        $this->embeddedTexts = [];
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(function (array $texts) {
            foreach ($texts as $t) {
                $this->embeddedTexts[] = (string) $t;
            }

            return new EmbeddingsResponse(
                embeddings: array_map(static fn () => [0.1, 0.2, 0.3], $texts),
                provider: 'openai',
                model: 'text-embedding-3-small',
            );
        });
        $this->app->instance(EmbeddingCacheService::class, $cache);
    }

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
        Mockery::close();
    }

    private function enableRedaction(string $strategy, string $tokenStore = 'memory'): void
    {
        config([
            'pii-redactor.enabled' => true,
            'pii-redactor.salt' => 'flow-test-salt',
            'pii-redactor.token_store.driver' => $tokenStore,
            'kb.pii_redactor.enabled' => true,
            'kb.pii_redactor.redact_inline_ingest' => true,
            'kb.pii_redactor.ingest_strategy' => $strategy,
        ]);
        foreach ([RedactorEngine::class, RedactionStrategyFactory::class, RedactionStrategy::class, TokenStore::class] as $a) {
            $this->app->forgetInstance($a);
        }
    }

    private function putDoc(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('tickets/123.md', "# Ticket\n\nContact Mario Rossi at ".self::EMAIL.".\n");
    }

    private function runFlow(bool $dryRun = false): FlowRun
    {
        $input = [
            'tenant_id' => 'default',
            'project_key' => 'support',
            'source_path' => 'tickets/123.md',
            'disk' => 'kb',
            'title' => 'Ticket',
            'metadata' => [],
            'mime_type' => 'text/markdown',
        ];
        $options = FlowExecutionOptions::make(
            idempotencyKey: 'default:support:tickets/123.md'.($dryRun ? ':dry' : ''),
            correlationId: 'default',
        );

        return $dryRun
            ? Flow::dryRun(IngestDocumentFlow::NAME, $input, $options)
            : Flow::execute(IngestDocumentFlow::NAME, $input, $options);
    }

    private function persistedChunkText(): string
    {
        return KnowledgeChunk::query()->get()->pluck('chunk_text')->implode("\n");
    }

    public function test_off_path_leaves_raw_pii_through_the_flow(): void
    {
        config([
            'pii-redactor.enabled' => false,
            'kb.pii_redactor.enabled' => false,
            'kb.pii_redactor.redact_inline_ingest' => false,
        ]);
        $this->putDoc();

        $run = $this->runFlow();

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertStringContainsString(self::EMAIL, $this->persistedChunkText());
        $this->assertStringContainsString(self::EMAIL, implode("\n", $this->embeddedTexts));
    }

    public function test_tokenise_redacts_embeddings_input_and_chunk_text_and_vaults_original(): void
    {
        $this->enableRedaction('tokenise', tokenStore: 'database');
        $this->putDoc();

        $run = $this->runFlow();

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);

        // Persisted vector-store payload carries a surrogate, never the PII.
        $chunkText = $this->persistedChunkText();
        $this->assertStringNotContainsString(self::EMAIL, $chunkText);
        $this->assertMatchesRegularExpression('/\[tok:[A-Za-z0-9_]+:[0-9a-f]+\]/', $chunkText);

        // The embedding provider NEVER saw the raw PII.
        $embedded = implode("\n", $this->embeddedTexts);
        $this->assertNotSame('', $embedded);
        $this->assertStringNotContainsString(self::EMAIL, $embedded);

        // The per-tenant vault keeps the original (R30/R31).
        $this->assertDatabaseHas('pii_token_maps', [
            'tenant_id' => 'default',
            'original' => self::EMAIL,
        ]);
    }

    public function test_dry_run_preview_mints_no_vault_tokens_and_stores_no_raw_pii(): void
    {
        $this->enableRedaction('tokenise', tokenStore: 'database');
        $this->putDoc();

        $run = $this->runFlow(dryRun: true);

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        // A dry-run preview is side-effect-free: it mints NO reversible vault
        // tokens (the redactor falls back to the one-way mask on dry-run) and
        // persists NO chunks — so no raw PII reaches the vector store or vault.
        $this->assertSame(0, DB::table('pii_token_maps')->count());
        $this->assertSame(0, KnowledgeChunk::count());
    }
}
