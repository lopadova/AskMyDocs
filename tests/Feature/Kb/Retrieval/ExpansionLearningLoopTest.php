<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Retrieval;

use App\Ai\AiManager;
use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use App\Jobs\AnalyzeExpansionLearningJob;
use App\Mcp\Tools\KbExpansionLearningTool;
use App\Models\KbExpansionEvent;
use App\Models\KbExpansionModel;
use App\Models\KbExpansionTuning;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Services\Kb\Retrieval\Expansion\Learning\ActiveExpansionTuning;
use App\Services\Kb\Retrieval\Expansion\Learning\ExpansionLearningDisabled;
use App\Services\Kb\Retrieval\Expansion\Learning\ExpansionLearningService;
use App\Services\Kb\Retrieval\Expansion\Learning\ExpansionSignalRecorder;
use App\Services\Kb\Retrieval\Expansion\Learning\ExpansionTuningGate;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Support\Benchmark\DeterministicEmbedder;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Mockery;
use Tests\TestCase;

/**
 * W7 — the query-expansion learning loop end to end, every flag in BOTH
 * states (R43), tenant-scoped (R30), human-gated where it matters:
 * signal → analyse (weights + few-shot) → gate → apply / reject → the live
 * search uses it; dataset → training backend → candidate → human promotion.
 */
