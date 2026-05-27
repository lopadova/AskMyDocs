<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v8.5 — de-risks the browser streaming E2E (`chat-stream-browser.spec.ts`)
 * WITHOUT the browser: proves that with the deterministic offline
 * `FakeProvider` selected (AI_PROVIDER=fake / AI_EMBEDDINGS_PROVIDER=fake, as
 * the Playwright webServer sets), ingesting any document and running ANY query
 * deterministically returns a citation — so the streaming controller always
 * emits a `source-url` frame (the frame whose wire-format crashed the browser
 * in v8.4).
 *
 * The fake provider's constant embedding vector means every chunk + every
 * query map to the same vector → cosine 1.0 → guaranteed retrieval. Runs on
 * SQLite via the v8.2 PHP-cosine fallback. If this is green, the browser E2E
 * has a real `source-url` + `text` + `finish` stream to validate.
 */
final class FakeProviderRetrievalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.default' => 'fake',
            'ai.embeddings_provider' => 'fake',
            'queue.default' => 'sync',
            'kb.reranking.enabled' => true,
            'kb.hybrid_search.enabled' => false,
        ]);
        app(TenantContext::class)->set('default');
    }

    public function test_fake_provider_makes_retrieval_return_a_citation_for_any_query(): void
    {
        app(DocumentIngestor::class)->ingest(
            'hr-portal',
            new SourceDocument(
                sourcePath: 'policies/remote-work.md',
                mimeType: 'text/markdown',
                bytes: "# Remote Work Policy\n\nEmployees may work remotely up to 3 days per week with manager approval.",
                externalUrl: null,
                externalId: null,
                connectorType: 'local',
                metadata: [],
            ),
            'remote-work',
        );

        // ANY query must retrieve the chunk (constant embedding vector → cosine 1.0).
        $result = app(KbSearchService::class)->searchWithContext(
            query: 'how many remote days',
            projectKey: 'hr-portal',
            limit: 5,
            minSimilarity: 0.30, // production default floor — fake vector scores 1.0
        );

        $this->assertGreaterThan(0, $result->primary->count(), 'fake embeddings must yield a retrieval hit');

        // The chat channel builds an evidence-grade citation → the controller
        // emits a `source-url` frame from it.
        $citations = app(ChatRetrievalService::class)->buildCitations($result);
        $this->assertNotEmpty($citations, 'a source-url citation must be produced');
        $this->assertSame('policies/remote-work.md', $citations[0]['source_path']);
    }
}
