<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Ai\EmbeddingsResponse;
use App\Models\KbPiiSetting;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\EmbeddingCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Padosoft\PiiRedactor\RedactorEngine;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;
use Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory;
use Padosoft\PiiRedactor\TokenStore\TokenStore;
use Tests\TestCase;

/**
 * v8.23 (Ciclo 4, PR2) — inline-path PII redaction in {@see DocumentIngestor}.
 *
 * The GDPR-critical contract: when the policy enables it, the chunk text that
 * reaches the embedding provider + the vector store carries surrogates, NEVER
 * raw PII — and the per-tenant vault keeps the originals for gated re-id.
 * Tested in BOTH states (R43) across both strategies, plus per-project policy
 * and tenant isolation (R30).
 */
final class DocumentIngestorInlinePiiTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'mario.rossi@example.com';

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function markdown(): string
    {
        return "# Support ticket\n\nContact Mario Rossi at ".self::EMAIL." about ticket 123.\n";
    }

    private function fakeEmbeddingCache(): EmbeddingCacheService
    {
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(function (array $texts) {
            $embeddings = array_map(static fn () => array_fill(0, 3, 0.1), array_values($texts));

            return new EmbeddingsResponse(embeddings: $embeddings, provider: 'openai', model: 'text-embedding-3-small');
        });

        return $cache;
    }

    /**
     * Turn the package engine on with the given inline strategy and rebuild the
     * config-derived singletons so they pick up the test config.
     */
    private function enableRedaction(string $strategy, string $tokenStore = 'memory'): void
    {
        config([
            'pii-redactor.enabled' => true,
            'pii-redactor.salt' => 'inline-test-salt',
            'pii-redactor.token_store.driver' => $tokenStore,
            'kb.pii_redactor.enabled' => true,
            'kb.pii_redactor.redact_inline_ingest' => true,
            'kb.pii_redactor.ingest_strategy' => $strategy,
        ]);

        foreach ([RedactorEngine::class, RedactionStrategyFactory::class, RedactionStrategy::class, TokenStore::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    private function ingest(string $projectKey = 'support'): KnowledgeDocument
    {
        $this->app->instance(EmbeddingCacheService::class, $this->fakeEmbeddingCache());

        return app(DocumentIngestor::class)->ingestMarkdown(
            $projectKey,
            'tickets/123.md',
            'Support ticket',
            $this->markdown(),
        );
    }

    private function chunkText(KnowledgeDocument $doc): string
    {
        return KnowledgeChunk::query()->where('knowledge_document_id', $doc->id)->get()
            ->pluck('chunk_text')->implode("\n");
    }

    public function test_off_path_leaves_raw_pii_in_the_chunk_text(): void
    {
        Queue::fake();
        // Every flag off (the default) → no redaction.
        config([
            'pii-redactor.enabled' => false,
            'kb.pii_redactor.enabled' => false,
            'kb.pii_redactor.redact_inline_ingest' => false,
        ]);

        $doc = $this->ingest();

        $this->assertStringContainsString(self::EMAIL, $this->chunkText($doc));
    }

    public function test_master_flag_off_short_circuits_even_with_inline_flag_on(): void
    {
        Queue::fake();
        // Inline knob on but the KB master switch off → still no redaction (R43).
        config([
            'pii-redactor.enabled' => true,
            'kb.pii_redactor.enabled' => false,
            'kb.pii_redactor.redact_inline_ingest' => true,
            'kb.pii_redactor.ingest_strategy' => 'mask',
        ]);
        $this->app->forgetInstance(RedactorEngine::class);

        $doc = $this->ingest();

        $this->assertStringContainsString(self::EMAIL, $this->chunkText($doc));
    }

    public function test_mask_strategy_redacts_the_chunk_text(): void
    {
        Queue::fake();
        $this->enableRedaction('mask');

        $text = $this->chunkText($this->ingest());

        $this->assertStringNotContainsString(self::EMAIL, $text);
        $this->assertStringContainsString('[REDACTED]', $text);
    }

    public function test_tokenise_strategy_writes_surrogates_and_vaults_the_original(): void
    {
        Queue::fake();
        $this->enableRedaction('tokenise', tokenStore: 'database');

        $text = $this->chunkText($this->ingest());

        // The vector-store payload carries a reversible surrogate, never the PII.
        $this->assertStringNotContainsString(self::EMAIL, $text);
        $this->assertMatchesRegularExpression('/\[tok:[A-Za-z0-9_]+:[0-9a-f]+\]/', $text);

        // The per-tenant vault keeps the original (R30/R31 — tenant-scoped row).
        $this->assertDatabaseHas('pii_token_maps', [
            'tenant_id' => 'default',
            'original' => self::EMAIL,
        ]);
    }

    public function test_identical_reingest_short_circuits_before_redaction(): void
    {
        Queue::fake();
        $this->enableRedaction('tokenise', tokenStore: 'database');

        $first = $this->ingest('support');

        // A second ingest of identical content hits the idempotency short-circuit
        // (same version_hash) BEFORE redaction runs. RedactorEngine is `final`
        // (un-mockable), so prove the short-circuit by binding a throwing factory:
        // if the no-op path ever resolved the engine, this test would error
        // (R26 short-circuit proof).
        $this->app->forgetInstance(RedactorEngine::class);
        $this->app->bind(RedactorEngine::class, function (): RedactorEngine {
            throw new \RuntimeException('RedactorEngine must not be resolved on an idempotent re-ingest.');
        });

        $second = $this->ingest('support');

        $this->assertSame($first->id, $second->id);
    }

    public function test_per_project_policy_enables_redaction_when_the_config_default_is_off(): void
    {
        Queue::fake();
        // Engine on, but the tenant-wide inline default is OFF.
        config([
            'pii-redactor.enabled' => true,
            'pii-redactor.salt' => 'inline-test-salt',
            'kb.pii_redactor.enabled' => true,
            'kb.pii_redactor.redact_inline_ingest' => false,
            'kb.pii_redactor.ingest_strategy' => 'mask',
        ]);
        foreach ([RedactorEngine::class, RedactionStrategyFactory::class, RedactionStrategy::class, TokenStore::class] as $a) {
            $this->app->forgetInstance($a);
        }
        // The 'support' project opts IN via a policy row.
        KbPiiSetting::create([
            'tenant_id' => 'default',
            'project_key' => 'support',
            'redact_enabled' => true,
            'strategy' => 'mask',
        ]);

        // 'support' redacts; 'sales' (no row, default off) does not.
        $this->assertStringNotContainsString(self::EMAIL, $this->chunkText($this->ingest('support')));
        $this->assertStringContainsString(self::EMAIL, $this->chunkText($this->ingest('sales')));
    }
}