final class ExpansionLearningLoopTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 'el-tenant';

    private const PROJECT = 'benchmark';

    private const Q = 'How is the cache purged when a document changes?';

    private const EXPANSION = '{"lex":["cache purge document change"],"vec":["when a document is updated the cached entries are removed"],"hyde":"On document change an event purges every cached key of the project."}';

    /** @var list<array{user: string, options: array<string,mixed>}> */
    private array $calls = [];

    /** @var list<string|null> provider names asked of the AiManager */
    private array $providers = [];

    private string $gateStatus = 'passed';

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(RbacSeeder::class);

        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(fn (array $texts) => new EmbeddingsResponse(
            embeddings: DeterministicEmbedder::embedBatch($texts), provider: 'stub', model: 'deterministic-'.DeterministicEmbedder::DEFAULT_DIM,
        ));
        $this->app->instance(EmbeddingCacheService::class, $cache);

        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('chat')->andReturnUsing(function (string $system, string $user, array $options = []) {
            $this->calls[] = ['user' => $user, 'options' => $options];

            return new AiResponse(content: self::EXPANSION, provider: 'fake', model: 'm');
        });
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('provider')->andReturnUsing(function (?string $name = null) use ($provider) {
            $this->providers[] = $name;

            return $provider;
        });
        $this->app->instance(AiManager::class, $ai);

        $this->app->instance(ExpansionTuningGate::class, new class($this) implements ExpansionTuningGate
        {
            public function __construct(private ExpansionLearningLoopTest $test) {}

            public function evaluate(array $tuning): array
            {
                return ['status' => $this->test->gateStatus()];
            }
        });

        config([
            'queue.default' => 'sync',
            'kb.reranking.enabled' => true,
            'kb.hybrid_search.enabled' => false,
            'kb.default_min_similarity' => 0.05,
            'kb.query_expansion.enabled' => true,
            'kb.query_expansion.skip_min_score' => 2.0,
            'kb.expansion_learning.enabled' => true,
            'kb.expansion_learning.min_events' => 5,
            'kb.expansion_learning.auto_apply' => false,
        ]);
        app(TenantContext::class)->set(self::TENANT);

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

    public function gateStatus(): string
    {
        return $this->gateStatus;
    }

    private function service(): ExpansionLearningService
    {
        return app(ExpansionLearningService::class);
    }

    private function search(string $q = self::Q): \App\Services\Kb\Retrieval\SearchResult
    {
        return app(KbSearchService::class)->searchWithContext($q, self::PROJECT, 5, 0.05, RetrievalFilters::forLegacyProject(self::PROJECT)->withExplain());
    }

    /** @param  array<string, array{lists:int, in_top:int, unique_in_top:int}>  $stats */
    private function event(array $stats, bool $refused = false, ?int $feedback = null, string $query = 'q', string $tenant = self::TENANT): KbExpansionEvent
    {
        return KbExpansionEvent::query()->create([
            'tenant_id' => $tenant,
            'project_key' => self::PROJECT,
            'query_hash' => hash('sha256', $query.uniqid('', true)),
            'masked_query' => $query,
            'source' => 'llm',
            'expansion' => ['lex' => ['l '.$query], 'vec' => ['v '.$query], 'hyde' => 'h '.$query],
            'list_stats' => $stats,
            'top_chunk_ids' => [1, 2],
            'refused' => $refused,
            'feedback' => $feedback,
        ]);
    }

    private function seedVecWinsLexNever(int $n = 6): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->event([
                'lex' => ['lists' => 1, 'in_top' => 1, 'unique_in_top' => 0],
                'vec' => ['lists' => 1, 'in_top' => 2, 'unique_in_top' => 1],
                'hyde' => ['lists' => 0, 'in_top' => 0, 'unique_in_top' => 0],
            ], query: "query {$i}");
        }
    }

    // ── signal ───────────────────────────────────────────────────────────

    public function test_off_records_nothing_and_the_tuning_reads_neutral(): void
    {
        config(['kb.expansion_learning.enabled' => false]);
        $this->search();

        $this->assertSame(0, KbExpansionEvent::query()->count());
        $this->assertSame(ActiveExpansionTuning::neutral(), ActiveExpansionTuning::for(self::TENANT, self::PROJECT));
        $this->expectException(ExpansionLearningDisabled::class);
        $this->service()->analyze(self::TENANT, self::PROJECT, 'test');
    }

    public function test_on_records_one_masked_event_per_expansion_with_the_reward_stats(): void
    {
        $this->search();

        $event = KbExpansionEvent::query()->sole();
        $this->assertSame(self::TENANT, $event->tenant_id);
        $this->assertSame(self::PROJECT, $event->project_key);
        $this->assertSame(hash('sha256', mb_strtolower($event->masked_query)), $event->query_hash);
        $this->assertSame(['lex', 'vec', 'hyde'], array_keys($event->list_stats));
        $this->assertGreaterThanOrEqual(1, $event->list_stats['vec']['lists'] + $event->list_stats['hyde']['lists']);
        $this->assertNotSame([], $event->top_chunk_ids);
    }

    public function test_reward_stats_count_unique_contributions(): void
    {
        $list = fn (string $q, array $ids) => ['source' => 'vector', 'query' => $q, 'chunks' => collect(array_map(fn ($id) => ['id' => $id], $ids)), 'weight' => 1.0, 'score_field' => 'vector_score'];
        $stats = ExpansionSignalRecorder::stats([$list('original', [1, 2]), $list('vec', [2, 3]), $list('hyde', [4]), $list('lex', [3])], [1, 2, 3, 4]);

        $this->assertSame(['lists' => 1, 'in_top' => 2, 'unique_in_top' => 0], $stats['vec'], '2 is original, 3 is also lex');
        $this->assertSame(['lists' => 1, 'in_top' => 1, 'unique_in_top' => 1], $stats['hyde']);
        $this->assertSame(['lists' => 1, 'in_top' => 1, 'unique_in_top' => 0], $stats['lex']);
    }

    // ── analyse → gate → apply ───────────────────────────────────────────

    public function test_too_little_evidence_proposes_nothing(): void
    {
        $this->seedVecWinsLexNever(3);
        $this->assertNull($this->service()->analyze(self::TENANT, self::PROJECT, 'test'));
        $this->assertSame(0, KbExpansionTuning::query()->count());
    }

    public function test_analysis_proposes_weights_and_examples_from_the_evidence(): void
    {
        $this->seedVecWinsLexNever();
        $this->event(['lex' => ['lists' => 0, 'in_top' => 0, 'unique_in_top' => 0], 'vec' => ['lists' => 1, 'in_top' => 1, 'unique_in_top' => 3], 'hyde' => ['lists' => 0, 'in_top' => 0, 'unique_in_top' => 0]], refused: true, query: 'refused one');
        $this->event(['lex' => ['lists' => 0, 'in_top' => 0, 'unique_in_top' => 0], 'vec' => ['lists' => 1, 'in_top' => 1, 'unique_in_top' => 5], 'hyde' => ['lists' => 0, 'in_top' => 0, 'unique_in_top' => 0]], feedback: -1, query: 'harmful one');
        $this->event(['lex' => ['lists' => 0, 'in_top' => 0, 'unique_in_top' => 0], 'vec' => ['lists' => 1, 'in_top' => 1, 'unique_in_top' => 1], 'hyde' => ['lists' => 0, 'in_top' => 0, 'unique_in_top' => 0]], tenant: 'other', query: 'other tenant');

        $p = $this->service()->analyze(self::TENANT, self::PROJECT, 'test');

        $this->assertSame('proposed', $p['status'], 'auto_apply OFF: a human decides');
        $this->assertSame(0.5, $p['weights']['lex'], 'never found anything new');
        $this->assertSame(1.5, $p['weights']['vec']);
        $this->assertSame(1.0, $p['weights']['hyde'], 'no evidence → neutral');
        $this->assertSame(8, $p['evidence']['events_analyzed'], 'R30: the other tenant is not read');
        $examples = array_column($p['few_shot'], 'query');
        $this->assertNotContains('refused one', $examples);
        $this->assertNotContains('harmful one', $examples);
        $this->assertNotContains('other tenant', $examples);
        $this->assertLessThanOrEqual(ExpansionLearningService::MAX_FEW_SHOT, count($examples));
        $this->assertSame([], $p['few_shot'][0]['lex'], 'only the types that earned something are kept');
        $this->assertSame('passed', $p['gate']['status']);
    }

    public function test_auto_apply_requires_a_passed_gate(): void
    {
        config(['kb.expansion_learning.auto_apply' => true]);
        $this->seedVecWinsLexNever();

        $this->gateStatus = 'not_measurable';
        $this->assertSame('proposed', $this->service()->analyze(self::TENANT, self::PROJECT, 'system')['status'], 'fail-closed');

        $this->gateStatus = 'passed';
        $applied = $this->service()->analyze(self::TENANT, self::PROJECT, 'system');
        $this->assertSame('applied', $applied['status']);
        $this->assertSame('system:auto-apply', $applied['decided_by']);
    }

    public function test_apply_supersedes_reject_rolls_back_and_the_live_search_uses_the_weights(): void
    {
        $this->seedVecWinsLexNever();
        $first = $this->service()->analyze(self::TENANT, self::PROJECT, 'test');
        $second = $this->service()->analyze(self::TENANT, self::PROJECT, 'test');

        $this->service()->apply(self::TENANT, $first['id'], 'user:1');
        $this->service()->apply(self::TENANT, $second['id'], 'user:1');
        $this->assertSame('superseded', KbExpansionTuning::query()->find($first['id'])->status);
        $this->assertSame(1.5, ActiveExpansionTuning::for(self::TENANT, self::PROJECT)['weights']['vec']);

        $explained = $this->search();
        $vecWeights = collect($explained->primary->flatMap(fn ($c) => $c['explain']['lists']))->where('query', 'vec')->pluck('weight')->unique()->values()->all();
        $expected = round(0.7 * 0.5 * 1.5, 6);
        $this->assertSame([$expected], $vecWeights, 'semantic weight × expansion ratio × learned multiplier');

        $this->service()->reject(self::TENANT, $second['id'], 'user:1');
        $this->assertSame(ActiveExpansionTuning::neutral()['weights'], ActiveExpansionTuning::for(self::TENANT, self::PROJECT)['weights'], 'rolled back');

        $this->expectException(InvalidArgumentException::class);
        $this->service()->apply(self::TENANT, $first['id'], 'user:1');
    }

    public function test_applied_examples_reach_the_expansion_prompt_as_delimited_data(): void
    {
        $this->seedVecWinsLexNever();
        $p = $this->service()->analyze(self::TENANT, self::PROJECT, 'test');
        $this->service()->apply(self::TENANT, $p['id'], 'user:1');

        $this->calls = [];
        $this->search('a different question about cache purge');
        $user = $this->calls[0]['user'] ?? '';
        $this->assertStringContainsString('<examples>', $user);
        $this->assertStringContainsString('query 0', $user);
        $this->assertLessThan(strpos($user, '<query>'), strpos($user, '</examples>'), 'examples precede the query, both in the user turn');
    }

    public function test_cross_tenant_ids_are_not_found(): void
    {
        $this->seedVecWinsLexNever();
        $p = $this->service()->analyze(self::TENANT, self::PROJECT, 'test');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->service()->apply('other', $p['id'], 'user:1');
    }

    // ── training → candidate → promotion ─────────────────────────────────

    public function test_dataset_keeps_rewarded_events_only(): void
    {
        $this->seedVecWinsLexNever();
        $this->event(['lex' => ['lists' => 1, 'in_top' => 1, 'unique_in_top' => 0], 'vec' => ['lists' => 1, 'in_top' => 1, 'unique_in_top' => 0], 'hyde' => ['lists' => 0, 'in_top' => 0, 'unique_in_top' => 0]], query: 'no reward');

        $rows = $this->service()->dataset(self::TENANT, self::PROJECT);
        $this->assertCount(6, $rows);
        $answer = json_decode($rows[0]['messages'][2]['content'], true);
        $this->assertSame([], $answer['lex']);
        $this->assertNotSame([], $answer['vec']);
        $this->assertSame(\App\Services\Kb\Retrieval\Expansion\QueryExpansionService::systemPrompt(), $rows[0]['messages'][0]['content'], 'train = serve');
    }

    public function test_export_backend_writes_the_jsonl_and_minimum_rows_are_enforced(): void
    {
        Storage::fake('local');
        config(['kb.expansion_learning.training.min_rows' => 10]);
        $this->seedVecWinsLexNever(4);
        try {
            $this->service()->train(self::TENANT, self::PROJECT, 'export', null, 'user:1');
            $this->fail('too few rows');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('at least 10', $e->getMessage());
        }

        $this->seedVecWinsLexNever(8);
        $run = $this->service()->train(self::TENANT, self::PROJECT, 'export', null, 'user:1');
        $this->assertSame('exported', $run['status']);
        $this->assertSame(12, $run['dataset_rows']);
        $row = KbExpansionModel::query()->find($run['id']);
        Storage::disk('local')->assertExists($row->dataset_path);
        $this->assertCount(12, array_filter(explode("\n", (string) Storage::disk('local')->get($row->dataset_path))));
    }

    public function test_openai_backend_needs_consent_then_submits_polls_and_a_human_promotes(): void
    {
        Storage::fake('local');
        config(['kb.expansion_learning.training.min_rows' => 10, 'ai.providers.openai.key' => 'sk-test']);
        $this->seedVecWinsLexNever(12);

        try {
            $this->service()->train(self::TENANT, self::PROJECT, 'openai', null, 'user:1');
            $this->fail('no consent, no egress');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('consented', $e->getMessage());
        }
        Http::assertNothingSent();

        config(['kb.expansion_learning.training.consented_tenants' => [self::TENANT]]);
        Http::fake([
            'api.openai.com/v1/files' => Http::response(['id' => 'file-abc123'], 200),
            'api.openai.com/v1/fine_tuning/jobs' => Http::response(['id' => 'ftjob-xyz'], 200),
            'api.openai.com/v1/fine_tuning/jobs/ftjob-xyz' => Http::sequence()
                ->push(['status' => 'running'], 200)
                ->push(['status' => 'succeeded', 'fine_tuned_model' => 'ft:gpt-4.1-mini-2025-04-14:org:amd-expansion:abc'], 200),
        ]);

        $run = $this->service()->train(self::TENANT, self::PROJECT, 'openai', 'gpt-4.1-mini-2025-04-14', 'user:1');
        $this->assertSame('submitted', $run['status']);
        $this->assertSame('training', $this->service()->refresh(self::TENANT, $run['id'])['status']);
        $ready = $this->service()->refresh(self::TENANT, $run['id']);
        $this->assertSame('ready', $ready['status']);
        $this->assertNull(ActiveExpansionTuning::promotedModel(self::TENANT), 'ready is not live');

        $this->service()->promote(self::TENANT, $run['id'], 'user:1');
        $this->assertSame('ft:gpt-4.1-mini-2025-04-14:org:amd-expansion:abc', ActiveExpansionTuning::promotedModel(self::TENANT));

        $this->calls = [];
        $this->providers = [];
        $this->search('purge cache when a document changes');
        $this->assertContains('openai', $this->providers, 'the promoted model is served through the OpenAI provider');
        $this->assertSame('ft:gpt-4.1-mini-2025-04-14:org:amd-expansion:abc', $this->calls[0]['options']['model'] ?? null);

        $this->service()->retire(self::TENANT, $run['id'], 'user:1');
        $this->assertNull(ActiveExpansionTuning::promotedModel(self::TENANT), 'rolled back');
    }

    public function test_openai_backend_refuses_a_base_model_outside_the_code_owned_list(): void
    {
        config(['kb.expansion_learning.training.consented_tenants' => [self::TENANT]]);
        $this->expectException(InvalidArgumentException::class);
        $this->service()->train(self::TENANT, self::PROJECT, 'openai', 'gpt-5-anything', 'user:1');
    }

    // ── surfaces ─────────────────────────────────────────────────────────

    private function user(string $role): User
    {
        $u = User::create(['name' => ucfirst($role), 'email' => $role.'-'.uniqid().'@example.test', 'password' => Hash::make('secret-secret')])->fresh();
        $u->assignRole($role);
        ProjectMembership::create(['tenant_id' => self::TENANT, 'user_id' => $u->id, 'project_key' => self::PROJECT, 'role' => 'member']);

        return $u;
    }

    public function test_http_both_states_queued_analysis_and_super_admin_gates(): void
    {
        Bus::fake([AnalyzeExpansionLearningJob::class]);
        $admin = $this->user('admin');
        $super = $this->user('super-admin');

        $this->actingAs($admin)->getJson('/api/admin/kb/expansion-learning')->assertOk()->assertJsonPath('enabled', true);
        $this->actingAs($admin)->postJson('/api/admin/kb/expansion-learning/analyze', ['project_key' => self::PROJECT])->assertStatus(202);
        Bus::assertDispatched(AnalyzeExpansionLearningJob::class, fn ($job) => $job->tenantId === self::TENANT && $job->projectKey === self::PROJECT);

        $this->actingAs($admin)->postJson('/api/admin/kb/expansion-learning/training', ['backend' => 'export', 'confirm' => true])
            ->assertStatus(403)->assertJsonPath('code', 'super_admin_required');
        $this->actingAs($super)->postJson('/api/admin/kb/expansion-learning/training', ['backend' => 'export'])->assertStatus(422);
        $this->actingAs($super)->postJson('/api/admin/kb/expansion-learning/training', ['backend' => 'export', 'confirm' => true])
            ->assertStatus(422)->assertJsonPath('code', 'refused');
        $this->actingAs($admin)->postJson('/api/admin/kb/expansion-learning/tunings/999999/apply')->assertStatus(404)->assertJsonPath('code', 'not_found');

        config(['kb.expansion_learning.enabled' => false]);
        $this->actingAs($admin)->getJson('/api/admin/kb/expansion-learning')->assertStatus(404)->assertJsonPath('code', 'expansion_learning_disabled');
        $this->actingAs($admin)->postJson('/api/admin/kb/expansion-learning/analyze', ['project_key' => self::PROJECT])->assertStatus(404);
    }

    public function test_mcp_tool_is_read_only_and_admin_only(): void
    {
        $tool = new KbExpansionLearningTool();
        Auth::setUser($this->user('viewer'));
        $this->assertTrue($tool->handle(new Request([]), $this->service(), app(TenantContext::class))->isError());

        Auth::setUser($this->user('admin'));
        $payload = json_decode((string) $tool->handle(new Request([]), $this->service(), app(TenantContext::class))->content(), true);
        $this->assertTrue($payload['enabled']);

        config(['kb.expansion_learning.enabled' => false]);
        $off = json_decode((string) $tool->handle(new Request([]), $this->service(), app(TenantContext::class))->content(), true);
        $this->assertSame(['disabled' => true, 'flag' => 'KB_EXPANSION_LEARNING_ENABLED'], $off);
    }

    public function test_nightly_is_a_noop_when_off_and_prunes_and_analyses_when_on(): void
    {
        config(['kb.expansion_learning.enabled' => false]);
        $this->assertSame(0, Artisan::call('kb:expansion-learn', ['action' => 'nightly']));
        $this->assertStringContainsString('disabled', Artisan::output());

        config(['kb.expansion_learning.enabled' => true]);
        $this->seedVecWinsLexNever();
        $old = $this->event(['lex' => ['lists' => 0, 'in_top' => 0, 'unique_in_top' => 0], 'vec' => ['lists' => 0, 'in_top' => 0, 'unique_in_top' => 0], 'hyde' => ['lists' => 0, 'in_top' => 0, 'unique_in_top' => 0]]);
        KbExpansionEvent::query()->whereKey($old->id)->update(['created_at' => now()->subDays(400)]);

        $this->assertSame(0, Artisan::call('kb:expansion-learn', ['action' => 'nightly']));
        $this->assertStringContainsString('pruned 1 event(s), recorded 1 proposal(s)', Artisan::output());
        $this->assertSame(1, Artisan::call('kb:expansion-learn', ['action' => 'promote', '--id' => '1']), 'promote needs --yes');
    }
}
