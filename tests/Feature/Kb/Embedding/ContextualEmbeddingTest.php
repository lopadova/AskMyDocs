<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Embedding;

use App\Ai\EmbeddingsResponse;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\Embedding\EmbeddingInputTemplate;
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
 * v8.42/W2 (ADR 0035 §D2) — the contextual embedding input and the stored
 * fingerprint, end to end through the real ingestor, in BOTH flag states (R43).
 */
final class ContextualEmbeddingTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<list<string>> every batch of texts the provider fake received */
    private array $embedded = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->embedded = [];

        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(function (array $texts) {
            $this->embedded[] = array_values($texts);

            return new EmbeddingsResponse(
                embeddings: array_map(static fn () => [0.1, 0.2, 0.3], array_values($texts)),
                provider: 'openai',
                model: 'text-embedding-3-small',
            );
        });
        $this->app->instance(EmbeddingCacheService::class, $cache);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function ingest(string $title = 'Manuale Magazzino'): KnowledgeDocument
    {
        return app(DocumentIngestor::class)->ingestMarkdown(
            'ops',
            'docs/magazzino.md',
            $title,
            "# Magazzino Milano\n\nIl timeout è 30 secondi per le chiamate API.\n",
        );
    }

    public function test_off_sends_the_bare_chunk_text_to_the_provider(): void
    {
        config(['kb.embedding_input.contextual' => false]);

        $doc = $this->ingest();

        $this->assertCount(1, $this->embedded);
        $bare = KnowledgeChunk::query()->where('knowledge_document_id', $doc->id)->pluck('chunk_text')->all();
        $this->assertSame($bare, $this->embedded[0], 'OFF: provider input === persisted chunk text, byte for byte');
    }

    public function test_on_sends_title_and_section_but_persists_the_bare_chunk(): void
    {
        config(['kb.embedding_input.contextual' => true]);

        $doc = $this->ingest();

        $input = $this->embedded[0][0];
        $this->assertStringStartsWith('title: Manuale Magazzino | section: ', $input);
        $this->assertStringContainsString('| text: ', $input);

        $chunk = KnowledgeChunk::query()->where('knowledge_document_id', $doc->id)->firstOrFail();
        $this->assertStringNotContainsString('title:', (string) $chunk->chunk_text, 'chunk_text stays bare');
        $this->assertSame(hash('sha256', (string) $chunk->chunk_text), $chunk->chunk_hash, 'chunk_hash is over the bare text — idempotency unchanged');
    }

    public function test_every_chunk_records_the_fingerprint_of_the_recipe_that_produced_it(): void
    {
        config(['kb.embedding_input.contextual' => false]);
        $doc = $this->ingest();
        $off = app(EmbeddingInputTemplate::class)->fingerprint('openai', 'text-embedding-3-small');

        $this->assertSame([$off], KnowledgeChunk::query()->where('knowledge_document_id', $doc->id)->pluck('embedding_fingerprint')->unique()->values()->all());

        config(['kb.embedding_input.contextual' => true]);
        $on = app(EmbeddingInputTemplate::class)->fingerprint('openai', 'text-embedding-3-small');
        $this->assertNotSame($off, $on);
    }

    public function test_pii_in_the_title_never_reaches_the_provider_when_redaction_is_active(): void
    {
        $email = 'mario.rossi@example.com';
        config([
            'kb.embedding_input.contextual' => true,
            'pii-redactor.enabled' => true,
            'pii-redactor.salt' => 'ctx-test-salt',
            'pii-redactor.token_store.driver' => 'memory',
            'kb.pii_redactor.enabled' => true,
            'kb.pii_redactor.redact_inline_ingest' => true,
            'kb.pii_redactor.ingest_strategy' => 'mask',
        ]);
        foreach ([RedactorEngine::class, RedactionStrategyFactory::class, RedactionStrategy::class, TokenStore::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $this->ingest('Ticket di '.$email);

        $sent = implode("\n", $this->embedded[0]);
        $this->assertStringNotContainsString($email, $sent, 'a PII-bearing title must be redacted before the first embedding');
        $this->assertStringStartsWith('title: ', $sent);
    }

    public function test_pii_in_the_title_is_left_alone_when_redaction_is_off(): void
    {
        $email = 'mario.rossi@example.com';
        config(['kb.embedding_input.contextual' => true, 'pii-redactor.enabled' => false, 'kb.pii_redactor.enabled' => false]);

        $this->ingest('Ticket di '.$email);

        $this->assertStringContainsString($email, $this->embedded[0][0], 'OFF path: no redaction, by design');
    }
}
