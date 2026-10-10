<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoop;
use App\Agent\Budget\AgentBudgetTracker;
use App\Agent\Tools\AgentServerToolRunner;
use App\Agent\Tools\AgentToolRegistry;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Ai\Tools\Sources\FreshdeskChatToolSource;
use App\Connectors\ConnectorTenantScopeMiddleware;
use App\Connectors\SerializedConnectorSyncJob;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\KbCanonicalAudit;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Retrieval\SearchResult;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as PackageTenantContext;
use Padosoft\AskMyDocsConnectorFreshdesk\FreshdeskConnector;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\ProcessSyncBatch;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\StartSync;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\SyncManager;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\SyncRun;
use Tests\TestCase;

final class FreshdeskIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(FreshdeskConnector::class)) {
            $this->markTestSkipped('Optional Freshdesk package is not installed.');
        }
        $this->seed(RbacSeeder::class);
        config()->set('connectors.built_in', array_merge(config('connectors.built_in', []), [FreshdeskConnector::class]));
        app()->forgetInstance(ConnectorRegistry::class);
        app(TenantContext::class)->set('test-tenant');
        app(PackageTenantContext::class)->set('test-tenant');
        config()->set('connector-freshdesk.http.resolve_dns', false);
        config()->set('queue.default', 'database');
        config()->set('kb.project_isolation.enabled', true);
        Project::create(['project_key' => 'support', 'name' => 'Support']);
        Queue::fake();
        Http::preventStrayRequests();
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    private function user(string $role = 'admin'): User
    {
        $user = User::create(['name' => 'Freshdesk test', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test-password')]);
        $user->assignRole($role);
        ProjectMembership::create(['tenant_id' => 'test-tenant', 'user_id' => $user->id, 'project_key' => 'support', 'role' => 'member']);

        return $user;
    }

    private function installation(string $tenant = 'test-tenant', string $project = 'support'): ConnectorInstallation
    {
        $installation = ConnectorInstallation::create(['tenant_id' => $tenant, 'connector_name' => 'freshdesk', 'label' => Str::uuid(), 'project_key' => $project, 'status' => 'active', 'config_json' => ['connection' => ['domain' => 'example.freshdesk.com'], 'date_window_days' => 45]]);
        if ($tenant === 'test-tenant') {
            app(OAuthCredentialVault::class)->setCredentials($installation->id, accessToken: 'freshdesk-test-key');
        }

        return $installation;
    }

    public function test_probe_and_configuration_vault_the_key_and_reject_failed_reconfiguration(): void
    {
        $user = $this->user();
        Http::fake(fn () => Http::response(['id' => 1]));
        $payload = ['domain' => 'example.freshdesk.com', 'api_key' => 'freshdesk-test-key', 'project_key' => 'support', 'label' => 'Help desk'];
        $this->actingAs($user)->postJson('/api/admin/connectors/freshdesk/test-connection', $payload)->assertOk()->assertJson(['ok' => true]);
        $response = $this->postJson('/api/admin/connectors/freshdesk/configure', $payload)->assertOk();
        $id = $response->json('data.id');
        $installation = ConnectorInstallation::findOrFail($id);
        $this->assertStringNotContainsString('freshdesk-test-key', $response->getContent());
        $this->assertStringNotContainsString('freshdesk-test-key', json_encode($installation->config_json));
        $this->assertSame('freshdesk-test-key', app(OAuthCredentialVault::class)->getAccessToken($id));
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['secret' => 'bad-key'], 401)]);
        $this->postJson('/api/admin/connectors/'.$id.'/reconfigure', ['domain' => 'example.freshdesk.com', 'api_key' => 'bad-key'])->assertStatus(422);
        $this->assertSame('freshdesk-test-key', app(OAuthCredentialVault::class)->getAccessToken($id));
    }

    public function test_history_action_is_scoped_deduplicated_and_preserves_settings(): void
    {
        $installation = $this->installation();
        $uri = '/api/admin/connectors/'.$installation->id.'/actions/historical-import';
        $this->actingAs($this->user())->getJson($uri)->assertOk()->assertJson(['data' => null]);
        $first = $this->postJson($uri)->assertStatus(202)->assertJsonPath('data.mode', 'history');
        $this->postJson($uri)->assertStatus(202)->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, SyncRun::count());
        $this->assertSame(45, $installation->fresh()->config_json['date_window_days']);
        $this->getJson($uri)->assertJsonPath('data.status', 'queued');
        $foreign = $this->installation('another-tenant');
        $this->postJson('/api/admin/connectors/'.$foreign->id.'/actions/historical-import')->assertNotFound();
        $this->postJson('/api/admin/connectors/'.$installation->id.'/actions/unknown')->assertNotFound();
        $installation->update(['status' => 'paused']);
        $this->postJson($uri)->assertStatus(422);
    }

    public function test_history_action_requires_manage_connectors(): void
    {
        $uri = '/api/admin/connectors/'.$this->installation()->id.'/actions/historical-import';
        $this->getJson($uri)->assertUnauthorized();
        $this->postJson($uri)->assertUnauthorized();
        $this->actingAs($this->user('viewer'))->getJson($uri)->assertForbidden();
        $this->postJson($uri)->assertForbidden();
        $this->assertSame(0, SyncRun::count());
    }

    public function test_normal_chat_and_agent_catalog_recheck_project_tenant_and_installation(): void
    {
        $installation = $this->installation();
        $this->installation(project: 'other-project');
        $this->installation('another-tenant');
        $user = $this->user('viewer');
        $run = $this->createRun($user);
        $context = AgentExecutionContext::fromArray($run->toArray());
        $tools = app(AgentToolRegistry::class)->forContext($context, $user);
        $name = 'freshdesk_'.$installation->id.'_get_ticket';
        $this->assertCount(11, $tools);
        $this->assertSame('api', $tools[$name]->kind);
        $this->assertSame($name, $tools[$name]->executorReference);
        $this->assertCount(9, app(FreshdeskChatToolSource::class)->catalog($user, 'support'));
        $this->assertSame([], app(FreshdeskChatToolSource::class)->catalog($user, 'other-project'));
        $this->assertArrayNotHasKey($name, app(AgentToolRegistry::class)->forContext(AgentExecutionContext::fromArray(array_replace($run->toArray(), ['channel' => 'widget', 'actor_type' => 'anonymous_widget', 'actor_id' => null])), $user));
        app(PackageTenantContext::class)->set('another-tenant');
        $this->assertSame([], app(FreshdeskChatToolSource::class)->catalog($user, 'support'));
        $this->assertArrayNotHasKey($name, app(AgentToolRegistry::class)->forContext($context, $user));
        app(PackageTenantContext::class)->set('test-tenant');
        $installation->update(['status' => 'paused']);
        $this->expectException(\RuntimeException::class);
        app(FreshdeskChatToolSource::class)->invoke(['name' => $name], ['id' => 1], $user, ['project_key' => 'support']);
    }

    public function test_agent_counts_retries_and_enforces_physical_budget(): void
    {
        $installation = $this->installation();
        $user = $this->user('viewer');
        $run = $this->createRun($user);
        $context = AgentExecutionContext::fromArray($run->toArray());
        $tool = app(AgentToolRegistry::class)->forContext($context, $user)['freshdesk_'.$installation->id.'_get_ticket'];
        Http::fake(['*/tickets/1' => Http::sequence()->push([], 503)->push(['id' => 1, 'description_text' => 'Answer'])]);
        $budget = new AgentBudgetTracker($run);
        $result = app(AgentServerToolRunner::class)->execute($tool, ['id' => 1], $context, $run, $budget);
        $this->assertTrue($result->successful());
        $this->assertSame(2, $result->physicalRequests);
        $this->assertSame(2, $run->fresh()->counters_json['physical_calls']);
        $this->assertSame($installation->id, $result->stats['freshdesk']['installation_id']);
        config()->set('agent.limits.physical_hard', 3);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 503)]);
        $result = app(AgentServerToolRunner::class)->execute($tool, ['id' => 2], $context, $run, $budget);
        $this->assertFalse($result->successful());
        $this->assertSame(1, $result->physicalRequests);
        $this->assertSame('physical_hard_limit', $result->stopReason);
        $this->assertSame(3, $run->fresh()->counters_json['physical_calls']);
    }

    public function test_scheduler_dispatch_uses_async_entry_without_advancing_watermark(): void
    {
        $installation = $this->installation();
        SerializedConnectorSyncJob::dispatchFor($installation);
        Queue::assertPushed(StartSync::class, fn ($job) => $job->installationId === $installation->id);
        (new StartSync($installation->id, 'test-tenant'))->handle(app(SyncManager::class), app(PackageTenantContext::class));
        $this->assertNull($installation->fresh()->last_sync_at);
        $this->assertSame('queued', SyncRun::firstOrFail()->status);
    }

    public function test_agent_loop_records_freshdesk_provenance_without_an_api_route_foreign_key(): void
    {
        config()->set('kb.investigation.enabled', false);
        $installation = $this->installation();
        $name = 'freshdesk_'.$installation->id.'_get_ticket';
        $run = $this->createRun($this->user('viewer'));
        $run->update(['input_json' => ['question' => 'Leggi il ticket 1']]);
        Http::fake(fn () => Http::response(['id' => 1, 'subject' => 'Support', 'description_text' => 'Verified answer']));
        $plans = [
            ['decision' => 'tools', 'actions' => [['id' => 'ticket', 'tool' => $name, 'arguments' => ['id' => 1], 'depends_on' => [], 'purpose' => 'Leggo il ticket richiesto']]],
            ['decision' => 'answer', 'actions' => []],
        ];
        $ai = \Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn(...array_map(static fn ($plan) => new AiResponse(content: '', provider: 'fake', model: 'fake-agent', toolCalls: [['name' => 'submit_agent_plan', 'arguments' => $plan]]), $plans));
        $this->app->instance(AiManager::class, $ai);
        $retrieval = \Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect(), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        $outcome = app(AgentLoop::class)->run($run, AgentExecutionContext::fromArray($run->toArray()));
        $this->assertSame('answer', $outcome->decision);
        $execution = $run->toolExecutions()->firstOrFail();
        $this->assertNull($execution->api_route_id);
        $this->assertSame('completed', $execution->status);
        $this->assertSame(1, $run->fresh()->counters_json['physical_calls']);
        $this->assertCount(1, $outcome->evidence->apiTools());
        $this->assertSame('api', data_get($run->events()->where('type', 'tool.started')->firstOrFail()->payload_json, 'data.tool_kind'));
    }

    public function test_queued_batches_rebind_and_restore_host_tenant_for_audit(): void
    {
        $installation = $this->installation();
        $manager = app(SyncManager::class);
        $run = $manager->start($installation);
        config()->set('connector-freshdesk.sync.batch_items', 100);
        Http::fake(fn () => Http::response([]));
        app(TenantContext::class)->set('another-tenant');
        app(PackageTenantContext::class)->set('another-tenant');
        $job = new ProcessSyncBatch($run->id, 'test-tenant');
        app(ConnectorTenantScopeMiddleware::class)->handle($job, fn ($job) => $job->handle($manager, app(PackageTenantContext::class)));
        $this->assertSame('another-tenant', app(TenantContext::class)->current());
        $this->assertSame('another-tenant', app(PackageTenantContext::class)->current());
        $this->assertSame('test-tenant', KbCanonicalAudit::withoutGlobalScopes()->where('event_type', 'connector_sync_completed')->firstOrFail()->tenant_id);
    }

    private function createRun(User $user): AgentRun
    {
        $conversation = Conversation::create(['tenant_id' => 'test-tenant', 'user_id' => $user->id, 'title' => 'Freshdesk', 'project_key' => 'support']);

        return AgentRun::create(['run_id' => Str::uuid()->toString(), 'tenant_id' => 'test-tenant', 'project_key' => 'support', 'user_id' => $user->id, 'conversation_id' => $conversation->id, 'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => (string) $user->id, 'locale' => 'it-IT', 'timezone' => 'Europe/Rome', 'status' => 'running', 'input_json' => [], 'counters_json' => [], 'started_at' => now()]);
    }
}
