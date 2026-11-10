<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Retrieval;

use App\Ai\EmbeddingsResponse;
use App\Mcp\Tools\KbRetrievalExplainTool;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Services\Kb\Retrieval\RetrievalExplainService;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Support\Benchmark\DeterministicEmbedder;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Mcp\Request;
use Mockery;
use Tests\TestCase;

/**
 * v8.41/W1 (ADR 0035 §D1) — the `explain` trace across all three surfaces
 * (R44) and BOTH flag states (R43), against the REAL retrieval pipeline on
 * SQLite (PHP-cosine fallback, deterministic embeddings — no key).
 */
final class RetrievalExplainTest extends TestCase
{
    use RefreshDatabase;

    /** An OPERATIONAL tenant (`default` is reserved and non-operational — ADR 0024). */
    private const TENANT = 'explain-tenant';

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();

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
        ]);

        app(TenantContext::class)->set(self::TENANT);
        $this->seed(RbacSeeder::class);
        $this->ingestCorpus();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function ingestCorpus(): void
    {
        $ingestor = app(DocumentIngestor::class);
        $dir = dirname(__DIR__, 4).'/resources/benchmark/corpus';

        foreach (['cache-invalidation.md', 'cache-purge-runbook.md', 'rejected-clientside-caching.md'] as $name) {
            $ingestor->ingest('benchmark', new SourceDocument(
                sourcePath: $name,
                mimeType: 'text/markdown',
                bytes: (string) file_get_contents($dir.'/'.$name),
                externalUrl: null,
                externalId: null,
                connectorType: 'local',
                metadata: [],
            ), pathinfo($name, PATHINFO_FILENAME));
        }
    }

    private function user(string $role): User
    {
        $u = User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@example.test',
            'password' => Hash::make('secret-secret'),
        ])->fresh();
        $u->assignRole($role);
        ProjectMembership::create([
            'tenant_id' => self::TENANT,
            'user_id' => $u->id,
            'project_key' => 'benchmark',
            'role' => 'member',
        ]);

        return $u;
    }

    // ── core service ──────────────────────────────────────────────────────

    public function test_explain_returns_a_trace_for_every_result(): void
    {
        $out = app(RetrievalExplainService::class)->explain('How long does a cached entry live before it expires?', 'benchmark', 5);

        $this->assertNotSame([], $out['results'], 'the corpus must yield results');
        foreach ($out['results'] as $r) {
            $this->assertIsArray($r['explain']);
            $this->assertNotSame([], $r['explain']['lists'], 'every result names at least one ranked list');
            $this->assertSame('vector', $r['explain']['lists'][0]['source']);
            $this->assertNull($r['explain']['rrf'], 'hybrid is off → no fused score');
            $this->assertIsArray($r['explain']['heuristic']);
            $this->assertEqualsWithDelta($r['rerank_score'], $r['explain']['heuristic']['total'], 0.001);
            $this->assertLessThanOrEqual(160, mb_strlen($r['preview']), 'only a short preview, never the full chunk');
        }
    }

    public function test_the_explain_ranking_equals_the_ranking_without_explain(): void
    {
        $search = app(KbSearchService::class);
        $q = 'How is the cache purged when a document changes?';

        $plain = $search->search($q, 'benchmark', 5, 0.05)->pluck('chunk_id')->all();
        $explained = $search->search($q, 'benchmark', 5, 0.05, RetrievalFilters::forLegacyProject('benchmark')->withExplain())->pluck('chunk_id')->all();

        $this->assertNotSame([], $plain);
        $this->assertSame($plain, $explained, 'asking for an explanation must never change the ranking');
    }

    public function test_without_explain_no_trace_key_is_attached_and_no_extra_queries_run(): void
    {
        $search = app(KbSearchService::class);
        $q = 'cache invalidation ttl';

        // Warm-up: the first call loads per-tenant caches (synonyms) that a
        // second call reads from memory — compare two WARM calls.
        $search->search($q, 'benchmark', 5, 0.05);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $plain = $search->search($q, 'benchmark', 5, 0.05);
        $plainQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $explained = $search->search($q, 'benchmark', 5, 0.05, RetrievalFilters::forLegacyProject('benchmark')->withExplain());
        $explainedQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertFalse($plain->first()['explain'] ?? false, 'OFF: no explain key');
        $this->assertArrayNotHasKey('explain', $plain->first());
        $this->assertArrayHasKey('explain', $explained->first());
        $this->assertSame($plainQueries, $explainedQueries, 'explain is computed from values already held — zero extra queries');
    }

    public function test_explain_flag_is_not_a_filter(): void
    {
        $this->assertTrue((new RetrievalFilters(explain: true))->isEmpty());
        $this->assertTrue(RetrievalFilters::forLegacyProject(null)->withExplain()->explain);
        $this->assertSame(['p'], RetrievalFilters::forLegacyProject('p')->withExplain()->projectKeys);
    }

    // ── HTTP surface ─────────────────────────────────────────────────────

    public function test_http_is_a_clean_404_when_the_flag_is_off(): void
    {
        config(['kb.retrieval_explain.enabled' => false]);

        $this->actingAs($this->user('admin'))
            ->getJson('/api/admin/kb/retrieval/explain?query=cache')
            ->assertStatus(404)
            ->assertJsonPath('code', 'retrieval_explain_disabled');
    }

    public function test_http_returns_the_trace_when_the_flag_is_on(): void
    {
        config(['kb.retrieval_explain.enabled' => true]);

        $resp = $this->actingAs($this->user('admin'))
            ->getJson('/api/admin/kb/retrieval/explain?query='.urlencode('How long does a cached entry live?').'&project_key=benchmark&limit=3');

        $resp->assertOk();
        $this->assertNotSame([], $resp->json('results'));
        $this->assertNotNull($resp->json('results.0.explain.heuristic'));
        $this->assertLessThanOrEqual(3, count($resp->json('results')));
    }

    public function test_http_validates_the_query_and_caps_the_limit(): void
    {
        config(['kb.retrieval_explain.enabled' => true, 'kb.retrieval_explain.max_limit' => 5]);
        $admin = $this->user('admin');

        $this->actingAs($admin)->getJson('/api/admin/kb/retrieval/explain')->assertStatus(422)->assertJsonValidationErrors('query');
        $this->actingAs($admin)->getJson('/api/admin/kb/retrieval/explain?query='.str_repeat('x', 501))->assertStatus(422);
        $this->actingAs($admin)->getJson('/api/admin/kb/retrieval/explain?query=cache&limit=6')->assertStatus(422)->assertJsonValidationErrors('limit');
    }

    public function test_http_denies_non_admin_roles_even_when_the_flag_is_on(): void
    {
        config(['kb.retrieval_explain.enabled' => true]);

        $this->actingAs($this->user('viewer'))->getJson('/api/admin/kb/retrieval/explain?query=cache')->assertForbidden();
    }

    public function test_http_rejects_a_guest(): void
    {
        config(['kb.retrieval_explain.enabled' => true]);

        $this->getJson('/api/admin/kb/retrieval/explain?query=cache')->assertUnauthorized();
    }

    // ── MCP surface ──────────────────────────────────────────────────────

    private function callTool(array $args): array
    {
        $response = (new KbRetrievalExplainTool())->handle(new Request($args), app(RetrievalExplainService::class));

        return json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_mcp_answers_disabled_when_the_flag_is_off(): void
    {
        config(['kb.retrieval_explain.enabled' => false]);
        Auth::setUser($this->user('admin'));

        $this->assertSame(['disabled' => true, 'flag' => 'KB_RETRIEVAL_EXPLAIN_ENABLED'], $this->callTool(['query' => 'cache']));
    }

    public function test_mcp_returns_the_trace_to_an_admin_when_on(): void
    {
        config(['kb.retrieval_explain.enabled' => true]);
        Auth::setUser($this->user('admin'));

        $payload = $this->callTool(['query' => 'How long does a cached entry live?', 'project_key' => 'benchmark', 'limit' => 3]);

        $this->assertNotSame([], $payload['results']);
        $this->assertArrayHasKey('explain', $payload['results'][0]);
    }

    public function test_mcp_refuses_a_non_admin_principal(): void
    {
        config(['kb.retrieval_explain.enabled' => true]);
        Auth::setUser($this->user('viewer'));

        $response = (new KbRetrievalExplainTool())->handle(new Request(['query' => 'cache']), app(RetrievalExplainService::class));

        $this->assertTrue($response->isError());
    }

    // ── CLI surface ──────────────────────────────────────────────────────

    public function test_cli_prints_the_trace_with_explain_and_omits_it_without(): void
    {
        // The CLI is an operator surface: works with the HTTP/MCP flag OFF.
        config(['kb.retrieval_explain.enabled' => false]);

        $this->artisan('kb:search', ['query' => 'How long does a cached entry live?', '--project' => 'benchmark', '--tenant' => self::TENANT, '--explain' => true])
            ->expectsOutputToContain('list vector')
            ->assertExitCode(0);

        $this->artisan('kb:search', ['query' => 'How long does a cached entry live?', '--project' => 'benchmark', '--tenant' => self::TENANT, '--json' => true])
            ->doesntExpectOutputToContain('"explain"')
            ->assertExitCode(0);
    }

    public function test_cli_rejects_a_blank_query_and_restores_the_tenant(): void
    {
        $this->artisan('kb:search', ['query' => '   '])->assertExitCode(1);

        app(TenantContext::class)->set(self::TENANT);
        $this->artisan('kb:search', ['query' => 'cache', '--tenant' => 'someone-else', '--project' => 'benchmark'])->assertExitCode(0);
        $this->assertSame(self::TENANT, app(TenantContext::class)->current(), 'the CLI restores the previous tenant');
    }

    public function test_cli_does_not_leak_another_tenants_documents(): void
    {
        // Documents exist only under self::TENANT (set in setUp).
        $this->artisan('kb:search', ['query' => 'cache', '--tenant' => 'other-tenant', '--project' => 'benchmark', '--json' => true])
            ->expectsOutputToContain('"results": []')
            ->assertExitCode(0);
    }
}
