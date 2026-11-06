<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Retrieval;

use App\Ai\AiManager;
use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Services\Kb\Retrieval\Expansion\UserQueryDocument;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Services\Kb\Retrieval\TopRankBonus;
use App\Support\Benchmark\DeterministicEmbedder;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * W6 (qmd parity) + the main-branch integration, on the REAL KbSearchService
 * (SQLite, deterministic embedder), every flag in BOTH states (R43):
 * top-rank bonus, user-written query documents, the "model-written query"
 * expansion skip used by the recursive investigation, the `ref` on results,
 * the score floor and the new `kb:search` output formats.
 */
final class QmdParityStagesTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'benchmark';

    private const Q = 'How is the cache purged when a document changes?';

    private const EXPANSION = '{"lex":["cache purge document change"],"vec":["when a document is updated the cached entries are removed"],"hyde":"On document change an event purges every cached key of the project."}';

    private int $providerCalls = 0;

    /** @var list<string> user turns sent to the expansion model */
    private array $prompts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(
            fn (array $texts) => new EmbeddingsResponse(
                embeddings: DeterministicEmbedder::embedBatch($texts),
                provider: 'stub',
                model: 'deterministic-'.DeterministicEmbedder::DEFAULT_DIM,
            ),
        );
        $this->app->instance(EmbeddingCacheService::class, $cache);

        config([
            'queue.default' => 'sync',
            'kb.reranking.enabled' => true,
            'kb.hybrid_search.enabled' => false,
            'kb.default_min_similarity' => 0.05,
            'kb.cross_rerank.enabled' => false,
            'kb.query_expansion.enabled' => false,
            'kb.query_expansion.user_documents' => false,
            'kb.hybrid_search.top_rank_bonus.enabled' => false,
        ]);

        app(TenantContext::class)->set('default');
        $ingestor = app(DocumentIngestor::class);
        $dir = dirname(__DIR__, 4).'/resources/benchmark/corpus';
        foreach (['cache-invalidation.md', 'cache-purge-runbook.md', 'rejected-clientside-caching.md'] as $name) {
            $ingestor->ingest(self::PROJECT, new SourceDocument($name, 'text/markdown', (string) file_get_contents($dir.'/'.$name), null, null, 'local', []), pathinfo($name, PATHINFO_FILENAME));
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function fakeProvider(string $answer): void
    {
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('chat')->andReturnUsing(function (string $system, string $user) use ($answer) {
            $this->providerCalls++;
            $this->prompts[] = $user;

            return new AiResponse(content: $answer, provider: 'fake', model: 'm');
        });
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('provider')->andReturn($provider);
        $this->app->instance(AiManager::class, $ai);
        $this->providerCalls = 0;
        $this->prompts = [];
    }

    private function searchWithContext(string $q, ?RetrievalFilters $filters = null): \App\Services\Kb\Retrieval\SearchResult
    {
        return app(KbSearchService::class)->searchWithContext($q, self::PROJECT, 5, 0.05, $filters ?? RetrievalFilters::forLegacyProject(self::PROJECT)->withExplain());
    }

    // ── top-rank bonus ───────────────────────────────────────────────────

    public function test_top_rank_bonus_values_and_off_state(): void
    {
        $this->assertSame(0.0, TopRankBonus::for(1), 'OFF: no bonus at all');
        config(['kb.hybrid_search.top_rank_bonus.enabled' => true]);
        $this->assertSame(0.05, TopRankBonus::for(1));
        $this->assertSame(0.02, TopRankBonus::for(2));
        $this->assertSame(0.02, TopRankBonus::for(3));
        $this->assertSame(0.0, TopRankBonus::for(4));
        $this->assertSame(0.0, TopRankBonus::for(null));
    }

    public function test_top_rank_bonus_reaches_the_fused_score_only_for_original_query_heads(): void
    {
        config(['kb.query_expansion.enabled' => true, 'kb.query_expansion.skip_min_score' => 2.0]);
        $this->fakeProvider(self::EXPANSION);

        $off = $this->searchWithContext(self::Q);
        foreach ($off->primary as $c) {
            $this->assertSame(0.0, (float) $c['explain']['rrf']['top_rank_bonus'], 'OFF: total = base');
            $this->assertEqualsWithDelta($c['explain']['rrf']['base'], $c['explain']['rrf']['total'], 1e-9);
        }

        Cache::flush();
        config(['kb.hybrid_search.top_rank_bonus.enabled' => true]);
        $on = $this->searchWithContext(self::Q);
        $sawBonus = false;
        foreach ($on->primary as $c) {
            $originalRanks = array_column(array_filter($c['explain']['lists'], fn ($l) => $l['query'] === 'original'), 'rank');
            $expected = $originalRanks === [] ? 0.0 : TopRankBonus::for(min($originalRanks));
            $this->assertEqualsWithDelta($expected, $c['explain']['rrf']['top_rank_bonus'], 1e-9, 'bonus from ORIGINAL lists only');
            $this->assertEqualsWithDelta($c['explain']['rrf']['base'] + $expected, $c['explain']['rrf']['total'], 1e-8);
            $sawBonus = $sawBonus || $expected > 0;
        }
        $this->assertTrue($sawBonus, 'the original rank-1 chunk earned the head bonus');
    }

    // ── user-written query documents ─────────────────────────────────────

    public function test_query_document_parser_contract(): void
    {
        $this->assertNull(UserQueryDocument::parse('plain question without typed lines'));

        $doc = UserQueryDocument::parse("how do I purge the cache\nlex: purge cache\nvec: remove cached entries\nvec: remove cached entries\nhyde: The cache is purged by an event.\nintent: ops runbook");
        $this->assertNotNull($doc);
        $this->assertSame('how do I purge the cache', $doc->original);
        $this->assertSame(['purge cache'], $doc->expansion->lex);
        $this->assertSame(['remove cached entries'], $doc->expansion->vec, 'de-duplicated');
        $this->assertSame('The cache is purged by an event.', $doc->expansion->hyde);
        $this->assertSame('ops runbook', $doc->intent);

        $onlyTyped = UserQueryDocument::parse("vec: purge the cache on change\nlex: cache purge");
        $this->assertSame('purge the cache on change', $onlyTyped?->original, 'no plain line: first vec is the original');
        $this->assertSame([], $onlyTyped->expansion->vec, 'the original is not counted twice');
    }

    public function test_query_document_off_is_searched_literally_and_on_uses_its_variants_with_no_model_call(): void
    {
        $this->fakeProvider(self::EXPANSION);
        $text = "How is the cache purged?\nvec: when a document is updated the cached entries are removed";

        $off = $this->searchWithContext($text);
        $this->assertNull($off->meta['query_expansion'], 'OFF: no expansion at all, the text is the query');

        config(['kb.query_expansion.user_documents' => true]);
        $on = $this->searchWithContext($text);
        $this->assertSame('user_document', $on->meta['query_expansion']['status']);
        $this->assertGreaterThanOrEqual(1, $on->meta['query_expansion']['lists']);
        $this->assertSame(0, $this->providerCalls, 'R26: the author wrote the variants — no model call');
        $queries = collect($on->primary->flatMap(fn ($c) => $c['explain']['lists']))->pluck('query')->unique()->all();
        $this->assertContains('vec', $queries);
    }

    // ── model-written queries (recursive investigation / agent planner) ──

    public function test_a_model_written_query_is_never_expanded_again(): void
    {
        config(['kb.query_expansion.enabled' => true, 'kb.query_expansion.skip_min_score' => 2.0]);
        $this->fakeProvider(self::EXPANSION);

        $skipped = $this->searchWithContext(self::Q, RetrievalFilters::forLegacyProject(self::PROJECT)->withoutExpansion());
        $this->assertSame(['status' => 'skipped', 'reason' => 'model_written_query'], $skipped->meta['query_expansion']);
        $this->assertSame(0, $this->providerCalls, 'R26: no second LLM call');

        $normal = $this->searchWithContext(self::Q);
        $this->assertSame('ok', $normal->meta['query_expansion']['status']);
        $this->assertSame(1, $this->providerCalls);
    }

    public function test_the_model_written_scope_skips_expansion_and_is_restored(): void
    {
        config(['kb.query_expansion.enabled' => true, 'kb.query_expansion.skip_min_score' => 2.0]);
        $this->fakeProvider(self::EXPANSION);

        $inside = \App\Services\Kb\Retrieval\Expansion\ModelWrittenQueries::run(fn () => $this->searchWithContext(self::Q));
        $this->assertSame(['status' => 'skipped', 'reason' => 'model_written_query'], $inside->meta['query_expansion']);
        $this->assertSame(0, $this->providerCalls);
        $this->assertFalse(\App\Services\Kb\Retrieval\Expansion\ModelWrittenQueries::active(), 'restored');

        $this->assertSame('ok', $this->searchWithContext(self::Q)->meta['query_expansion']['status'], 'outside the scope expansion runs');
    }

    public function test_without_expansion_keeps_every_other_filter(): void
    {
        $f = (new RetrievalFilters(projectKeys: ['a'], tagSlugs: ['t'], explain: true, intent: 'x'))->withoutExpansion();
        $this->assertTrue($f->skipExpansion);
        $this->assertSame(['a'], $f->projectKeys);
        $this->assertSame(['t'], $f->tagSlugs);
        $this->assertTrue($f->explain);
        $this->assertSame('x', $f->intent);
        $this->assertTrue($f->withIntent('y')->skipExpansion, 'copy() carries the flag');
    }

    // ── refs, score floor, CLI formats ───────────────────────────────────

    public function test_results_carry_a_short_document_reference(): void
    {
        $result = $this->searchWithContext(self::Q);
        $first = $result->primary->first();
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{12}$/', (string) $first['document']['ref']);
    }

    public function test_the_score_floor_uses_the_score_the_results_are_ordered_by(): void
    {
        // The cross-encoder orders by `cross_encoder.blended` and leaves
        // `rerank_score` as it was: the floor must follow the blend, or it
        // could drop a higher-ranked result and keep a lower-ranked one.
        $results = collect([
            ['chunk_id' => 1, 'rerank_score' => 0.20, 'vector_score' => 0.9, 'cross_encoder' => ['status' => 'ok', 'blended' => 0.80]],
            ['chunk_id' => 2, 'rerank_score' => 0.70, 'vector_score' => 0.9, 'cross_encoder' => ['status' => 'ok', 'blended' => 0.30]],
            // A failed cross-encoder stage keeps the reranker's score.
            ['chunk_id' => 3, 'rerank_score' => 0.60, 'vector_score' => 0.1, 'cross_encoder' => ['status' => 'unavailable']],
            ['chunk_id' => 4, 'vector_score' => 0.55],
        ]);

        $kept = \App\Services\Kb\Retrieval\ResultScoreFloor::apply($results, 0.5)->pluck('chunk_id')->all();

        $this->assertSame([1, 3, 4], $kept, 'blend first, then rerank_score, then vector_score');
        $this->assertSame(0.8, \App\Services\Kb\Retrieval\ResultScoreFloor::scoreOf($results[0]));
        $this->assertSame(0.6, \App\Services\Kb\Retrieval\ResultScoreFloor::scoreOf($results[2]));
        $this->assertSame($results->all(), \App\Services\Kb\Retrieval\ResultScoreFloor::apply($results, null)->all(), 'no floor = untouched');
    }

    public function test_kb_search_min_score_files_and_csv(): void
    {
        $this->assertSame(0, Artisan::call('kb:search', ['query' => self::Q, '--project' => self::PROJECT, '--files' => true]));
        $files = trim(Artisan::output());
        $lines = array_values(array_filter(explode("\n", $files)));
        $this->assertNotSame([], $lines);
        $this->assertSame(count($lines), count(array_unique(array_map(fn ($l) => explode(',', $l)[2] ?? '', $lines))), 'one line per document');
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{12},[0-9.]+,.+\.md$/', $lines[0]);

        $this->assertSame(0, Artisan::call('kb:search', ['query' => self::Q, '--project' => self::PROJECT, '--min-score' => '999']));
        $this->assertStringContainsString('0 result(s)', Artisan::output(), 'every result is below the floor');

        $this->assertSame(0, Artisan::call('kb:search', ['query' => self::Q, '--project' => self::PROJECT, '--csv' => true, '--line-numbers' => true]));
        $csv = array_map('str_getcsv', array_values(array_filter(explode("\n", trim(Artisan::output())))));
        $this->assertSame(['ref', 'score', 'source_path', 'heading_path', 'chunk_id', 'start_line', 'end_line'], $csv[0]);
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $csv[1][5], 'line numbers resolved');

        $this->assertSame(1, Artisan::call('kb:search', ['query' => self::Q, '--csv' => true, '--json' => true]), 'one output format at a time');
        $this->assertSame(1, Artisan::call('kb:search', ['query' => self::Q, '--min-score' => 'abc']));
    }

    public function test_explain_reports_the_investigation_trace_as_disabled_when_the_switch_is_off(): void
    {
        config(['kb.investigation.enabled' => false]);
        $this->assertSame(0, Artisan::call('kb:search', ['query' => self::Q, '--project' => self::PROJECT, '--investigation' => true, '--json' => true]));
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame('disabled', $payload['meta']['investigation']['status']);
        $this->assertNotSame([], $payload['results'], 'falls back to the direct retrieval');

        $this->assertSame(0, Artisan::call('kb:search', ['query' => self::Q, '--project' => self::PROJECT, '--json' => true]));
        $this->assertNull(json_decode(Artisan::output(), true)['meta']['investigation'], 'not requested: null');
    }

    public function test_benchmark_rejects_an_unknown_path(): void
    {
        $this->assertSame(1, Artisan::call('kb:benchmark', ['--stub' => true, '--path' => 'sideways']));
        $this->assertStringContainsString("--path must be 'direct' or 'investigation'", Artisan::output());
    }

    // ── self-hosted expansion profile ────────────────────────────────────

    public function test_self_hosted_expansion_profile_calls_only_the_allow_listed_endpoint(): void
    {
        $this->fakeProvider(self::EXPANSION);
        config([
            'kb.query_expansion.enabled' => true,
            'kb.query_expansion.skip_min_score' => 2.0,
            'kb.query_expansion.profile' => 'self_hosted',
            'kb.query_expansion.self_hosted' => ['endpoint' => 'https://llm.internal.example/v1/chat/completions', 'model' => 'qwen3-1.7b-expansion', 'allowed_hosts' => ['llm.internal.example']],
        ]);
        \Illuminate\Support\Facades\Http::fake(['llm.internal.example/*' => \Illuminate\Support\Facades\Http::response(['choices' => [['message' => ['content' => self::EXPANSION]]]], 200)]);

        $result = $this->searchWithContext(self::Q);
        $this->assertSame('ok', $result->meta['query_expansion']['status']);
        $this->assertSame(0, $this->providerCalls, 'no AiManager provider: no third-party processor');
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r->url() === 'https://llm.internal.example/v1/chat/completions'
            && $r['model'] === 'qwen3-1.7b-expansion' && $r['messages'][0]['role'] === 'system' && str_contains($r['messages'][1]['content'], '<query>'));

        Cache::flush();
        config(['kb.query_expansion.self_hosted.allowed_hosts' => ['other.example']]);
        \Illuminate\Support\Facades\Http::fake();
        $blocked = $this->searchWithContext(self::Q.' again');
        $this->assertSame(['status' => 'unavailable', 'reason' => 'provider_error'], $blocked->meta['query_expansion'], 'SSRF guard: a host off the list is never called');
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    // ── code + main's exact literal lookup ───────────────────────────────

    public function test_exact_identifier_lookup_finds_a_code_symbol(): void
    {
        $doc = \App\Models\KnowledgeDocument::query()->create([
            'tenant_id' => 'default', 'project_key' => self::PROJECT, 'source_type' => 'code', 'title' => 'Invoice.php',
            'source_path' => 'src/Billing/Invoice.php', 'mime_type' => 'text/x-php', 'language' => 'en', 'access_scope' => 'internal',
            'status' => 'indexed', 'document_hash' => str_repeat('f', 64), 'version_hash' => str_repeat('f', 64), 'metadata' => [],
        ]);
        \App\Models\KnowledgeChunk::query()->create([
            'tenant_id' => 'default', 'knowledge_document_id' => $doc->id, 'project_key' => self::PROJECT, 'chunk_order' => 0,
            'chunk_hash' => str_repeat('1', 64), 'heading_path' => 'src/Billing/Invoice.php > Invoice > calculate_total_v2',
            'chunk_text' => "public function calculate_total_v2(array \$lines): int\n{\n    return array_sum(\$lines);\n}", 'metadata' => ['language' => 'php', 'symbol' => 'calculate_total_v2'],
        ]);

        $hits = app(KbSearchService::class)->exactIdentifierCandidates(['calculate_total_v2'], self::PROJECT);
        $this->assertContains('src/Billing/Invoice.php', $hits->map(fn ($c) => data_get($c, 'document.source_path') ?? $c->document?->source_path)->all());
    }

    // ── investigation ON paths ───────────────────────────────────────────

    private function fakeInvestigation(): void
    {
        $chunk = ['chunk_id' => 1, 'chunk_text' => 'Cache purge on change.', 'vector_score' => 0.9, 'rerank_score' => 0.8,
            'document' => ['id' => 1, 'ref' => '#aaaaaaaaaaaa', 'title' => 'Runbook', 'source_path' => 'cache-purge-runbook.md']];
        $investigation = Mockery::mock(\App\Services\Kb\Investigation\KbInvestigationService::class);
        $investigation->shouldReceive('investigate')->andReturn(new \App\Services\Kb\Investigation\KbInvestigationResult(
            'ready', 'enough_evidence', new \App\Services\Kb\Retrieval\SearchResult(collect([$chunk]), collect(), collect()),
            queries: ['cache purge document change', 'purge runbook'],
        ));
        $this->app->instance(\App\Services\Kb\Investigation\KbInvestigationService::class, $investigation);
    }

    public function test_explain_traces_the_investigation_when_it_is_on(): void
    {
        config(['kb.investigation.enabled' => true]);
        $this->fakeInvestigation();

        $this->assertSame(0, Artisan::call('kb:search', ['query' => self::Q, '--project' => self::PROJECT, '--investigation' => true, '--json' => true]));
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame('ready', $payload['meta']['investigation']['status']);
        $this->assertSame('enough_evidence', $payload['meta']['investigation']['stop_reason']);
        $this->assertSame(['cache purge document change', 'purge runbook'], $payload['meta']['investigation']['queries']);
        $this->assertSame('cache-purge-runbook.md', $payload['results'][0]['document']['source_path'], 'the results ARE the investigation sources');
    }

    public function test_the_benchmark_measures_the_investigation_path(): void
    {
        $this->fakeInvestigation();
        $runner = app(\App\Services\Kb\Benchmark\BenchmarkRunner::class);
        $card = $runner->run(dirname(__DIR__, 4).'/resources/benchmark/corpus', dirname(__DIR__, 4).'/resources/benchmark/queries.yaml', 'bench-inv', 5, false, 'v1', \App\Services\Kb\Benchmark\BenchmarkRunner::PATH_INVESTIGATION);

        $this->assertSame('investigation', $card['path']);
        $this->assertSame(['status' => 'ready', 'stop_reason' => 'enough_evidence', 'rounds' => 2], $card['queries'][0]['investigation']);
    }

    public function test_top_rank_bonus_changes_the_real_fused_score_and_order(): void
    {
        $chunk = function (int $id) {
            $c = new \App\Models\KnowledgeChunk();
            $c->id = $id;
            $c->vector_score = 0.5;

            return $c;
        };
        // Original list: A(1) first. Expansion vec list: B(2) first, C(3) second; B also 2nd in original.
        $lists = [
            ['source' => 'vector', 'query' => 'original', 'chunks' => collect([$chunk(1), $chunk(2)]), 'weight' => 0.7, 'score_field' => 'vector_score'],
            ['source' => 'vector', 'query' => 'vec', 'chunks' => collect([$chunk(2), $chunk(3)]), 'weight' => 0.35, 'score_field' => 'vector_score'],
        ];
        $fuse = new \ReflectionMethod(KbSearchService::class, 'fuseLists');
        $svc = app(KbSearchService::class);

        $off = $fuse->invoke($svc, $lists, 60, []);
        $this->assertSame([2, 1, 3], $off->pluck('id')->all(), 'OFF: plain RRF lets B (two lists) overtake the original head');
        $offScores = $off->mapWithKeys(fn ($c) => [$c->id => $c->rrf_score])->all(); // fuseLists mutates the same models

        config(['kb.hybrid_search.top_rank_bonus.enabled' => true]);
        $on = $fuse->invoke($svc, $lists, 60, []);
        $this->assertSame([1, 2, 3], $on->pluck('id')->all(), 'ON: the original rank-1 hit keeps the head');
        $this->assertEqualsWithDelta($offScores[1] + 0.05, $on->firstWhere('id', 1)->rrf_score, 1e-9);
        $this->assertEqualsWithDelta($offScores[2] + 0.02, $on->firstWhere('id', 2)->rrf_score, 1e-9, 'rank 2 of the original list: podium bonus');
        $this->assertEqualsWithDelta($offScores[3], $on->firstWhere('id', 3)->rrf_score, 1e-9, 'expansion-only hits never get the bonus');
    }
}
