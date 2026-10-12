<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Investigation;

use App\Ai\EmbeddingsResponse;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Reranker;
use App\Services\Kb\Retrieval\GraphExpander;
use App\Services\Kb\Retrieval\RejectedApproachInjector;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Services\Kb\Retrieval\SynonymExpander;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class InvestigationRetrievalContextTest extends TestCase
{
    public function test_primary_retrieval_never_queries_runner_up_or_unused_context(): void
    {
        [$service, $primary, $filters] = $this->service(false);
        $database = DB::getFacadeRoot();
        try {
            // fetchRunnerUp consults the database driver before its vector query.
            DB::shouldReceive('connection')->never();
            $result = $service->retrieve('CASE-A', 'demo', $filters, includeAuxiliaryContext: false);
        } finally {
            DB::swap($database);
        }

        $this->assertSame($primary, $result->primary);
        $this->assertTrue($result->runnerUp()->isEmpty());
        $this->assertTrue($result->expanded->isEmpty());
        $this->assertTrue($result->rejected->isEmpty());
        $this->assertSame(1, $result->meta['primary_count']);
        $this->assertFalse($result->meta['search_strategy']['graph_expansion_enabled']);
        $this->assertFalse($result->meta['search_strategy']['rejected_injection_enabled']);
    }

    public function test_default_retrieval_still_collects_auxiliary_context(): void
    {
        [$service, $primary, $filters] = $this->service(true);
        $database = DB::getFacadeRoot();
        try {
            DB::shouldReceive('connection->getDriverName')->once()->andReturn('sqlite');
            $result = $service->retrieve('CASE-A', 'demo', $filters);
        } finally {
            DB::swap($database);
        }

        $this->assertSame($primary, $result->primary);
        $this->assertSame([['chunk_id' => 2]], $result->expanded->all());
        $this->assertSame([['chunk_id' => 3]], $result->rejected->all());
    }

    private function service(bool $includeContext): array
    {
        config(['kb.default_limit' => 8, 'kb.default_min_similarity' => .30, 'kb.synonyms.enabled' => false]);
        $vector = [.1, .2, .3];
        $primary = collect([['chunk_id' => 1, 'vector_score' => .9]]);
        $filters = new RetrievalFilters(projectKeys: ['demo']);
        $embeddings = Mockery::mock(EmbeddingCacheService::class);
        $embeddings->shouldReceive('generate')->once()->with(['CASE-A'])->andReturn(new EmbeddingsResponse([$vector], 'fake', 'fake'));
        $synonyms = new SynonymExpander();
        $graph = Mockery::mock(GraphExpander::class);
        $rejected = Mockery::mock(RejectedApproachInjector::class);
        if ($includeContext) {
            $graph->shouldReceive('expand')->once()->with($primary, 'demo')->andReturn(collect([['chunk_id' => 2]]));
            $rejected->shouldReceive('pick')->once()->with('CASE-A', 'demo')->andReturn(collect([['chunk_id' => 3]]));
        } else {
            $graph->shouldNotReceive('expand');
            $rejected->shouldNotReceive('pick');
        }
        $search = Mockery::mock(KbSearchService::class, [$embeddings, Mockery::mock(Reranker::class), $graph, $rejected, $synonyms])->makePartial();
        $search->shouldReceive('search')->once()->with('CASE-A', 'demo', 8, .30, $filters, $vector)->andReturn($primary);

        return [new ChatRetrievalService($search), $primary, $filters];
    }
}
