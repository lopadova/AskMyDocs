<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Jobs\ExecuteAgentRunJob;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class ChatApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class)
            ->prependMiddleware(\App\Http\Middleware\ResolveTenant::class);
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('acme');
        $this->user = User::create(['name' => 'QA', 'email' => 'qa@example.test', 'password' => 'password', 'is_active' => true]);
        ProjectMembership::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'user_id' => $this->user->id, 'role' => 'member']);
        $this->token = $this->user->createToken('qa', ['chat:use', 'chat:tenant:acme'], now()->addDay())->plainTextToken;
        Queue::fake();
    }

    public function test_bearer_turn_uses_the_chat_pipeline_and_can_be_observed_without_cookies(): void
    {
        $conversation = $this->api('POST', '/api/chat/conversations', ['project_key' => 'orders'])
            ->assertCreated()->json('id');
        $accepted = $this->api('POST', "/api/chat/conversations/$conversation/messages", [
            'content' => 'Incrocia U858724 e U858798', 'depth' => 4, 'live_sources' => ['api' => [], 'mcp' => []],
            'filters' => ['languages' => ['it']],
        ])->assertAccepted();
        $id = $accepted->json('run_id');
        $accepted->assertJsonPath('events_url', "/api/chat/runs/$id/events");
        $run = AgentRun::query()->sole();
        $this->assertSame(4, data_get($run->input_json, 'depth'));
        $this->assertSame(['it'], data_get($run->input_json, 'filters.languages'));
        $this->assertSame([], data_get($run->input_json, 'live_sources.api'));
        Queue::assertPushed(ExecuteAgentRunJob::class, fn ($job) => $job->agentRunId === $run->id && $job->tenantId === 'acme');
        $this->api('GET', "/api/chat/runs/$id")->assertOk()->assertJsonPath('status', 'queued')
            ->assertJsonPath('conversation_id', $conversation)->assertHeader('Cache-Control', 'no-store, private');
        $this->api('GET', "/api/chat/conversations/$conversation/messages")->assertOk()
            ->assertJsonPath('0.content', 'Incrocia U858724 e U858798');
        $this->api('POST', "/api/chat/runs/$id/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->api('GET', "/api/chat/runs/$id")->assertOk()->assertJsonPath('terminal', true);
    }

    public function test_headless_routes_have_unique_names_and_a_working_preflight(): void
    {
        $names = collect(app('router')->getRoutes())->filter(fn ($route) => in_array(\App\Http\Middleware\ChatApiTokenAccess::class, $route->middleware(), true))
            ->map(fn ($route) => $route->getName())->values();
        $this->assertCount($names->count(), $names->unique());
        $this->assertFalse($names->contains(null));
        $this->getJson('/api/chat/me')->assertUnauthorized();
        $this->api('GET', '/api/chat/me')->assertOk()->assertJsonPath('tenant_id', 'acme')
            ->assertJsonPath('projects', ['orders'])->assertJsonPath('debug', false);
    }

    public function test_tokens_are_tenant_bound_even_when_the_user_belongs_to_both_tenants(): void
    {
        ProjectMembership::create(['tenant_id' => 'other', 'project_key' => 'orders', 'user_id' => $this->user->id, 'role' => 'member']);
        $this->api('GET', '/api/chat/conversations', [], 'other')->assertForbidden();
        $this->api('POST', '/api/chat/conversations', ['project_key' => 'forbidden'])->assertForbidden();
        $this->api('GET', '/api/auth/me')->assertForbidden();
        $this->api('POST', '/api/kb/chat', ['question' => 'no'])->assertForbidden();
        $this->user->forceFill(['is_active' => false])->save();
        $this->api('GET', '/api/chat/conversations')->assertForbidden();
    }

    public function test_the_real_catalog_url_accepts_bearer_tokens_and_keeps_its_session_client(): void
    {
        $this->getJson('/api/chat/live-sources?project_key=orders')->assertUnauthorized();
        $this->api('GET', '/api/chat/live-sources?project_key=orders')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->api('GET', '/api/chat/live-sources?project_key=forbidden')->assertForbidden();
        ProjectMembership::create(['tenant_id' => 'other', 'project_key' => 'orders', 'user_id' => $this->user->id, 'role' => 'member']);
        $this->api('GET', '/api/chat/live-sources?project_key=orders', [], 'other')->assertForbidden();

        auth()->forgetGuards();
        $this->actingAs($this->user, 'web')->withHeader('Authorization', '')
            ->withHeader('X-Tenant-Id', 'acme')->getJson('/api/chat/live-sources?project_key=orders')->assertOk();
        $this->getJson('/api/chat/conversations')->assertForbidden(); // Other headless routes still require Bearer auth.
    }

    public function test_api_turn_finishes_through_the_real_worker_handler_and_projects_a_chat_answer(): void
    {
        config(['kb.investigation.enabled' => false, 'reasoning.enabled' => false,
            'agent.waves.enabled' => false, 'agent.waves.combined_initial_planning' => false]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $ai = \Mockery::mock(\App\Ai\AiManager::class);
        $ai->shouldReceive('chatWithHistory')->andReturnUsing(function (string $system) {
            return str_contains($system, 'submit_agent_plan')
                ? new \App\Ai\AiResponse('', 'fake', 'fake', toolCalls: [['name' => 'submit_agent_plan', 'arguments' => ['decision' => 'answer', 'actions' => []]]])
                : new \App\Ai\AiResponse('', 'fake', 'fake', toolCalls: [['name' => 'submit_agent_answer', 'arguments' => [
                    'completeness' => 'insufficient', 'claims' => [], 'limitations' => ['Nessuna fonte.'], 'requires_selection' => false, 'render_table' => false]]]);
        });
        $this->app->instance(\App\Ai\AiManager::class, $ai);
        $retrieval = \Mockery::mock(\App\Services\Kb\Chat\ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->andReturn(new \App\Services\Kb\Retrieval\SearchResult(collect(), collect(), collect()));
        $this->app->instance(\App\Services\Kb\Chat\ChatRetrievalService::class, $retrieval);
        $conversation = $this->api('POST', '/api/chat/conversations', ['project_key' => 'orders'])->assertCreated()->json('id');
        $id = $this->api('POST', "/api/chat/conversations/$conversation/messages", ['content' => 'Trova ordine'])->assertAccepted()->json('run_id');
        $job = Queue::pushed(ExecuteAgentRunJob::class)->sole();
        $this->app->call([$job, 'handle']);
        $this->api('GET', "/api/chat/runs/$id")->assertOk()->assertJsonPath('terminal', true);
        $messages = $this->api('GET', "/api/chat/conversations/$conversation/messages")->assertOk();
        $messages->assertJsonPath('1.role', 'assistant');
        $this->assertNotEmpty($messages->json('1.content'));
        $this->assertNotSame('failed', AgentRun::query()->sole()->status);
    }

    public function test_debug_admin_can_reconcile_only_owned_terminal_work_without_replaying_it(): void
    {
        $conversation = $this->api('POST', '/api/chat/conversations', ['project_key' => 'orders'])->assertCreated()->json('id');
        $id = $this->api('POST', "/api/chat/conversations/$conversation/messages", ['content' => 'Order'])->assertAccepted()->json('run_id');
        $run = AgentRun::query()->sole();
        $run->toolExecutions()->create(['logical_index' => 1, 'tool_name' => 'lookup', 'tool_kind' => 'api',
            'status' => 'running', 'started_at' => now()->subMinute()]);
        $this->api('POST', "/api/chat/runs/$id/reconcile")->assertForbidden();
        $this->user->assignRole('admin');
        $this->api('POST', "/api/chat/runs/$id/reconcile")->assertForbidden(); // Debug ability is independently required.
        $this->token = $this->user->createToken('debug', ['chat:use', 'chat:tenant:acme', 'chat:debug'], now()->addDay())->plainTextToken;
        $this->api('POST', "/api/chat/runs/$id/reconcile")->assertConflict();
        $this->assertSame('running', $run->toolExecutions()->sole()->status);
        $run->forceFill(['status' => 'failed', 'error_code' => 'worker_timeout', 'completed_at' => now()])->save();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        Queue::fake();
        $this->api('POST', "/api/chat/runs/$id/reconcile")->assertOk()->assertJsonPath('closed_tools', 1)->assertJsonPath('status', 'failed');
        $this->api('POST', "/api/chat/runs/$id/reconcile")->assertOk()->assertJsonPath('closed_tools', 0);
        $tool = $run->toolExecutions()->sole();
        $this->assertSame('failed', $tool->status);
        $this->assertTrue($tool->result_meta_json['outcome_unknown']);
        $this->assertSame(1, $run->events()->where('type', 'tool.failed')->count());
        $this->assertSame('failed', $run->fresh()->status);
        $foreign = $run->replicate()->forceFill(['run_id' => (string) \Illuminate\Support\Str::uuid(), 'actor_id' => 'another-principal']);
        $foreign->save();
        $this->api('POST', "/api/chat/runs/$foreign->run_id/reconcile")->assertNotFound();
        Queue::assertNothingPushed();
        \Illuminate\Support\Facades\Http::assertNothingSent();
        $this->api('POST', '/api/chat/runs/'.\Illuminate\Support\Str::uuid().'/reconcile')->assertNotFound();
    }

    public function test_foreign_conversations_and_revoked_project_access_are_denied(): void
    {
        $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'password']);
        $foreign = Conversation::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'user_id' => $other->id]);
        $this->api('GET', "/api/chat/conversations/$foreign->id/messages")->assertNotFound();
        $own = $this->api('POST', '/api/chat/conversations', ['project_key' => 'orders'])->assertCreated()->json('id');
        $run = $this->api('POST', "/api/chat/conversations/$own/messages", ['content' => 'Order'])->assertAccepted()->json('run_id');
        ProjectMembership::query()->forTenant('acme')->where('user_id', $this->user->id)->update(['project_key' => 'remaining']);
        $this->api('GET', "/api/chat/runs/$run")->assertForbidden();
        $this->api('GET', "/api/chat/runs/$run/events")->assertForbidden();
        $this->api('GET', "/api/chat/conversations/$own/messages")->assertForbidden();
    }

    public function test_terminal_snapshot_pages_all_events_and_returns_the_same_rich_messages_as_chat(): void
    {
        $conversation = Conversation::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'user_id' => $this->user->id]);
        $id = $this->api('POST', "/api/chat/conversations/$conversation->id/messages", ['content' => 'Order'])->assertAccepted()->json('run_id');
        $run = AgentRun::query()->sole();
        $run->events()->delete();
        for ($i = 1; $i <= 105; $i++) $run->events()->create(['sequence' => $i, 'type' => 'tool.completed', 'phase' => 'tool', 'locale' => 'it']);
        $run->forceFill(['status' => 'partial', 'completed_at' => now(), 'last_sequence' => 105])->save();
        $metadata = ['citations' => [['document_id' => 123]], 'agent_artifact' => ['rows' => [['key' => 'order-42']]]];
        $conversation->messages()->create(['tenant_id' => 'acme', 'role' => 'assistant', 'content' => 'Verified answer', 'metadata' => $metadata]);
        $this->api('GET', "/api/chat/runs/$id")->assertOk()->assertJsonCount(100, 'events')->assertJsonPath('has_more', true)->assertJsonPath('next_after', 100);
        $this->api('GET', "/api/chat/runs/$id?after=100")->assertOk()->assertJsonCount(5, 'events')->assertJsonPath('has_more', false)->assertJsonPath('terminal', true);
        $this->api('GET', "/api/chat/conversations/$conversation->id/messages")->assertOk()->assertJsonPath('1.metadata', $metadata);
        $this->api('GET', "/api/chat/conversations/$conversation->id/debug-transcript")->assertForbidden();
        $this->user->assignRole('admin');
        $debug = $this->user->createToken('debug', ['chat:use', 'chat:tenant:acme', 'chat:debug'], now()->addDay());
        $this->token = $debug->plainTextToken;
        $this->api('GET', "/api/chat/runs/$id")->assertOk()->assertJsonStructure(['diagnostics' => ['spans', 'open_spans']]);
        $this->api('GET', "/api/chat/conversations/$conversation->id/debug-transcript")->assertOk()
            ->assertJsonPath('kind', 'askmydocs.conversation_debug_transcript')->assertJsonPath('agent_runs.0.run_id', $id);
    }

    public function test_expired_or_unscoped_tokens_are_rejected_and_current_token_can_be_revoked(): void
    {
        $this->api('DELETE', '/api/chat/token')->assertNoContent();
        $this->api('GET', '/api/chat/conversations')->assertUnauthorized();
        $this->token = $this->user->createToken('expired', ['chat:use', 'chat:tenant:acme'], now()->subMinute())->plainTextToken;
        $this->api('GET', '/api/chat/conversations')->assertUnauthorized();
        $this->token = $this->user->createToken('desktop', ['kb:chat'])->plainTextToken;
        $this->api('GET', '/api/chat/conversations')->assertForbidden();
    }

    public function test_token_bootstrap_enforces_membership_expiry_and_admin_debug_scope(): void
    {
        $args = ['email' => $this->user->email, '--tenant' => 'acme', '--hours' => 24];
        $this->assertSame(0, Artisan::call('chat:issue-api-token', $args));
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $record = \Laravel\Sanctum\PersonalAccessToken::findToken($payload['token']);
        $this->assertSame(['chat:use', 'chat:tenant:acme'], $record->abilities);
        $this->assertTrue($record->expires_at->isFuture());
        $this->assertSame(1, Artisan::call('chat:issue-api-token', [...$args, '--debug' => true]));
        $this->assertSame(1, Artisan::call('chat:issue-api-token', [...$args, '--tenant' => 'foreign']));
        $this->assertSame(2, Artisan::call('chat:issue-api-token', [...$args, '--hours' => 0]));
    }

    private function api(string $method, string $path, array $data = [], string $tenant = 'acme'): TestResponse
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'X-Tenant-Id' => $tenant])->json($method, $path, $data);
    }
}
