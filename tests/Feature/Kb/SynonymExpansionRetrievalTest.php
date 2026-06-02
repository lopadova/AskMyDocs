<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Ai\EmbeddingsResponse;
use App\Models\KbSynonym;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Reranker;
use App\Services\Kb\Retrieval\RetrievalFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * v8.7/W1 — proves KbSearchService embeds the SYNONYM-EXPANDED query text
 * when synonym groups exist for the active (tenant, project) and the
 * feature is enabled, and embeds the verbatim query when it is disabled.
 *
 * The assertion targets the embedding-generation call directly (rather
 * than a fake-embedding similarity outcome) so it is deterministic and
 * driver-independent — the wiring is what we are guaranteeing.
 */
final class SynonymExpansionRetrievalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('kb.synonyms.cache_ttl_seconds', 0);
        config()->set('kb.synonyms.enabled', true);
        Cache::flush();
    }

    private function embeddingResponse(): EmbeddingsResponse
    {
        return new EmbeddingsResponse(
            embeddings: [array_fill(0, 1536, 0.0)],
            provider: 'test',
            model: 'test',
        );
    }

    private function rerankerStub(): Reranker
    {
        $reranker = Mockery::mock(Reranker::class);
        $reranker->shouldReceive('rerank')->andReturn(collect());

        return $reranker;
    }

    public function test_search_embeds_expanded_text_when_synonyms_configured(): void
    {
        KbSynonym::create([
            'project_key' => 'eng',
            'term' => 'k8s',
            'synonyms' => ['kubernetes'],
            'enabled' => true,
        ]);

        $embedding = Mockery::mock(EmbeddingCacheService::class);
        $embedding->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(fn (array $texts): bool => isset($texts[0]) && str_contains($texts[0], 'kubernetes')))
            ->andReturn($this->embeddingResponse());

        $service = new KbSearchService($embedding, $this->rerankerStub());
        $service->search('deploy k8s', 'eng');

        // Mockery expectations verified on tearDown.
        $this->addToAssertionCount(1);
    }

    public function test_search_expands_when_project_comes_from_filters_not_legacy_arg(): void
    {
        // The chat path passes projectKey=null and scopes via
        // RetrievalFilters::projectKeys. Synonym expansion must still key
        // on that effective project (Copilot review fix).
        KbSynonym::create([
            'project_key' => 'eng',
            'term' => 'k8s',
            'synonyms' => ['kubernetes'],
            'enabled' => true,
        ]);

        $embedding = Mockery::mock(EmbeddingCacheService::class);
        $embedding->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(fn (array $texts): bool => isset($texts[0]) && str_contains($texts[0], 'kubernetes')))
            ->andReturn($this->embeddingResponse());

        $service = new KbSearchService($embedding, $this->rerankerStub());
        $service->search('deploy k8s', null, 8, 0.30, new RetrievalFilters(projectKeys: ['eng']));

        $this->addToAssertionCount(1);
    }

    public function test_search_embeds_verbatim_text_when_feature_disabled(): void
    {
        KbSynonym::create([
            'project_key' => 'eng',
            'term' => 'k8s',
            'synonyms' => ['kubernetes'],
            'enabled' => true,
        ]);
        config()->set('kb.synonyms.enabled', false);

        $embedding = Mockery::mock(EmbeddingCacheService::class);
        $embedding->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(fn (array $texts): bool => isset($texts[0])
                && $texts[0] === 'deploy k8s'
                && ! str_contains($texts[0], 'kubernetes')))
            ->andReturn($this->embeddingResponse());

        $service = new KbSearchService($embedding, $this->rerankerStub());
        $service->search('deploy k8s', 'eng');

        $this->addToAssertionCount(1);
    }
}
