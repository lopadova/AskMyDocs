<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\ProjectMembership;
use App\Models\QaCampaign;
use App\Models\QaInferenceAttempt;
use App\Models\User;
use App\Services\Qa\CampaignLedger;
use App\Services\Qa\CampaignScope;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class QaCampaignTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['qa.campaigns_enabled' => true, 'agent.diagnostics.release' => str_repeat('a', 40)]);
        app(TenantContext::class)->set('acme');
        $this->seed(RbacSeeder::class);
        $this->actor = User::create(['name' => 'QA', 'email' => 'campaign@example.test', 'password' => 'password', 'is_active' => true]);
        $this->actor->assignRole('admin');
        ProjectMembership::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'user_id' => $this->actor->id, 'role' => 'member']);
        Http::preventStrayRequests();
    }

    private function manifest(float $budget = 10): array
    {
        return ['schema_version' => 1, 'budget_usd' => $budget, 'max_calls' => 100,
            'corpus_sha256' => str_repeat('b', 64), 'live_sources' => ['api' => [], 'mcp' => []],
            'allowed_models' => ['test/model' => ['paths' => ['/api/v1/chat/completions', '/api/v1/embeddings', '/api/alpha/decisions'],
                'input_per_million' => 1, 'output_per_million' => 2, 'output_reserve_tokens' => 4096,
                'response_models' => ['test/model'], 'price_source' => 'https://openrouter.ai/api/v1/models',
                'price_observed_at' => now()->toIso8601String()]]];
    }

    private function campaign(float $budget = 10): QaCampaign
    {
        return app(CampaignLedger::class)->create('acme', 'orders', $this->actor, $this->manifest($budget));
    }

    private function runFixture(QaCampaign $campaign): AgentRun
    {
        return AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'acme', 'project_key' => 'orders',
            'user_id' => $this->actor->id, 'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => (string) $this->actor->id,
            'locale' => 'it', 'timezone' => 'UTC', 'status' => 'running',
            'input_json' => ['qa' => ['campaign_id' => $campaign->id, 'round' => 'baseline', 'case_id' => 'DEMO-01', 'turn' => 1,
                'release' => config('agent.diagnostics.release')]]]);
    }

    private function infer(string $path = '/api/v1/chat/completions')
    {
        return Http::post('https://openrouter.ai'.$path, ['model' => 'test/model', 'messages' => [['role' => 'user', 'content' => 'private original']], 'max_tokens' => 100]);
    }

    public function test_real_http_middleware_reserves_and_records_without_storing_originals(): void
    {
        $campaign = $this->campaign();
        $run = $this->runFixture($campaign);
        Http::fake(['*' => Http::response(['model' => 'test/model', 'usage' => ['cost' => .0002]])]);
        app(CampaignScope::class)->withinRun($run, fn () => $this->infer());
        $attempt = QaInferenceAttempt::sole();
        $this->assertSame(200000, $campaign->fresh()->spent_nanos);
        $this->assertSame(0, $campaign->fresh()->reserved_nanos);
        $this->assertSame('recorded', $attempt->status);
        $this->assertSame('provider_usage', $attempt->cost_source);
        $this->assertIsInt($attempt->duration_ms);
        $this->assertIsArray($attempt->caller_classes);
        $this->assertSame($run->run_id, $attempt->run_uuid);
        $this->assertStringNotContainsString('private original', $attempt->toJson());
        Http::assertSent(fn ($r) => json_decode($r->body(), true)['provider']['require_parameters'] === true
            && (float) json_decode($r->body(), true)['provider']['max_price']['prompt'] === 1.0
            && json_decode($r->body(), true)['plugins'] === [['id' => 'web', 'enabled' => false]]);
    }

    public function test_pending_reservations_are_shared_and_completion_is_idempotent(): void
    {
        $campaign = $this->campaign(.001);
        $run = $this->runFixture($campaign);
        $request = ['path' => '/api/v1/chat/completions', 'requested_model' => 'test/model', 'request_sha256' => str_repeat('c', 64)];
        $one = (new CampaignLedger)->reserve($campaign, $run, (string) Str::uuid(), $request, 600000);
        try {
            (new CampaignLedger)->reserve($campaign, $run, (string) Str::uuid(), $request, 600000);
            $this->fail('Concurrent allowance was multiplied.');
        } catch (\DomainException $e) { $this->assertSame('qa_budget_exhausted', $e->getMessage()); }
        $ledger = new CampaignLedger;
        $ledger->finish($one, 400000, 200, 'test/model', null);
        $ledger->finish($one, 400000, 200, 'test/model', null);
        $this->assertSame(400000, $campaign->fresh()->spent_nanos);
        $this->assertSame(0, $campaign->fresh()->reserved_nanos);
        $this->assertSame('stopped', $campaign->fresh()->status);
    }

    public function test_unknown_billing_retains_reservation_and_stops_another_request(): void
    {
        $campaign = $this->campaign();
        Http::fake(['*' => Http::response(['model' => 'test/model'])]);
        app(CampaignScope::class)->withinRun($this->runFixture($campaign), fn () => $this->infer());
        $this->assertNull(QaInferenceAttempt::sole()->cost_nanos);
        $this->assertGreaterThan(0, $campaign->fresh()->reserved_nanos);
        $this->assertSame('stopped', $campaign->fresh()->status);
        try {
            app(CampaignScope::class)->withinRun($this->runFixture($campaign), fn () => $this->infer());
            $this->fail('Unknown billing was treated as zero.');
        } catch (\DomainException $e) { $this->assertSame('qa_campaign_stopped', $e->getMessage()); }
        Http::assertSentCount(1);
    }

    public function test_read_only_generation_lookup_can_reconcile_a_missing_cost(): void
    {
        Http::fake(['*/generation*' => Http::response(['data' => ['total_cost' => .0001]]),
            '*' => Http::response(['id' => 'generation', 'model' => 'test/model'])]);
        $campaign = $this->campaign();
        app(CampaignScope::class)->withinRun($this->runFixture($campaign), fn () => $this->infer());
        $this->assertSame(100000, $campaign->fresh()->spent_nanos);
        $this->assertSame(1, $campaign->fresh()->attempt_count);
        $this->assertSame('active', $campaign->fresh()->status);
        $this->assertSame('provider_generation', QaInferenceAttempt::sole()->cost_source);
        $this->assertSame('generation', QaInferenceAttempt::sole()->generation_id);
        Http::assertSentCount(2);
    }

    public function test_connection_failure_and_retry_cannot_charge_a_second_attempt(): void
    {
        Http::fake(fn () => Http::failedConnection());
        $campaign = $this->campaign();
        try {
            app(CampaignScope::class)->withinRun($this->runFixture($campaign), fn () => Http::retry(3, 0)
                ->post('https://openrouter.ai/api/v1/chat/completions', ['model' => 'test/model', 'max_tokens' => 100]));
        } catch (\Throwable) { }
        $this->assertSame(1, QaInferenceAttempt::count());
        $this->assertSame('unknown', QaInferenceAttempt::sole()->status);
        $this->assertSame('stopped', $campaign->fresh()->status);
    }

    public function test_mismatched_model_and_above_reservation_cost_stop_campaign(): void
    {
        Http::fake(['*' => Http::response(['model' => 'another/model', 'usage' => ['cost' => 1]])]);
        $campaign = $this->campaign();
        app(CampaignScope::class)->withinRun($this->runFixture($campaign), fn () => $this->infer());
        $this->assertSame('qa_response_model_mismatch', $campaign->fresh()->stop_reason);
        $this->assertSame(1000000000, $campaign->fresh()->spent_nanos);
    }

    public function test_unapproved_model_endpoint_and_streaming_fail_before_dispatch(): void
    {
        foreach ([['model' => 'foreign/model', 'max_tokens' => 1], ['model' => 'test/model', 'max_tokens' => 1, 'stream' => true],
            ['model' => 'test/model'], ['model' => 'test/model', 'max_tokens' => 1, 'models' => ['foreign/model']],
            ['model' => 'test/model', 'max_tokens' => 1, 'preset' => 'opaque-preset'],
            ['model' => 'test/model', 'max_tokens' => 1, 'tools' => [['type' => 'openrouter:web_search']]]] as $body) {
            Http::fake();
            $campaign = $this->campaign();
            try {
                app(CampaignScope::class)->withinRun($this->runFixture($campaign), fn () => Http::post('https://openrouter.ai/api/v1/chat/completions', $body));
                $this->fail('Unapproved inference dispatched.');
            } catch (\DomainException) { }
            Http::assertNothingSent();
            $this->assertSame('stopped', $campaign->fresh()->status);
        }
    }

    public function test_all_configured_inference_paths_share_one_campaign_and_scope_does_not_leak(): void
    {
        Http::fake(['*' => Http::response(['model' => 'test/model', 'usage' => ['cost' => 0]])]);
        $manifest = $this->manifest();
        $manifest['allowed_models']['test/model']['output_per_million'] = 0;
        $campaign = app(CampaignLedger::class)->create('acme', 'orders', $this->actor, $manifest);
        app(CampaignScope::class)->withinRun($this->runFixture($campaign), function (): void {
            $this->infer('/api/v1/embeddings');
            $this->infer('/api/alpha/decisions');
            $this->infer();
        });
        $this->assertSame(3, $campaign->fresh()->attempt_count);
        Http::post('https://another.example.test/chat/completions', ['model' => 'unrestricted']);
        $this->assertSame(3, QaInferenceAttempt::count());
    }

    public function test_revoked_actor_and_disabled_flag_do_not_run_unguarded(): void
    {
        $campaign = $this->campaign();$run = $this->runFixture($campaign);
        $this->actor->removeRole('admin');
        try {
            app(CampaignScope::class)->withinRun($run, fn () => $this->fail('Revoked actor entered scope.'));
            $this->fail('Revocation ignored.');
        } catch (\DomainException $e) { $this->assertSame('qa_campaign_actor_not_authorized', $e->getMessage()); }
        config(['qa.campaigns_enabled' => false]);
        $this->expectExceptionMessage('qa_campaigns_disabled');
        app(CampaignScope::class)->withinRun($run, fn () => $this->fail('Disabled campaign ran.'));
    }

    public function test_scope_checks_owner_project_and_tenant(): void
    {
        $campaign = $this->campaign();
        foreach (['tenant_id' => 'other', 'project_key' => 'other', 'user_id' => 999] as $field => $value) {
            $run = $this->runFixture($campaign);
            $run->$field = $value;
            try {
                app(CampaignScope::class)->withinRun($run, fn () => $this->fail('Foreign campaign entered.'));
                $this->fail('Ownership mismatch was ignored.');
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_unbounded_non_free_decision_output_cannot_dispatch(): void
    {
        Http::fake();
        $campaign = $this->campaign();
        try {
            app(CampaignScope::class)->withinRun($this->runFixture($campaign), fn () => $this->infer('/api/alpha/decisions'));
            $this->fail('Unbounded paid decision output dispatched.');
        } catch (\DomainException $e) { $this->assertSame('qa_unbounded_output', $e->getMessage()); }
        Http::assertNothingSent();
        $this->assertSame(0, QaInferenceAttempt::count());
    }

    public function test_sdk_text_and_embedding_requests_are_both_metered(): void
    {
        config(['ai.providers.openrouter.driver' => 'openrouter', 'ai.providers.openrouter.key' => 'test-key',
            'ai.providers.openrouter.url' => 'https://openrouter.ai/api/v1',
            'ai.providers.openrouter.models.embeddings.default' => 'test/model']);
        Http::fake(['*/embeddings' => Http::response(['model' => 'test/model', 'data' => [['embedding' => [0.1, 0.2, 0.3], 'index' => 0]],
            'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1, 'cost' => .0001]]),
            '*' => Http::response(['id' => 'text', 'model' => 'test/model', 'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'cost' => .0001]])]);
        $campaign = $this->campaign();
        app(CampaignScope::class)->withinRun($this->runFixture($campaign), function (): void {
            $provider = new \App\Ai\Providers\OpenRouterProvider(config('ai.providers.openrouter'));
            $provider->chat('System', 'Question', ['model' => 'test/model', 'max_tokens' => 100]);
            $provider->generateEmbeddings(['text']);
        });
        $this->assertSame(2, $campaign->fresh()->attempt_count);
        $this->assertSame(200000, $campaign->fresh()->spent_nanos);
        $this->assertSame('active', $campaign->fresh()->status);
    }

    public function test_two_real_processes_cannot_reserve_more_than_one_shared_budget(): void
    {
        $directory = sys_get_temp_dir().'/qa-concurrency-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $database = $directory.'/database.sqlite';
        touch($database);
        config(['database.connections.qa_concurrency' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'busy_timeout' => 10000]]);
        $connection = \Illuminate\Support\Facades\DB::connection('qa_concurrency');
        $oldSchema = \Illuminate\Support\Facades\Schema::getFacadeRoot();
        $processes = [];
        try {
            \Illuminate\Support\Facades\Schema::swap($connection->getSchemaBuilder());
            (require dirname(__DIR__, 3).'/database/migrations/2026_11_02_160000_create_qa_campaign_tables.php')->up();
            $campaign = $this->campaign(.001);
            $connection->table('qa_campaigns')->insert($campaign->getAttributes());
            $barrier = $directory.'/start';
            for ($i = 0; $i < 2; $i++) {
                $process = new \Symfony\Component\Process\Process([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/Qa/reserve.php', $database, $campaign->id, $barrier]);
                $process->setTimeout(15);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (count(glob($barrier.'.*.ready')) < 2 && microtime(true) < $deadline) usleep(20000);
            $this->assertCount(2, glob($barrier.'.*.ready'));
            touch($barrier);
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = $process->getOutput();
            }
            sort($results);
            $this->assertSame(['allowed', 'blocked'], $results);
            $row = $connection->table('qa_campaigns')->first();
            $this->assertSame(600000, (int) $row->reserved_nanos);
            $this->assertSame(1, (int) $row->attempt_count);
        } finally {
            foreach ($processes as $process) if ($process->isRunning()) $process->stop();
            \Illuminate\Support\Facades\Schema::swap($oldSchema);
            \Illuminate\Support\Facades\DB::purge('qa_concurrency');
            foreach (glob($directory.'/*') as $file) unlink($file);
            rmdir($directory);
        }
    }

    public function test_manifest_refuses_missing_prices_and_budget_above_ten_dollars(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->campaign(10.01);
    }

    public function test_operator_probe_uses_two_bootstrapped_processes_and_never_owns_a_chat_actor(): void
    {
        $probe = app(\App\Services\Qa\CampaignBudgetProbe::class);
        $campaign = $probe->create('acme', 'orders', $this->actor);
        $this->assertSame(0, (int) $campaign->actor_id);
        $this->assertSame('ledger_reservation_v1', $campaign->manifest_json['simulation']);
        $directory = sys_get_temp_dir().'/qa-probe-test-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $database = $directory.'/database.sqlite';
        touch($database);
        config(['database.connections.qa_probe_test' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'busy_timeout' => 10000]]);
        $db = \Illuminate\Support\Facades\DB::getFacadeRoot();
        $oldDefault = $db->getDefaultConnection();
        $connection = $db->connection('qa_probe_test');
        $oldSchema = \Illuminate\Support\Facades\Schema::getFacadeRoot();
        try {
            \Illuminate\Support\Facades\Schema::swap($connection->getSchemaBuilder());
            (require dirname(__DIR__, 3).'/database/migrations/2026_11_02_160000_create_qa_campaign_tables.php')->up();
            $connection->table('qa_campaigns')->insert($campaign->getAttributes());
            $db->setDefaultConnection('qa_probe_test');
            $fileCampaign = QaCampaign::query()->forTenant('acme')->findOrFail($campaign->id);
            $result = $probe->exercise($fileCampaign, ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
                'APP_RELEASE' => str_repeat('a', 40), 'APP_CONFIG_CACHE' => $directory.'/absent-config.php',
                'ASKMYDOCS_QA_CAMPAIGNS_ENABLED' => 'true', 'ASKMYDOCS_DEPLOYMENT_TIER' => 'development',
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => false, 'DATABASE_URL' => false,
                'ASKMYDOCS_QA_MANIFEST' => false, 'AI_PROVIDER' => 'fake', 'LOG_CHANNEL' => 'null',
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync']);
            $this->assertSame(['blocked', 'reserved'], $result['results']);
            $this->assertSame(0, $result['inference_calls']);
            $this->assertSame('0.000000000', $result['spent_usd']);
            $this->assertSame('simulation', QaInferenceAttempt::query()->forTenant('acme')->sole()->cost_source);
        } finally {
            $db->setDefaultConnection($oldDefault);
            \Illuminate\Support\Facades\Schema::swap($oldSchema);
            $db->purge('qa_probe_test');
            foreach (glob($directory.'/*') as $file) unlink($file);
            rmdir($directory);
        }
        Http::assertNothingSent();
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(CampaignScope::class)->withinRun($this->runFixture($campaign), fn () => $this->infer());
    }

    public function test_missing_tariff_is_rejected_instead_of_assumed_free(): void
    {
        $manifest = $this->manifest();
        unset($manifest['allowed_models']['test/model']['input_per_million']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(CampaignLedger::class)->create('acme', 'orders', $this->actor, $manifest);
    }

    public function test_provider_native_model_ids_with_dots_remain_literal_manifest_keys(): void
    {
        $manifest = $this->manifest();
        $manifest['allowed_models']['typesafe/jev-1.13'] = $manifest['allowed_models']['test/model'];
        $manifest['allowed_models']['typesafe/jev-1.13']['output_per_million'] = 0;
        $campaign = app(CampaignLedger::class)->create('acme', 'orders', $this->actor, $manifest);
        $this->assertArrayHasKey('typesafe/jev-1.13', $campaign->manifest_json['allowed_models']);
    }

    public function test_interrupted_scope_retains_reservation_and_first_stop_reason(): void
    {
        $campaign = $this->campaign();
        $run = $this->runFixture($campaign);
        app(CampaignScope::class)->withinRun($run, function () use ($campaign, $run): void {
            // An interrupted transport never returned its completion callback.
            $ledger = app(CampaignLedger::class);
            $ledger->reserve($campaign, $run, 'orphan-sibling', ['path' => '/api/v1/chat/completions',
                'requested_model' => 'test/model', 'request_sha256' => str_repeat('c', 64)], 1000);
            $ledger->stop($campaign, 'qa_first_reason');
            $ledger->stop($campaign, 'qa_later_reason');
        });
        $this->assertSame('pending', QaInferenceAttempt::sole()->status);
        app(CampaignLedger::class)->interruptScope('acme', $campaign->id, 'orphan-sibling');
        $this->assertSame('unknown', QaInferenceAttempt::sole()->status);
        $this->assertSame(1000, $campaign->fresh()->reserved_nanos);
        $this->assertSame('qa_first_reason', $campaign->fresh()->stop_reason);
    }

    public function test_stale_reservation_blocks_dispatch_and_worker_release_is_checked(): void
    {
        $campaign = $this->campaign();
        $run = $this->runFixture($campaign);
        app(CampaignLedger::class)->reserve($campaign, $run, 'stale', ['path' => '/api/v1/chat/completions',
            'requested_model' => 'test/model', 'request_sha256' => str_repeat('c', 64)], 1000);
        QaInferenceAttempt::query()->update(['started_at' => now()->subHour()]);
        Http::fake();
        try {
            app(CampaignScope::class)->withinRun($run, fn () => $this->infer());
            $this->fail('Stale reservation did not block.');
        } catch (\DomainException $e) { $this->assertSame('qa_stale_reservation', $e->getMessage()); }
        Http::assertNothingSent();
        $run = $this->runFixture($this->campaign());
        $run->input_json = ['qa' => [...$run->input_json['qa'], 'release' => str_repeat('c', 40)]];
        $this->expectExceptionMessage('qa_worker_release_mismatch');
        app(CampaignScope::class)->withinRun($run, fn () => $this->infer());
    }

    public function test_cli_manifest_snapshot_and_export_survive_disabled_activation(): void
    {
        $directory = sys_get_temp_dir().'/qa-manifest-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $file = $directory.'/manifest.json';
        file_put_contents($file, json_encode([...$this->manifest(), 'prompt' => 'must not persist']));
        try {
            $this->artisan('qa:campaign', ['action' => 'create', '--manifest' => $file,
                '--tenant' => 'acme', '--project' => 'orders', '--actor' => $this->actor->id])->assertSuccessful();
            $campaign = QaCampaign::sole();
            $this->assertArrayNotHasKey('prompt', $campaign->manifest_json);
            $this->assertSame('qa-rubric-v1', $campaign->manifest_json['evaluation']['version']);
            $this->assertSame(str_repeat('a', 40), $campaign->manifest_json['configuration']['runtime']['release']);
            config(['qa.campaigns_enabled' => false]);
            $this->artisan('qa:campaign', ['action' => 'export', '--id' => $campaign->id,
                '--tenant' => 'acme', '--project' => 'orders', '--actor' => $this->actor->id])->assertSuccessful();
        } finally { unlink($file); rmdir($directory); }
    }

    public function test_http_campaign_binding_requires_debug_and_narrows_live_sources(): void
    {
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->prependMiddleware(\App\Http\Middleware\ResolveTenant::class);
        Queue::fake();
        $campaign = $this->campaign();
        $conversation = Conversation::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'user_id' => $this->actor->id, 'title' => 'QA']);
        $qa = ['campaign_id' => $campaign->id, 'round' => 'baseline', 'case_id' => 'DEMO-01', 'turn' => 1];
        $token = $this->actor->createToken('qa', ['chat:use', 'chat:tenant:acme'])->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Tenant-Id' => 'acme'])
            ->postJson('/api/chat/conversations/'.$conversation->id.'/messages', ['content' => 'Question', 'qa' => $qa])->assertForbidden();
        $this->assertSame(0, $conversation->messages()->count());
        auth()->forgetGuards();
        $token = $this->actor->createToken('debug', ['chat:use', 'chat:tenant:acme', 'chat:debug'])->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token, 'X-Tenant-Id' => 'acme'];
        $this->withHeaders($headers)->postJson('/api/chat/conversations/'.$conversation->id.'/messages', ['content' => 'Question', 'qa' => $qa])->assertAccepted();
        $run = AgentRun::sole();
        $this->assertSame([], data_get($run->input_json, 'live_sources.api'));
        $this->assertSame($campaign->id, data_get($run->input_json, 'qa.campaign_id'));
        $run->update(['status' => 'completed']);
        $this->withHeaders($headers)->postJson('/api/chat/conversations/'.$conversation->id.'/messages', ['content' => 'Followup'])->assertUnprocessable();
        $this->withHeaders($headers)->postJson('/api/chat/conversations/'.$conversation->id.'/messages', ['content' => 'Question', 'qa' => $qa,
            'live_sources' => ['api' => ['api:unauthorized'], 'mcp' => []]])->assertUnprocessable();
        $this->withHeaders($headers)->getJson('/api/chat/qa-campaigns/'.$campaign->id.'?project_key=orders')
            ->assertOk()->assertJsonPath('budget_usd', '10.000000000')->assertHeader('Cache-Control', 'no-store, private');
        config(['qa.campaigns_enabled' => false]);
        $this->withHeaders($headers)->getJson('/api/chat/qa-campaigns/'.$campaign->id.'?project_key=orders')->assertOk();
        $this->withHeaders($headers)->postJson('/api/chat/conversations/'.$conversation->id.'/messages',
            ['content' => 'Followup', 'qa' => $qa])->assertNotFound();
    }
}
