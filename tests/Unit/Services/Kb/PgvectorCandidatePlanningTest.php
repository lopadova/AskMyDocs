<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb;

use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Reranker;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Support\TenantContext;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDO;
use ReflectionMethod;
use Tests\TestCase;

final class PgvectorCandidatePlanningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('plan-tenant');
        config(['rbac.enforced' => false, 'kb.mentions.mode' => 'filter', 'kb.runner_up.min_similarity' => 0.2]);
    }

    public function test_semantic_query_uses_hnsw_order_with_scoped_filters_and_transaction_local_scanning(): void
    {
        $capture = $this->capture('0.8.0', 'semanticCandidates');
        $query = $this->chunkQuery($capture);

        $this->assertStringContainsString('order by embedding <=> ?::vector ASC limit 24', $query['query']);
        $this->assertStringNotContainsString('order by "vector_score" desc', $query['query']);
        $this->assertStringContainsString('"knowledge_chunks"."tenant_id" = ?', $query['query']);
        $this->assertStringContainsString('"knowledge_documents"."tenant_id" = ?', $query['query']);
        $this->assertStringContainsString('"knowledge_chunks"."project_key" = ?', $query['query']);
        $this->assertStringContainsString('"source_type" in (?)', $query['query']);
        $this->assertStringContainsString('"knowledge_documents"."deleted_at" is null', $query['query']);
        foreach (['plan-tenant', 'orders', 'archived', 'markdown', 'en', 501] as $binding) {
            $this->assertContains($binding, $query['bindings']);
        }
        $this->assertSame("SET LOCAL hnsw.iterative_scan = 'strict_order'", $capture['queries'][0]['query']);
        $this->assertSame([1, 1], array_column($capture['execution'], 'transaction_level'));
        $this->assertSame(0, $capture['final_transaction_level']);
    }

    public function test_runner_up_query_uses_the_same_index_order_and_preserves_primary_exclusions(): void
    {
        $capture = $this->capture('0.8.7', 'fetchRunnerUp');
        $query = $this->chunkQuery($capture);

        $this->assertSame("SET LOCAL hnsw.iterative_scan = 'strict_order'", $capture['queries'][0]['query']);
        $this->assertStringContainsString('order by embedding <=> ?::vector ASC limit 15', $query['query']);
        $this->assertStringContainsString('"knowledge_chunks"."id" not in (?)', $query['query']);
        $this->assertStringContainsString('"knowledge_chunks"."tenant_id" = ?', $query['query']);
        foreach (['plan-tenant', 'orders', 'archived', 'markdown', 71] as $binding) {
            $this->assertContains($binding, $query['bindings']);
        }
        $this->assertSame(0, $capture['final_transaction_level']);
    }

    public function test_similarity_floor_is_applied_after_nearest_candidates_and_rejects_null_scores(): void
    {
        $rows = [];
        foreach ([0.9, 0.3, 0.299, null] as $index => $score) {
            $rows[] = (object) ['id' => $index + 1, 'knowledge_document_id' => 501,
                'tenant_id' => 'plan-tenant', 'project_key' => 'orders', 'vector_score' => $score];
        }
        $capture = $this->capture('0.8.7', 'semanticCandidates', $rows);
        $query = $this->chunkQuery($capture);

        $this->assertSame([1, 2], $capture['result']->pluck('id')->all());
        $this->assertStringNotContainsString('(1 - (embedding <=> ?::vector)) >= ?', $query['query']);
        $this->assertNotContains(0.3, $query['bindings']);
        $this->assertSame([0, 1], $capture['result']->keys()->all());
    }

    public function test_older_or_missing_pgvector_version_preserves_exact_order_and_floor(): void
    {
        foreach (['0.7.4', null] as $version) {
            foreach (['semanticCandidates', 'fetchRunnerUp'] as $method) {
                $capture = $this->capture($version, $method);
                $query = $this->chunkQuery($capture);

                $this->assertCount(1, $capture['queries']);
                $this->assertStringContainsString('order by "vector_score" desc limit ', $query['query']);
                $this->assertStringContainsString('(1 - (embedding <=> ?::vector)) >= ?', $query['query']);
                $this->assertContains($method === 'semanticCandidates' ? 0.3 : 0.2, $query['bindings']);
                $this->assertSame([0], array_column($capture['execution'], 'transaction_level'));
                $this->assertSame(0, $capture['final_transaction_level']);
            }
        }
    }

    private function chunkQuery(array $capture): array
    {
        return collect($capture['queries'])->first(fn (array $query): bool => str_contains($query['query'], 'from "knowledge_chunks"'));
    }

    /** PostgreSQL grammar is exercised without a PostgreSQL server or an AI call. */
    private function capture(?string $version, string $method, array $rows = []): array
    {
        $connection = new class(new PDO('sqlite::memory:'), '', '', ['driver' => 'pgsql', 'name' => 'pgvector-plan']) extends PostgresConnection
        {
            public ?string $vectorVersion = null;
            public array $versionReads = [];
            public array $chunkRows = [];

            public function selectOne($query, $bindings = [], $useReadPdo = true)
            {
                if ($query === 'SELECT extversion FROM pg_extension WHERE extname = ?') {
                    $this->versionReads[] = $bindings;
                    return $this->vectorVersion === null ? null : (object) ['extversion' => $this->vectorVersion];
                }
                return parent::selectOne($query, $bindings, $useReadPdo);
            }

            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
            {
                $result = parent::select($query, $bindings, $useReadPdo, $fetchUsing);
                return str_contains($query, 'from "knowledge_chunks"') ? $this->chunkRows : $result;
            }
        };
        $connection->vectorVersion = $version;
        $connection->chunkRows = $rows;
        $execution = [];
        $connection->beforeExecuting(static function ($query, $bindings, $connection) use (&$execution): void {
            $execution[] = ['query' => $query, 'bindings' => $bindings, 'transaction_level' => $connection->transactionLevel()];
        });
        $previous = DB::getDefaultConnection();
        DB::extend('pgvector-plan', static fn () => $connection);
        config(['database.connections.pgvector-plan' => ['driver' => 'pgvector-plan']]);
        DB::setDefaultConnection('pgvector-plan');

        try {
            $embedding = Mockery::mock(EmbeddingCacheService::class);
            $embedding->shouldNotReceive('generate');
            $service = new KbSearchService($embedding, new Reranker());
            $filters = new RetrievalFilters(projectKeys: ['orders'], sourceTypes: ['markdown'], docIds: [501], languages: ['en']);
            $result = null;
            $connection->pretend(function () use ($service, $method, $filters, &$result): void {
                $reflection = new ReflectionMethod($service, $method);
                $arguments = $method === 'semanticCandidates'
                    ? ['plan-tenant', [0.1, 0.2, 0.3], '[0.1,0.2,0.3]', 'orders', 0.3, $filters, 24]
                    : ['test query', collect([['chunk_id' => 71]]), 'orders', 0.3, $filters, 15, [0.1, 0.2, 0.3]];
                $result = $reflection->invokeArgs($service, $arguments);
            });
            $this->assertSame([['vector']], $connection->versionReads);

            return ['queries' => $execution, 'execution' => $execution, 'result' => $result,
                'final_transaction_level' => $connection->transactionLevel()];
        } finally {
            DB::setDefaultConnection($previous);
            DB::purge('pgvector-plan');
        }
    }
}
