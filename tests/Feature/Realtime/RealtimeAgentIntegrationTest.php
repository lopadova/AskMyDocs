<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use AgentsFullDuplex\RealtimeAgent\Engine\AgentSessionManager;
use AgentsFullDuplex\RealtimeAgent\Models\AgentSessionRecord;
use App\Compliance\AskMyDocsUserDataDeleter;
use App\Compliance\AskMyDocsUserDataExporter;
use App\Contracts\AgentRunHandler;
use App\Http\Controllers\Api\RealtimeAgentSessionController;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\ProjectMembership;
use App\Models\RealtimeAgentSessionLink;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Padosoft\LaravelAiFinOps\Models\UsageRecord as FinOpsUsageRecord;
use Tests\TestCase;

final class RealtimeAgentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);
        Route::middleware(['api', 'auth'])->post(
            '/test-conversations/{conversation}/realtime-agent',
            [RealtimeAgentSessionController::class, 'store'],
        );
        Route::middleware(['api', 'auth'])->get('/test-conversations/{conversation}/realtime-agent/{session}/run',
            [RealtimeAgentSessionController::class, 'run']);
        Route::middleware(['api', 'auth'])->post('/test-conversations/{conversation}/messages/agent',
            [\App\Http\Controllers\Api\AgentMessageController::class, 'store']);
    }

    public function test_start_is_default_off_and_fake_start_is_tenant_linked(): void
    {
        [$user, $conversation] = $this->chatFixture();

        $this->actingAs($user)
            ->postJson("/test-conversations/{$conversation->id}/realtime-agent")
            ->assertStatus(503)
            ->assertJsonPath('error.reason', 'disabled');

        config()->set('realtime-agent.enabled', true);
        config()->set('realtime-agent.default', 'fake');

        $response = $this->actingAs($user)
            ->postJson("/test-conversations/{$conversation->id}/realtime-agent", [
                'filters' => ['languages' => ['it']],
                'live_sources' => ['api' => [], 'mcp' => ['mcp:shared-search']],
            ])
            ->assertCreated()
            ->assertJsonPath('provider', 'fake')
            ->assertJsonPath('connection.transport', 'fake')
            ->assertJsonPath('conversation_id', $conversation->id);

        $sessionId = (string) $response->json('session_id');
        $this->assertDatabaseHas('realtime_agent_sessions', [
            'id' => $sessionId,
            'owner_id' => (string) $user->id,
            'provider' => 'fake',
        ]);
        $link = RealtimeAgentSessionLink::query()->findOrFail($sessionId);
        $this->assertSame('acme', $link->tenant_id);
        $this->assertSame(['it'], data_get($link->filters, 'languages'));
        $this->assertSame(['mcp:shared-search'], data_get($link->live_sources, 'mcp'));
    }

    public function test_fake_tool_uses_the_canonical_durable_agent_run(): void
    {
        [$user, $conversation] = $this->chatFixture();
        config()->set('realtime-agent.enabled', true);
        config()->set('realtime-agent.default', 'fake');
        $this->app->bind(AgentRunHandler::class, static fn (): AgentRunHandler => new class implements AgentRunHandler
        {
            public function handle(AgentRun $run): void
            {
                $run->forceFill([
                    'status' => AgentRun::STATUS_COMPLETED,
                    'result_json' => [
                        'response' => [
                            'answer' => 'Risposta canonica.',
                            'citations' => [],
                            'completeness' => 'complete',
                            'limitations' => [],
                            'locale' => 'it-IT',
                        ],
                    ],
                    'completed_at' => now(),
                ])->save();
            }
        });

        $started = $this->actingAs($user)
            ->postJson("/test-conversations/{$conversation->id}/realtime-agent")
            ->assertCreated();
        $sessionId = (string) $started->json('session_id');
        $revision = (int) $started->json('state.session.revision');

        $this->actingAs($user)
            ->postJson("/realtime-agent/sessions/{$sessionId}/tools", [
                'id' => 'call-1',
                'name' => 'askmydocs.chat_turn',
                'arguments' => ['question' => 'Qual è la policy ferie?'],
                'base_revision' => $revision,
                'idempotency_key' => 'call-1',
            ])
            ->assertOk()
            ->assertJsonPath('result.status', 'completed')
            ->assertJsonPath('result.output.response.answer', 'Risposta canonica.')
            ->assertJsonPath('result.output.run.status', AgentRun::STATUS_COMPLETED);

        $run = AgentRun::query()->sole();
        $this->assertSame($conversation->id, $run->conversation_id);
        $this->assertSame('Qual è la policy ferie?', data_get($run->input_json, 'question'));
        $this->assertSame($sessionId, data_get($conversation->messages()->sole()->metadata, 'realtime_agent_session_id'));
    }

    public function test_openai_handshake_permits_spoken_progress_without_enabling_instruction_or_session_updates(): void
    {
        [$user, $conversation] = $this->chatFixture();
        config(['realtime-agent.enabled' => true, 'realtime-agent.default' => 'openai',
            'realtime-agent.providers.openai.api_key' => 'test-only-key',
            'realtime-agent.providers.openai.model' => 'gpt-live-1',
            'realtime-agent.providers.openai.backend_model' => 'gpt-5.6-terra']);
        $captured = null;
        \Illuminate\Support\Facades\Http::fake(function ($request) use (&$captured) {
            $captured = $request;

            return \Illuminate\Support\Facades\Http::response(['session' => ['id' => 'live-test'],
                'transport' => ['sdp' => "v=0\r\n"]]);
        });
        $globalMiddleware = app(\Illuminate\Http\Client\Factory::class)->getGlobalMiddleware();
        $sessionId = $this->actingAs($user)->postJson("/test-conversations/{$conversation->id}/realtime-agent")
            ->assertCreated()->json('session_id');
        $this->call('POST', "/realtime-agent/sessions/{$sessionId}/connect", [], [], [],
            ['CONTENT_TYPE' => 'application/sdp', 'HTTP_ACCEPT' => 'application/json'], "v=0\r\n")
            ->assertOk()->assertJsonPath('connection.api_variant', 'live');

        $this->assertNotNull($captured);
        // Laravel's assertion data retains the original post() arguments;
        // inspect the actual serialized request after the provider middleware.
        $channel = json_decode($captured->body(), true, flags: JSON_THROW_ON_ERROR)['session']['client']['data_channel'];
        $this->assertContains('session.commentary.append', $channel['allowed_client_events']);
        $this->assertContains('response.item.create', $channel['allowed_client_events']);
        $this->assertContains('response.create', $channel['allowed_client_events']);
        $this->assertNotContains('session.instructions.append', $channel['allowed_client_events']);
        $this->assertNotContains('session.update', $channel['allowed_client_events']);
        $this->assertContains(['type' => 'session.commentary.appended'], $channel['allowed_server_events']);
        $this->assertSame((string) strlen($captured->body()), $captured->header('Content-Length')[0]);
        $this->assertSame($globalMiddleware, app(\Illuminate\Http\Client\Factory::class)->getGlobalMiddleware());
    }

    public function test_receipt_is_visible_while_the_voice_tool_is_still_running_and_is_scoped_to_call_and_owner(): void
    {
        [$user, $conversation] = $this->chatFixture();
        config(['realtime-agent.enabled' => true, 'realtime-agent.default' => 'fake']);
        $sessionId = $this->actingAs($user)->postJson("/test-conversations/{$conversation->id}/realtime-agent")
            ->assertCreated()->json('session_id');
        $url = "/test-conversations/{$conversation->id}/realtime-agent/{$sessionId}/run?call_id=call-live";
        $this->getJson($url)->assertOk()->assertJsonPath('run', null);
        $this->app->bind(AgentRunHandler::class, fn () => new class($this, $url) implements AgentRunHandler
        {
            public function __construct(private $test, private string $url) {}
            public function handle(AgentRun $run): void
            {
                $run->forceFill(['status' => AgentRun::STATUS_RUNNING])->save();
                $this->test->getJson($this->url)->assertOk()
                    ->assertJsonPath('run.run_id', $run->run_id)
                    ->assertJsonPath('run.status', 'running')
                    ->assertJsonPath('run.user_message.content', 'Hub e ordine');
                $run->forceFill(['status' => AgentRun::STATUS_COMPLETED,
                    'result_json' => ['response' => ['answer' => 'Risposta canonica.']]])->save();
            }
        });
        $session = app(AgentSessionManager::class)->resume($sessionId);
        $result = app(\App\Realtime\HandleRealtimeChatTurn::class)->handle($session,
            new \AgentsFullDuplex\RealtimeAgent\Data\ToolCall('call-live', 'askmydocs.chat_turn', ['question' => 'Hub e ordine'], 1, 'call-live'));
        $this->assertSame('Risposta canonica.', $result['response']['answer']);
        $this->getJson(str_replace('call-live', 'another-call', $url))->assertOk()->assertJsonPath('run', null);
        $this->getJson(str_replace($sessionId, 'unknown-session', $url))->assertNotFound();
        app(TenantContext::class)->set('another-tenant');
        $this->getJson($url)->assertNotFound();
        app(TenantContext::class)->set('acme');
        $other = User::create(['name' => 'Other', 'email' => 'other-live@example.test', 'password' => Hash::make('test')]);
        $this->actingAs($other)->getJson($url)->assertForbidden();
        $this->actingAs($user);
        RealtimeAgentSessionLink::whereKey($sessionId)->update(['expires_at' => now()->subMinute()]);
        $this->getJson($url)->assertNotFound();
    }

    public static function inlineExecutionLimits(): array
    {
        return [
            'HTTP success' => [30, 900, false],
            'HTTP failure' => [30, 900, true],
            'unlimited success' => [0, 0, false],
            'unlimited failure' => [0, 0, true],
            'longer existing limit' => [1200, 1200, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inlineExecutionLimits')]
    public function test_voice_pipeline_uses_the_agent_time_budget_and_restores_the_request_limit(int $requestLimit, int $expectedLimit, bool $fails): void
    {
        [$user, $conversation] = $this->chatFixture();
        config(['realtime-agent.enabled' => true, 'realtime-agent.default' => 'fake', 'agent.job_timeout_seconds' => 900]);
        $sessionId = $this->actingAs($user)->postJson("/test-conversations/{$conversation->id}/realtime-agent")
            ->assertCreated()->json('session_id');
        $observed = new \ArrayObject();
        $this->app->bind(AgentRunHandler::class, fn () => new class($observed, $fails) implements AgentRunHandler
        {
            public function __construct(private \ArrayObject $observed, private bool $fails) {}

            public function handle(AgentRun $run): void
            {
                $this->observed['limit'] = (int) ini_get('max_execution_time');
                if ($this->fails) {
                    throw new \RuntimeException('Synthetic inline execution failure.');
                }
                $run->forceFill(['status' => AgentRun::STATUS_COMPLETED,
                    'result_json' => ['response' => ['answer' => 'Risposta canonica.']]])->save();
            }
        });
        $session = app(AgentSessionManager::class)->resume($sessionId);
        $originalLimit = (int) ini_get('max_execution_time');
        try {
            set_time_limit($requestLimit);
            try {
                $result = app(\App\Realtime\HandleRealtimeChatTurn::class)->handle($session,
                    new \AgentsFullDuplex\RealtimeAgent\Data\ToolCall('budget-call', 'askmydocs.chat_turn', ['question' => 'Qual è la policy?'], 1, 'budget-call'));
                $this->assertFalse($fails, 'The failed handler must propagate its exception.');
                $this->assertSame('Risposta canonica.', $result['response']['answer']);
            } catch (\RuntimeException $exception) {
                $this->assertTrue($fails);
                $this->assertSame('Synthetic inline execution failure.', $exception->getMessage());
            }
            $this->assertSame($expectedLimit, $observed['limit']);
            $this->assertSame($requestLimit, (int) ini_get('max_execution_time'));
        } finally {
            set_time_limit($originalLimit);
        }
    }

    public function test_busy_conversation_rejects_voice_and_text_without_creating_or_cancelling_turns(): void
    {
        [$user, $conversation] = $this->chatFixture();
        config(['realtime-agent.enabled' => true, 'realtime-agent.default' => 'fake']);
        \Illuminate\Support\Facades\Queue::fake();
        $context = app(\App\Agent\AgentExecutionContextFactory::class)->forUser($user, $conversation->project_key);
        $turn = app(\App\Agent\AgentChatTurnStarter::class)->start($context, $conversation, $user, 'Prima domanda', ['question' => 'Prima domanda']);
        $sessionId = $this->actingAs($user)->postJson("/test-conversations/{$conversation->id}/realtime-agent")
            ->assertCreated()->json('session_id');
        $session = app(AgentSessionManager::class)->resume($sessionId);
        foreach ([AgentRun::STATUS_QUEUED, AgentRun::STATUS_RUNNING, AgentRun::STATUS_AWAITING_CONFIRMATION] as $status) {
            $turn->run->forceFill(['status' => $status])->save();
            $result = app(\App\Realtime\HandleRealtimeChatTurn::class)->handle($session,
                new \AgentsFullDuplex\RealtimeAgent\Data\ToolCall('overlap-'.$status, 'askmydocs.chat_turn', ['question' => 'Seconda domanda'], 1, 'overlap-'.$status));
            $this->assertTrue($result['busy']);
            $this->assertFalse($result['retry']);
            $this->assertStringContainsString('Un attimo, una cosa alla volta', $result['response']['answer']);
            $this->assertArrayNotHasKey('run', $result);
            $this->postJson("/test-conversations/{$conversation->id}/messages/agent", ['content' => 'Da un’altra scheda'])
                ->assertStatus(409)->assertJsonPath('error', 'agent_conversation_busy');
            $this->assertSame($status, $turn->run->fresh()->status);
            $this->assertSame(1, AgentRun::count());
            $this->assertSame(1, $conversation->messages()->count());
        }
        // The next explicit request is allowed once the prior request ends.
        $turn->run->forceFill(['status' => AgentRun::STATUS_COMPLETED])->save();
        $this->postJson("/test-conversations/{$conversation->id}/messages/agent", ['content' => 'Ora la seconda domanda'])->assertStatus(202);
        $this->assertSame(2, $conversation->messages()->count());
        $this->assertSame(2, AgentRun::count());
    }

    public function test_usage_is_projected_to_finops_once_and_dsars_follow_the_session_link(): void
    {
        [$user, $conversation] = $this->chatFixture();
        config()->set('realtime-agent.enabled', true);
        config()->set('realtime-agent.default', 'fake');
        config()->set('ai-finops.enabled', true);
        config()->set('ai-finops.metering', true);

        $started = $this->actingAs($user)
            ->postJson("/test-conversations/{$conversation->id}/realtime-agent")
            ->assertCreated();
        $sessionId = (string) $started->json('session_id');
        $session = app(AgentSessionManager::class)->resume($sessionId);
        $usage = [
            'kind' => 'response',
            'model' => 'fake-realtime',
            'units' => ['input_text_tokens' => 8, 'output_audio_tokens' => 13],
            'raw' => ['deterministic' => true],
            'idempotency_key' => 'usage-1',
        ];
        $session->recordUsage($usage);
        $session->recordUsage($usage);

        $ledger = FinOpsUsageRecord::query()
            ->where('trace_id', 'like', 'realtime-agent:%')
            ->sole();
        $this->assertSame('acme', $ledger->tenant_id);
        $this->assertSame((string) $user->id, $ledger->user_id);
        $this->assertSame(8, $ledger->tokens_input);
        $this->assertSame(13, $ledger->tokens_output);

        $export = app(AskMyDocsUserDataExporter::class)->export($user);
        $this->assertSame($sessionId, $export['realtime_agent_sessions'][0]['id']);
        $this->assertCount(1, $export['realtime_agent_usage']);

        app(AskMyDocsUserDataDeleter::class)->delete($user);
        $this->assertDatabaseMissing('realtime_agent_sessions', ['id' => $sessionId]);
        $this->assertDatabaseMissing('realtime_agent_session_links', ['session_id' => $sessionId]);
    }

    public function test_prune_only_removes_sessions_past_retention(): void
    {
        [$user, $conversation] = $this->chatFixture();
        config()->set('realtime-agent.enabled', true);
        config()->set('realtime-agent.default', 'fake');

        $old = (string) $this->actingAs($user)
            ->postJson("/test-conversations/{$conversation->id}/realtime-agent")
            ->assertCreated()
            ->json('session_id');
        $fresh = (string) $this->actingAs($user)
            ->postJson("/test-conversations/{$conversation->id}/realtime-agent")
            ->assertCreated()
            ->json('session_id');
        RealtimeAgentSessionLink::query()->whereKey($old)->update(['expires_at' => now()->subDays(100)]);
        AgentSessionRecord::query()->whereKey($old)->update(['expires_at' => now()->subDays(100)]);

        $this->artisan('realtime-agent:prune --days=90')->assertSuccessful();

        $this->assertDatabaseMissing('realtime_agent_sessions', ['id' => $old]);
        $this->assertDatabaseHas('realtime_agent_sessions', ['id' => $fresh]);
    }

    /** @return array{User,Conversation} */
    private function chatFixture(): array
    {
        app(TenantContext::class)->set('acme');
        $user = User::query()->create([
            'name' => 'Realtime user',
            'email' => 'realtime-'.uniqid().'@example.test',
            'password' => Hash::make('secret-pass-123'),
            'locale' => 'it-IT',
        ]);
        ProjectMembership::query()->create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'handbook',
            'role' => 'member',
        ]);
        $conversation = Conversation::query()->create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'title' => 'Live',
            'project_key' => 'handbook',
        ]);

        return [$user, $conversation];
    }
}
