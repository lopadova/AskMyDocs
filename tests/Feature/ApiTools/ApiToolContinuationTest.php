<?php

declare(strict_types=1);

namespace Tests\Feature\ApiTools;

use App\Agent\AgentExecutionContext;
use App\Ai\AiManager;
use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Ai\Tools\ChatToolInvocationResult;
use App\Ai\Tools\ChatToolSourceContract;
use App\Ai\Tools\ChatToolSourceRegistry;
use App\ApiTools\Flow\ToolTenantScope;
use App\ApiTools\Jobs\ResumeApiTool;
use App\ApiTools\Models\ApiTool;
use App\ApiTools\Models\ApiToolRevision;
use App\ApiTools\Models\ApiToolRun;
use App\ApiTools\ToolContinuation;
use App\Jobs\ExecuteAgentRunJob;
use App\Mcp\Client\McpToolCallingService;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Padosoft\LaravelFlow\Executor\Jobs\CoordinatorJob;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Tests\TestCase;

final class ApiToolContinuationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('acme');
        app(ConnectorTenantContext::class)->set('acme');
        Queue::fake();
    }

    public static function pendingTimings(): array
    {
        return ['graph still running' => [false], 'graph completes before parent unlocks' => [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pendingTimings')]
    public function test_real_agent_loop_hands_off_pending_action_without_publishing_fake_completion(bool $finishDuringHandoff): void
    {
        config(['kb.investigation.enabled' => false, 'api-tools.enabled' => true]);
        $user = User::create(['name' => 'Agent', 'email' => 'agent-api-pending@example.com', 'password' => 'test-password', 'locale' => 'en']);
        \App\Models\ProjectMembership::create(['tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'sales', 'role' => 'member']);
        $context = new AgentExecutionContext((string) Str::uuid(), 'acme', 'sales', 'chat', 'user', (string) $user->id, 'en', 'UTC');
        $context->activate();
        $agent = AgentRun::create(array_merge($context->jsonSerialize(), ['user_id' => $user->id, 'status' => 'running',
            'input_json' => ['question' => 'Calculate the sales total'], 'started_at' => now(), 'counters_json' => []]));
        $connector = \Padosoft\AskMyDocsConnectorApi\Models\ApiConnector::create([
            'tenant_id' => 'acme', 'project_key' => 'sales', 'name' => 'Sales', 'is_active' => true, 'base_url' => 'https://sales.example.test',
        ]);
        $route = \Padosoft\AskMyDocsConnectorApi\Models\ApiRoute::create([
            'tenant_id' => 'acme', 'api_connector_id' => $connector->id, 'project_key' => 'sales', 'name' => 'total', 'slug' => 'total',
            'http_method' => 'GET', 'url' => '/total', 'mode' => 'tool', 'status' => 'active', 'endpoint_type' => 'detail',
            'input_schema' => ['type' => 'object'], 'output_schema' => ['type' => 'object', 'properties' => ['total' => ['type' => 'integer']]],
        ]);
        $preset = app(\App\ApiTools\ToolLifecycle::class)->saveDraft(null, ['name' => 'Sales total', 'definition' => [
            'version' => 1, 'input_schema' => ['type' => 'object'],
            'steps' => [['id' => 'total', 'operation' => 'api', 'route_id' => $route->id, 'bindings' => []]],
            'output' => ['total' => ['source' => 'step', 'step' => 'total', 'path' => 'total']],
        ]], $context);
        $preset->update(['status' => 'active', 'publication_intent' => true]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse(json_encode([
            'decision' => 'tools', 'actions' => [['id' => 'calculate', 'tool' => 'api_tool_'.$preset->id, 'arguments' => [], 'depends_on' => [], 'purpose' => 'Calculate total']],
        ], JSON_THROW_ON_ERROR), 'openai', 'test-model'));
        $this->app->instance(AiManager::class, $ai);
        $retrieval = Mockery::mock(\App\Services\Kb\Chat\ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new \App\Services\Kb\Retrieval\SearchResult(collect(), collect(), collect()));
        $this->app->instance(\App\Services\Kb\Chat\ChatRetrievalService::class, $retrieval);
        if ($finishDuringHandoff) {
            \App\Models\AgentRunEvent::created(function ($event): void {
                if ($event->type !== 'run.tool_pending') { return; }
                $toolRun = ApiToolRun::query()->sole();
                $toolRun->update(['status' => 'completed', 'outputs' => ['total' => 42], 'physical_requests' => 1]);
                (new ResumeApiTool($toolRun->id, $toolRun->tenant_id))->handle(app(ToolContinuation::class));
            });
            app(\App\Agent\DefaultAgentRunHandler::class)->handle($agent);
            $this->assertSame('queued', $agent->fresh()->status);
            $this->assertSame('completed', $agent->toolExecutions()->sole()->status);
            $this->assertSame(['total' => 42], $agent->fresh()->result_json['action_results']['calculate']);
            // One dispatch from resume plus recovery after the original parent unlocks.
            Queue::assertPushed(ExecuteAgentRunJob::class, 2);
            return;
        }
        $outcome = app(\App\Agent\AgentLoop::class)->run($agent, $context);
        $this->assertSame('waiting_api_tool', $outcome->decision);
        $this->assertTrue($outcome->requiresInteraction());
        $this->assertSame('waiting_api_tool', $agent->fresh()->status);
        $this->assertSame([], $outcome->completedActions);
        $this->assertSame([], $outcome->evidence->apiTools());
        $this->assertSame('running', $agent->toolExecutions()->sole()->status);
        $this->assertNull($agent->toolExecutions()->sole()->api_route_id);
        $this->assertSame(0, $agent->events()->where('type', 'tool.completed')->count());
        $this->assertSame('agent', ApiToolRun::query()->sole()->continuation['type']);
        Queue::assertPushed(CoordinatorJob::class, 1);
    }

    public function test_terminal_tool_resumes_the_same_agent_execution_once_and_restores_tenant(): void
    {
        $context = new AgentExecutionContext((string) Str::uuid(), 'acme', 'sales', 'widget', 'anonymous_widget', null, 'en', 'UTC');
        $agent = AgentRun::create(array_merge($context->jsonSerialize(), ['status' => 'running', 'input_json' => ['question' => 'Total?'],
            'result_json' => ['evidence' => [], 'completed_actions' => [], 'action_results' => []]]));
        $execution = $agent->toolExecutions()->create(['logical_index' => 1, 'tool_name' => 'sales_total', 'tool_kind' => 'api', 'status' => 'running', 'started_at' => now()]);
        $tool = $this->fixtureRun($context);
        app(ToolContinuation::class)->attachAgent($tool->id, $agent, ['action_id' => 'sum', 'purpose' => 'Calculate total',
            'execution_id' => $execution->id, 'arguments' => ['year' => 2026], 'tool' => ['name' => 'sales_total', 'display_name' => 'Sales total', 'kind' => 'api']]);
        $this->assertSame('waiting_api_tool', $agent->fresh()->status);
        $this->assertSame('running', $execution->fresh()->status);
        $this->assertSame([], $agent->fresh()->result_json['completed_actions']);
        $this->assertStringNotContainsString('Calculate total', (string) DB::table('api_tool_runs')->where('id', $tool->id)->value('continuation'));
        $tool->update(['status' => 'completed', 'outputs' => ['total' => 42], 'physical_requests' => 2, 'completed_at' => now()]);
        app(TenantContext::class)->set('other');
        app(ConnectorTenantContext::class)->set('other');
        $job = new ResumeApiTool($tool->id, 'acme');
        $job->handle(app(ToolContinuation::class));
        $job->handle(app(ToolContinuation::class));
        $this->assertSame('other', app(TenantContext::class)->current());
        $this->assertSame('other', app(ConnectorTenantContext::class)->current());
        $this->assertSame('queued', $agent->fresh()->status);
        $this->assertSame('completed', $execution->fresh()->status);
        $this->assertSame(2, $agent->fresh()->counters_json['physical_calls']);
        $this->assertCount(1, $agent->fresh()->result_json['completed_actions']);
        $this->assertSame(['total' => 42], $agent->fresh()->result_json['action_results']['sum']);
        Queue::assertPushed(ExecuteAgentRunJob::class, 1);
        $this->assertNotNull($tool->fresh()->resumed_at);
    }

    public function test_real_wave_loop_starts_both_composed_tools_and_joins_their_durable_continuations(): void
    {
        config(['agent.waves.enabled' => true, 'api-tools.enabled' => true, 'reasoning.enabled' => true]);
        [$user, $conversation, $context] = $this->chatOwner();
        $context->activate();
        $question = 'Calculate both sales totals';
        $understanding = new \App\Services\Chat\QuestionUnderstanding('en', $question, [], [], false, requiresResearch: true);
        $agent = AgentRun::create(array_merge($context->jsonSerialize(), ['user_id' => $user->id, 'conversation_id' => $conversation->id,
            'status' => 'running', 'input_json' => ['question' => $question], 'started_at' => now(),
            'result_json' => ['question_understanding' => $understanding->toArray()]]));
        $actions = [];
        foreach (['first' => 42, 'second' => 24] as $id => $total) {
            $preset = app(\App\ApiTools\ToolLifecycle::class)->saveDraft(null, ['name' => 'Sales '.$id, 'definition' => [
                'version' => 1, 'input_schema' => ['type' => 'object'],
                'steps' => [['id' => 'total', 'operation' => 'compose', 'bindings' => ['value' => ['source' => 'constant', 'value' => $total]]]],
                'output' => ['total' => ['source' => 'step', 'step' => 'total', 'path' => 'value']],
            ]], $context);
            $preset->update(['status' => 'active', 'publication_intent' => true]);
            $actions[] = ['id' => $id, 'tool' => 'api_tool_'.$preset->id, 'arguments' => [], 'depends_on' => [], 'purpose' => 'Calculate '.$id.' total'];
        }
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse(json_encode([
            'decision' => 'tools', 'actions' => $actions,
        ], JSON_THROW_ON_ERROR), 'openai', 'test-model'));
        $this->app->instance(AiManager::class, $ai);
        $retrieval = Mockery::mock(\App\Services\Kb\Chat\ChatRetrievalService::class);
        $retrieval->shouldNotReceive('retrieve');
        $this->app->instance(\App\Services\Kb\Chat\ChatRetrievalService::class, $retrieval);

        // Use the actual executor and task closures; test mode executes each worker
        // inline while retaining the same registration and continuation contracts.
        $outcome = app(\App\Agent\AgentLoop::class)->run($agent, $context);
        $fresh = $agent->fresh();
        $this->assertSame('waiting_api_tool', $outcome->decision, (string) $outcome->stopReason);
        $this->assertSame('waiting_api_tool', $fresh->status);
        $this->assertCount(2, $fresh->result_json['pending_api_tools']);
        $this->assertFalse($fresh->result_json['wave_dispatching']);
        $this->assertSame([], $fresh->result_json['completed_actions']);
        $this->assertSame(2, $fresh->counters_json['logical_calls']);
        $this->assertSame(0, $fresh->counters_json['physical_calls']);
        $this->assertSame(['running', 'running'], $agent->toolExecutions()->orderBy('logical_index')->pluck('status')->all());
        $runs = ApiToolRun::query()->get()->keyBy(fn ($run) => $run->continuation['action_id']);
        $this->assertCount(2, $runs);
        foreach ($runs as $toolRun) {
            $this->assertNotNull($toolRun->flow_run_id);
            $this->assertFalse($toolRun->continuation['defer_dispatch']);
            $this->assertSame($agent->id, $toolRun->continuation['agent_run_id']);
        }
        $waveId = $runs['first']->continuation['wave_id'];
        $this->assertSame('waiting', $fresh->result_json['waves'][$waveId]['status']);
        Queue::assertPushed(CoordinatorJob::class, 2);
        Queue::assertNotPushed(ExecuteAgentRunJob::class);

        $runs['first']->update(['status' => 'completed', 'outputs' => ['total' => 42], 'physical_requests' => 1]);
        (new ResumeApiTool($runs['first']->id, 'acme'))->handle(app(ToolContinuation::class));
        $this->assertSame('waiting_api_tool', $agent->fresh()->status);
        $this->assertCount(1, $agent->fresh()->result_json['pending_api_tools']);
        Queue::assertNotPushed(ExecuteAgentRunJob::class);

        $runs['second']->update(['status' => 'failed', 'error_code' => 'upstream_failed', 'physical_requests' => 1]);
        $job = new ResumeApiTool($runs['second']->id, 'acme');
        $job->handle(app(ToolContinuation::class));
        $job->handle(app(ToolContinuation::class));
        $fresh = $agent->fresh();
        $this->assertSame('queued', $fresh->status);
        $this->assertSame([], $fresh->result_json['pending_api_tools']);
        $this->assertCount(2, $fresh->result_json['completed_actions']);
        $this->assertSame(['total' => 42], $fresh->result_json['action_results']['first']);
        $this->assertSame(['error' => 'upstream_failed'], $fresh->result_json['action_results']['second']);
        $this->assertSame(2, $fresh->counters_json['logical_calls']);
        $this->assertSame(2, $fresh->counters_json['physical_calls']);
        $this->assertSame('completed', $fresh->result_json['waves'][$waveId]['status']);
        $this->assertSame(1, $agent->events()->where('type', 'wave.completed')->count());
        $execution = $agent->toolExecutions()->orderBy('logical_index')->first();
        $this->assertSame('first', $execution->result_meta_json['action_id']);
        $this->assertSame($waveId, $execution->result_meta_json['wave_id']);
        $this->assertGreaterThan(0, $execution->result_meta_json['physical_quota']);
        $this->assertSame($execution->result_meta_json['arguments_hash'], $fresh->result_json['completed_actions'][0]['arguments_hash']);
        Queue::assertPushed(ExecuteAgentRunJob::class, 1);
    }

    public function test_a_terminal_run_swept_before_attachment_still_resumes_and_keeps_lifecycle_guard(): void
    {
        $context = new AgentExecutionContext((string) Str::uuid(), 'acme', 'sales', 'widget', 'anonymous_widget', null, 'en', 'UTC');
        $agent = AgentRun::create(array_merge($context->jsonSerialize(), ['status' => 'running', 'result_json' => []]));
        $execution = $agent->toolExecutions()->create(['logical_index' => 1, 'tool_name' => 'total', 'tool_kind' => 'api', 'status' => 'running']);
        $tool = $this->fixtureRun($context);
        $tool->update(['status' => 'completed', 'outputs' => ['total' => 42], 'resumed_at' => now(), 'continuation' => ['_lifecycle_recorded' => true]]);
        app(ToolContinuation::class)->attachAgent($tool->id, $agent, ['action_id' => 'sum', 'purpose' => 'Total', 'execution_id' => $execution->id,
            'arguments' => [], 'tool' => ['name' => 'total', 'display_name' => 'Total', 'kind' => 'api']]);
        $this->assertNull($tool->fresh()->resumed_at);
        $this->assertTrue($tool->fresh()->continuation['_lifecycle_recorded']);
        (new ResumeApiTool($tool->id, 'acme'))->handle(app(ToolContinuation::class));
        $this->assertSame('queued', $agent->fresh()->status);
        $this->assertSame(['total' => 42], $agent->fresh()->result_json['action_results']['sum']);
    }

    public function test_cancelled_parent_cannot_be_revived_or_receive_tool_evidence(): void
    {
        $context = new AgentExecutionContext((string) Str::uuid(), 'acme', 'sales', 'widget', 'anonymous_widget', null, 'en', 'UTC');
        $agent = AgentRun::create(array_merge($context->jsonSerialize(), ['status' => 'cancelled', 'result_json' => []]));
        $tool = $this->fixtureRun($context);
        $tool->update(['status' => 'completed', 'outputs' => ['total' => 42], 'continuation' => ['type' => 'agent', 'agent_run_id' => $agent->id]]);
        (new ResumeApiTool($tool->id, 'acme'))->handle(app(ToolContinuation::class));
        $this->assertSame('cancelled', $agent->fresh()->status);
        $this->assertSame([], $agent->fresh()->result_json);
        Queue::assertNotPushed(ExecuteAgentRunJob::class);
        $this->assertNotNull($tool->fresh()->resumed_at);
    }

    public function test_wave_waits_for_all_siblings_even_when_first_completes_during_registration(): void
    {
        $context = new AgentExecutionContext((string) Str::uuid(), 'acme', 'sales', 'widget', 'anonymous_widget', null, 'en', 'UTC');
        $agent = AgentRun::create(array_merge($context->jsonSerialize(), ['status' => 'running',
            'result_json' => ['wave_dispatching' => true, 'completed_actions' => [], 'action_results' => []]]));
        $continuations = app(ToolContinuation::class);
        $first = $this->attachWaveTool($agent, $context, 'first');
        $first->update(['status' => 'completed', 'outputs' => ['total' => 42], 'physical_requests' => 2]);
        $job = new ResumeApiTool($first->id, 'acme');
        $job->handle($continuations);
        $job->handle($continuations);
        $this->assertSame('waiting_api_tool', $agent->fresh()->status);
        $this->assertNotNull($first->fresh()->resumed_at);
        Queue::assertNotPushed(ExecuteAgentRunJob::class);

        $second = $this->attachWaveTool($agent, $context, 'second');
        $continuations->finishAgentWave($agent);
        $this->assertSame([$second->id => true], $agent->fresh()->result_json['pending_api_tools']);
        $this->assertFalse($agent->fresh()->result_json['wave_dispatching']);
        Queue::assertNotPushed(ExecuteAgentRunJob::class);

        $second->update(['status' => 'failed', 'error_code' => 'upstream_failed', 'physical_requests' => 1]);
        $job = new ResumeApiTool($second->id, 'acme');
        $job->handle($continuations);
        $job->handle($continuations);
        $continuations->finishAgentWave($agent);
        $fresh = $agent->fresh();
        $this->assertSame('queued', $fresh->status);
        $this->assertSame([], $fresh->result_json['pending_api_tools']);
        $this->assertSame(['total' => 42], $fresh->result_json['action_results']['first']);
        $this->assertSame(['error' => 'upstream_failed'], $fresh->result_json['action_results']['second']);
        $this->assertCount(2, $fresh->result_json['completed_actions']);
        $this->assertSame(3, $fresh->counters_json['physical_calls']);
        $this->assertSame(['completed', 'failed'], $agent->toolExecutions()->orderBy('logical_index')->pluck('status')->all());
        Queue::assertPushed(ExecuteAgentRunJob::class, 1);
    }

    public function test_wave_finishing_after_all_early_completions_queues_once(): void
    {
        $context = new AgentExecutionContext((string) Str::uuid(), 'acme', 'sales', 'widget', 'anonymous_widget', null, 'en', 'UTC');
        $agent = AgentRun::create(array_merge($context->jsonSerialize(), ['status' => 'running', 'result_json' => [
            'wave_dispatching' => true, 'waves' => ['wave-1' => ['id' => 'wave-1', 'status' => 'waiting']],
        ]]));
        $continuations = app(ToolContinuation::class);
        foreach (['first', 'second'] as $action) {
            $tool = $this->attachWaveTool($agent, $context, $action);
            $tool->update(['status' => 'completed', 'outputs' => ['total' => 42], 'physical_requests' => 1]);
            (new ResumeApiTool($tool->id, 'acme'))->handle($continuations);
        }
        Queue::assertNotPushed(ExecuteAgentRunJob::class);
        $this->assertSame('waiting_api_tool', $agent->fresh()->status);
        $continuations->finishAgentWave($agent);
        $continuations->finishAgentWave($agent);
        $this->assertSame('queued', $agent->fresh()->status);
        $this->assertCount(2, $agent->fresh()->result_json['completed_actions']);
        $this->assertSame('completed', $agent->fresh()->result_json['waves']['wave-1']['status']);
        $this->assertSame(1, $agent->events()->where('type', 'wave.completed')->count());
        Queue::assertPushed(ExecuteAgentRunJob::class, 1);
    }

    public function test_cancelling_wave_cancels_every_pending_tool(): void
    {
        $context = new AgentExecutionContext((string) Str::uuid(), 'acme', 'sales', 'widget', 'anonymous_widget', null, 'en', 'UTC');
        $agent = AgentRun::create(array_merge($context->jsonSerialize(), ['status' => 'running', 'result_json' => ['wave_dispatching' => true]]));
        $first = $this->attachWaveTool($agent, $context, 'first');
        $second = $this->attachWaveTool($agent, $context, 'second');
        app(\App\Agent\AgentRunControl::class)->cancel($agent);
        $this->assertSame('cancelled', $agent->fresh()->status);
        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('cancelled', $second->fresh()->status);
        app(ToolContinuation::class)->finishAgentWave($agent);
        Queue::assertNotPushed(ExecuteAgentRunJob::class);
    }

    public function test_last_wave_completion_retries_failed_dispatch_without_merging_twice(): void
    {
        $context = new AgentExecutionContext((string) Str::uuid(), 'acme', 'sales', 'widget', 'anonymous_widget', null, 'en', 'UTC');
        $agent = AgentRun::create(array_merge($context->jsonSerialize(), ['status' => 'running', 'result_json' => ['wave_dispatching' => true]]));
        $tool = $this->attachWaveTool($agent, $context, 'total');
        $continuations = app(ToolContinuation::class);
        $continuations->finishAgentWave($agent);
        $tool->update(['status' => 'completed', 'outputs' => ['total' => 42], 'physical_requests' => 2]);
        $dispatcher = Mockery::mock(new \App\Agent\AgentRunDispatcher);
        $dispatcher->shouldReceive('enqueue')->once()->ordered()->andThrow(new \RuntimeException('queue unavailable'));
        $dispatcher->shouldReceive('enqueue')->once()->ordered()->andReturnNull();
        $this->app->instance(\App\Agent\AgentRunDispatcher::class, $dispatcher);
        $job = new ResumeApiTool($tool->id, 'acme');
        try {
            $job->handle($continuations);
            $this->fail('Expected dispatch failure.');
        } catch (\RuntimeException $exception) { $this->assertSame('queue unavailable', $exception->getMessage()); }
        $this->assertNull($tool->fresh()->resumed_at);
        $this->assertSame('queued', $agent->fresh()->status);
        $job->handle($continuations);
        $job->handle($continuations);
        $this->assertNotNull($tool->fresh()->resumed_at);
        $this->assertSame(2, $agent->fresh()->counters_json['physical_calls']);
        $this->assertCount(1, $agent->fresh()->result_json['completed_actions']);
        $this->assertCount(1, $agent->fresh()->result_json['evidence']['api_tools']);
    }

    public function test_deferred_wave_registration_cannot_resume_until_parent_activates_it(): void
    {
        $context = new AgentExecutionContext((string) Str::uuid(), 'acme', 'sales', 'widget', 'anonymous_widget', null, 'en', 'UTC');
        $agent = AgentRun::create(array_merge($context->jsonSerialize(), ['status' => 'running', 'result_json' => ['wave_dispatching' => true]]));
        $tool = $this->attachWaveTool($agent, $context, 'total', defer: true);
        $tool->update(['status' => 'completed', 'outputs' => ['total' => 42], 'physical_requests' => 2]);
        $continuations = app(ToolContinuation::class);
        ToolContinuation::dispatchIfReady($tool->fresh());
        (new ResumeApiTool($tool->id, 'acme'))->handle($continuations);
        Queue::assertNotPushed(ResumeApiTool::class);
        Queue::assertNotPushed(ExecuteAgentRunJob::class);
        $this->assertNull($tool->fresh()->resumed_at);
        $this->assertArrayNotHasKey('completed_actions', $agent->fresh()->result_json);

        DB::transaction(function () use ($tool, $agent, $continuations): void {
            $handoff = $tool->fresh()->continuation;
            $continuations->attachAgent($tool->id, $agent, [...$handoff, 'defer_dispatch' => false]);
            $continuations->finishAgentWave($agent);
        });
        Queue::assertPushed(ResumeApiTool::class, 1);
        (new ResumeApiTool($tool->id, 'acme'))->handle($continuations);
        $this->assertSame('queued', $agent->fresh()->status);
        $this->assertSame(['total' => 42], $agent->fresh()->result_json['action_results']['total']);
        Queue::assertPushed(ExecuteAgentRunJob::class, 1);
    }

    public function test_runtime_registers_wave_owner_before_graph_dispatch_and_cancellation_stops_launch(): void
    {
        config(['api-tools.enabled' => true]);
        [$user, $conversation, $context] = $this->chatOwner();
        $context->activate();
        $agent = AgentRun::create(array_merge($context->jsonSerialize(), ['user_id' => $user->id, 'status' => 'running',
            'result_json' => ['wave_dispatching' => true]]));
        $execution = $agent->toolExecutions()->create(['logical_index' => 1, 'tool_name' => 'total', 'tool_kind' => 'api', 'status' => 'running']);
        $preset = app(\App\ApiTools\ToolLifecycle::class)->saveDraft(null, ['name' => 'Deferred sum', 'definition' => [
            'version' => 1, 'input_schema' => ['type' => 'object'],
            'steps' => [['id' => 'sum', 'operation' => 'compose', 'bindings' => ['total' => ['source' => 'constant', 'value' => 42]]]],
            'output' => ['total' => ['source' => 'step', 'step' => 'sum', 'path' => 'total']],
        ]], $context);
        $preset->update(['status' => 'active', 'publication_intent' => true]);
        \App\Models\AgentRunEvent::created(function ($event) use ($agent): void {
            if ($event->type !== 'run.tool_pending') { return; }
            $pending = ApiToolRun::query()->sole();
            $this->assertTrue($agent->fresh()->result_json['pending_api_tools'][$pending->id]);
            $this->assertTrue($pending->continuation['defer_dispatch']);
            $this->assertNull($pending->flow_run_id);
            app(\App\Agent\AgentRunControl::class)->cancel($agent);
        });
        $tool = app(\App\ApiTools\ToolRuntime::class)->start($preset, [], $context, continuation: ['agent_wave_handoff' => [
            'agent_run_id' => $agent->id, 'wave_id' => 'wave-1', 'action_id' => 'total', 'execution_id' => $execution->id,
            'purpose' => 'Calculate total', 'arguments' => [], 'tool' => ['name' => 'total', 'display_name' => 'Total', 'kind' => 'api'],
        ]]);
        $this->assertSame('cancelled', $agent->fresh()->status);
        $this->assertSame('cancelled', $tool->fresh()->status);
        $this->assertNull($tool->flow_run_id);
        Queue::assertNotPushed(CoordinatorJob::class);
    }

    public function test_runtime_rolls_back_a_wave_start_if_parent_was_already_cancelled(): void
    {
        config(['api-tools.enabled' => true]);
        [$user, $conversation, $context] = $this->chatOwner();
        $context->activate();
        $preset = $this->replayPreset($context);
        $agent = AgentRun::create(array_merge($context->jsonSerialize(), ['user_id' => $user->id, 'status' => 'cancelled',
            'result_json' => ['wave_dispatching' => true]]));
        $execution = $agent->toolExecutions()->create(['logical_index' => 1, 'tool_name' => 'total', 'tool_kind' => 'api', 'status' => 'running']);
        try {
            app(\App\ApiTools\ToolRuntime::class)->start($preset, [], $context, continuation: ['agent_wave_handoff' => [
                'agent_run_id' => $agent->id, 'wave_id' => 'wave-1', 'action_id' => 'total', 'execution_id' => $execution->id,
                'purpose' => 'Calculate total', 'arguments' => [], 'tool' => ['name' => 'total', 'display_name' => 'Total', 'kind' => 'api'],
            ]]);
            $this->fail('Expected cancelled owner rejection.');
        } catch (\DomainException $exception) { $this->assertSame('api_tool_continuation_scope_mismatch', $exception->getMessage()); }
        $this->assertDatabaseCount('api_tool_runs', 0);
        $this->assertDatabaseCount('api_tool_steps', 0);
        $this->assertDatabaseCount('flow_runs', 0);
        Queue::assertNotPushed(CoordinatorJob::class);
    }

    public function test_recovery_of_deferred_terminal_tool_wakes_parent_without_consuming_observation(): void
    {
        $context = new AgentExecutionContext((string) Str::uuid(), 'acme', 'sales', 'widget', 'anonymous_widget', null, 'en', 'UTC');
        $agent = AgentRun::create(array_merge($context->jsonSerialize(), ['status' => 'running', 'result_json' => ['wave_dispatching' => true]]));
        $tool = $this->attachWaveTool($agent, $context, 'total', defer: true);
        $tool->update(['status' => 'completed', 'outputs' => ['total' => 42], 'physical_requests' => 2]);
        $this->assertSame(1, app(ToolContinuation::class)->recoverReady('acme'));
        Queue::assertPushed(ExecuteAgentRunJob::class, fn ($job) => $job->agentRunId === $agent->id);
        Queue::assertNotPushed(ResumeApiTool::class);
        $this->assertNull($tool->fresh()->resumed_at);
        $this->assertArrayNotHasKey('completed_actions', $agent->fresh()->result_json);
        app(\App\Agent\AgentRunControl::class)->cancel($agent);
        $this->assertSame(0, app(ToolContinuation::class)->recoverReady('acme'));
        Queue::assertPushed(ExecuteAgentRunJob::class, 1);
    }

    private function attachWaveTool(AgentRun $agent, AgentExecutionContext $context, string $action, bool $defer = false): ApiToolRun
    {
        $execution = $agent->toolExecutions()->create(['logical_index' => (int) $agent->toolExecutions()->max('logical_index') + 1,
            'tool_name' => $action, 'tool_kind' => 'api', 'status' => 'running', 'started_at' => now()]);
        $tool = $this->fixtureRun($context);
        app(ToolContinuation::class)->attachAgent($tool->id, $agent, ['wave_id' => 'wave-1', 'action_id' => $action, 'defer_dispatch' => $defer,
            'purpose' => 'Calculate total', 'execution_id' => $execution->id, 'arguments' => [],
            'tool' => ['name' => $action, 'display_name' => $action, 'kind' => 'api']]);
        return $tool;
    }

    public function test_direct_chat_automatically_finishes_original_tool_calls_without_reinvoking_pending_tool(): void
    {
        $user = User::create(['name' => 'Tester', 'email' => 'api-continuation@example.com', 'password' => 'test-password', 'locale' => 'en']);
        \App\Models\ProjectMembership::create(['tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'sales', 'role' => 'member']);
        $conversation = Conversation::create(['tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'sales', 'title' => 'Totals']);
        $context = new AgentExecutionContext((string) Str::uuid(), 'acme', 'sales', 'chat', 'user', (string) $user->id, 'en', 'UTC');
        $tool = $this->fixtureRun($context);
        $source = new class($tool->id) implements ChatToolSourceContract {
            public array $calls = [];
            public function __construct(private string $runId) {}
            public function key(): string { return 'api_tool'; }
            public function catalog(User $user, ?string $projectKey = null): array { return [
                ['name' => 'total', 'inputSchema' => ['type' => 'object']], ['name' => 'currency', 'inputSchema' => ['type' => 'object']],
            ]; }
            public function invoke(array $tool, array $arguments, User $user, array $context = []): ChatToolInvocationResult {
                $this->calls[] = $tool['name'];
                return $tool['name'] === 'total'
                    ? new ChatToolInvocationResult('task_accepted', ['run_id' => $this->runId], ['source' => 'api_tool', 'run_id' => $this->runId])
                    : new ChatToolInvocationResult('completed', ['currency' => 'EUR']);
            }
        };
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('provider')->andReturn($provider);
        $ai->shouldReceive('chatWithHistory')->once()->withArgs(fn ($system, $history) => count($history) === 1)
            ->andReturn(new AiResponse('', 'openai', 'test-model', toolCalls: [
                ['id' => 'call-total', 'type' => 'function', 'function' => ['name' => 'total', 'arguments' => '{}']],
                ['id' => 'call-currency', 'type' => 'function', 'function' => ['name' => 'currency', 'arguments' => '{}']],
            ]));
        $ai->shouldReceive('chatWithHistory')->once()->withArgs(function ($system, $history): bool {
            return count($history) === 4 && $history[2]['tool_call_id'] === 'call-total'
                && str_contains($history[2]['content'], '42') && $history[3]['tool_call_id'] === 'call-currency';
        })->andReturn(new AiResponse('The total is EUR 42.', 'openai', 'test-model'));
        $service = new McpToolCallingService($ai, new ChatToolSourceRegistry([$source]));
        $this->app->instance(McpToolCallingService::class, $service);
        $response = $service->chatWithTools('system', [['role' => 'user', 'content' => 'Total?']], user: $user,
            context: ['conversation_id' => $conversation->id, 'project_key' => 'sales']);
        $this->assertSame('tool_interaction', $response->finishReason);
        $this->assertSame(['total'], $source->calls);
        $this->assertSame('chat', $tool->fresh()->continuation['type']);
        $this->assertSame(0, Message::query()->count());
        $tool->update(['status' => 'completed', 'outputs' => ['total' => 42]]);
        (new ResumeApiTool($tool->id, 'acme'))->handle(app(ToolContinuation::class));
        (new ResumeApiTool($tool->id, 'acme'))->handle(app(ToolContinuation::class));
        $this->assertSame(['total', 'currency'], $source->calls);
        $this->assertSame('The total is EUR 42.', Message::query()->sole()->content);
        $this->assertNotNull($tool->fresh()->resumed_at);
    }

    public function test_async_chat_cannot_reset_provider_iteration_limit_across_handoffs(): void
    {
        config(['mcp.tool_calling.max_iterations' => 3, 'api-tools.chat_physical_requests' => 10]);
        [$user, $conversation, $context] = $this->chatOwner();
        $source = $this->asyncSource($context);
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('provider')->andReturn($provider);
        $calls = 0;
        $ai->shouldReceive('chatWithHistory')->times(3)->andReturnUsing(function () use (&$calls): AiResponse {
            return new AiResponse('', 'openai', 'test', toolCalls: [
                ['id' => 'call-'.++$calls, 'type' => 'function', 'function' => ['name' => 'total', 'arguments' => '{}']],
            ]);
        });
        $service = new McpToolCallingService($ai, new ChatToolSourceRegistry([$source]));
        $this->app->instance(McpToolCallingService::class, $service);
        $service->chatWithTools('system', [['role' => 'user', 'content' => 'Total?']], user: $user,
            context: ['conversation_id' => $conversation->id, 'project_key' => 'sales']);
        for ($i = 0; $i < 3; $i++) {
            $run = $source->runs[$i];
            $run->update(['status' => 'completed', 'outputs' => ['total' => 42], 'physical_requests' => 1]);
            (new ResumeApiTool($run->id, 'acme'))->handle(app(ToolContinuation::class));
        }
        $this->assertSame(3, $calls);
        $this->assertCount(3, $source->runs); // A fourth asynchronous call is never started.
        $this->assertSame([2, 3, 3], array_map(fn ($r) => $r->fresh()->continuation['context']['_api_tool_chat_budget']['iterations_used'], $source->runs));
        $this->assertSame([10, 9, 8], array_map(fn ($r) => $r->request_limit, $source->runs));
        $this->assertSame('chat_iteration_limit', Message::query()->latest('id')->first()->metadata['finish_reason']);
    }

    public function test_chat_siblings_share_one_physical_allowance_across_async_runs(): void
    {
        config(['mcp.tool_calling.max_iterations' => 3, 'api-tools.chat_physical_requests' => 3]);
        [$user, $conversation, $context] = $this->chatOwner();
        $source = $this->asyncSource($context);
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('provider')->andReturn($provider);
        $ai->shouldReceive('chatWithHistory')->once()->withArgs(fn ($s, $h) => count($h) === 1)->andReturn(
            new AiResponse('', 'openai', 'test', toolCalls: array_map(fn ($i) => [
                'id' => 'call-'.$i, 'type' => 'function', 'function' => ['name' => 'total', 'arguments' => '{}'],
            ], [1, 2, 3])));
        $ai->shouldReceive('chatWithHistory')->once()->withArgs(fn ($s, $h) => count($h) === 5 && str_contains($h[4]['content'], 'chat_physical_limit'))
            ->andReturn(new AiResponse('Partial total.', 'openai', 'test'));
        $service = new McpToolCallingService($ai, new ChatToolSourceRegistry([$source]));
        $this->app->instance(McpToolCallingService::class, $service);
        $service->chatWithTools('system', [['role' => 'user', 'content' => 'Total?']], user: $user,
            context: ['conversation_id' => $conversation->id, 'project_key' => 'sales']);
        foreach ([2, 1] as $index => $physical) {
            $run = $source->runs[$index];
            $run->update(['status' => 'completed', 'outputs' => ['total' => 42], 'physical_requests' => $physical]);
            (new ResumeApiTool($run->id, 'acme'))->handle(app(ToolContinuation::class));
        }
        $this->assertCount(2, $source->runs);
        $this->assertSame([3, 1], array_map(fn ($r) => $r->request_limit, $source->runs));
        $this->assertSame(3, array_sum(array_map(fn ($r) => $r->fresh()->physical_requests, $source->runs)));
        $this->assertSame('Partial total.', Message::query()->latest('id')->first()->content);
    }

    public static function asyncSiblingCrashPoints(): array
    {
        return ['after start before attach' => ['before_child_attach'], 'after attach before parent response' => ['after_child_attach']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('asyncSiblingCrashPoints')]
    public function test_async_sibling_reuses_one_runtime_after_a_crash_and_resumes_only_once(string $crashPoint): void
    {
        config(['api-tools.enabled' => true, 'connector-api.chat_tools.enabled' => true]);
        [$user, $conversation, $context] = $this->chatOwner();
        $preset = $this->replayPreset($context);
        $source = $this->asyncSource($context);
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('provider')->andReturn($provider);
        $ai->shouldReceive('chatWithHistory')->once()->withArgs(fn ($s, $h) => count($h) === 1)->andReturn(new AiResponse('', 'openai', 'test', toolCalls: [
            ['id' => 'call-parent', 'type' => 'function', 'function' => ['name' => 'total', 'arguments' => '{}']],
            ['id' => 'call-sibling', 'type' => 'function', 'function' => ['name' => 'api_tool_'.$preset->id, 'arguments' => '{}']],
        ]));
        $ai->shouldReceive('chatWithHistory')->once()->withArgs(fn ($s, $h) => count($h) === 4)->andReturn(new AiResponse('Combined total 44.', 'openai', 'test'));
        $service = new McpToolCallingService($ai, new ChatToolSourceRegistry([$source, app(\App\Ai\Tools\Sources\ApiConnectorChatToolSource::class)]));
        $this->app->instance(McpToolCallingService::class, $service);
        $service->chatWithTools('system', [['role' => 'user', 'content' => 'Total?']], user: $user,
            context: ['conversation_id' => $conversation->id, 'project_key' => 'sales']);
        $parent = $source->runs[0];
        $parent->update(['status' => 'completed', 'outputs' => ['total' => 2]]);
        $crashed = false;
        ApiToolRun::saving(function (ApiToolRun $saving) use ($parent, $crashPoint, &$crashed): void {
            $beforeAttach = $saving->id !== $parent->id && data_get($saving->continuation, 'type') === 'chat';
            $afterAttach = $saving->id === $parent->id && $saving->resumed_at !== null && is_string(data_get($saving->continuation, 'child_handoff.run_id'));
            if (! $crashed && (($crashPoint === 'before_child_attach' && $beforeAttach) || ($crashPoint === 'after_child_attach' && $afterAttach))) {
                $crashed = true;
                throw new \RuntimeException('simulated worker crash');
            }
        });
        try { (new ResumeApiTool($parent->id, 'acme'))->handle(app(ToolContinuation::class)); $this->fail('Expected crash'); }
        catch (\RuntimeException $exception) { $this->assertSame('simulated worker crash', $exception->getMessage()); }
        $this->assertTrue($crashed);
        $this->assertSame(2, ApiToolRun::query()->count());
        $child = ApiToolRun::query()->whereKeyNot($parent->id)->sole();
        $this->assertNotNull($child->idempotency_key);
        $this->assertNull($parent->fresh()->resumed_at);
        $this->assertArrayNotHasKey('response', $parent->fresh()->continuation);
        if ($crashPoint === 'after_child_attach') {
            $this->assertSame($child->id, $parent->fresh()->continuation['child_handoff']['run_id']);
            $this->assertSame([], $parent->fresh()->continuation['remaining']);
        }
        (new ResumeApiTool($parent->id, 'acme'))->handle(app(ToolContinuation::class));
        (new ResumeApiTool($parent->id, 'acme'))->handle(app(ToolContinuation::class));
        $this->assertSame(2, ApiToolRun::query()->count());
        $this->assertSame($child->id, $parent->fresh()->continuation['child_handoff']['run_id']);
        $this->assertNotNull($parent->fresh()->resumed_at);
        $this->assertSame(0, Message::query()->count());
        Queue::assertPushed(CoordinatorJob::class, 1);
        $child->update(['status' => 'completed', 'outputs' => ['total' => 42], 'physical_requests' => 1]);
        (new ResumeApiTool($child->id, 'acme'))->handle(app(ToolContinuation::class));
        (new ResumeApiTool($child->id, 'acme'))->handle(app(ToolContinuation::class));
        $this->assertSame('Combined total 44.', Message::query()->sole()->content);
        $this->assertSame(2, ApiToolRun::query()->count());
        Queue::assertPushed(CoordinatorJob::class, 1);
    }

    private function replayPreset(AgentExecutionContext $context): ApiTool
    {
        $connector = \Padosoft\AskMyDocsConnectorApi\Models\ApiConnector::create(['tenant_id' => 'acme', 'project_key' => 'sales', 'name' => 'Replay API', 'is_active' => true]);
        $route = \Padosoft\AskMyDocsConnectorApi\Models\ApiRoute::create([
            'tenant_id' => 'acme', 'api_connector_id' => $connector->id, 'project_key' => 'sales', 'name' => 'replay', 'slug' => 'replay',
            'http_method' => 'GET', 'url' => 'https://api.example.com/total', 'mode' => 'tool', 'status' => 'active',
            'input_schema' => ['type' => 'object'], 'output_schema' => ['type' => 'object', 'properties' => ['total' => ['type' => 'integer']]],
        ]);
        $tool = app(\App\ApiTools\ToolLifecycle::class)->saveDraft(null, ['name' => 'Replay-safe total', 'definition' => [
            'version' => 1, 'input_schema' => ['type' => 'object'],
            'steps' => [['id' => 'total', 'operation' => 'api', 'route_id' => $route->id, 'bindings' => []]],
            'output' => ['total' => ['source' => 'step', 'step' => 'total', 'path' => 'total']],
            'limits' => ['physical_requests' => 5, 'timeout_seconds' => 30],
        ]], $context);
        $tool->update(['status' => 'active', 'publication_intent' => true]);
        return $tool;
    }

    public function test_provider_failure_on_resume_cannot_restore_a_spent_iteration_on_retry(): void
    {
        config(['mcp.tool_calling.max_iterations' => 2]);
        [$user, $conversation, $context] = $this->chatOwner();
        $source = $this->asyncSource($context);
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('provider')->andReturn($provider);
        $ai->shouldReceive('chatWithHistory')->once()->withArgs(fn ($s, $h) => count($h) === 1)->andReturn(new AiResponse('', 'openai', 'test', toolCalls: [
            ['id' => 'call-total', 'type' => 'function', 'function' => ['name' => 'total', 'arguments' => '{}']],
        ]));
        $ai->shouldReceive('chatWithHistory')->once()->withArgs(fn ($s, $h) => count($h) === 3)->andThrow(new \RuntimeException('provider unavailable'));
        $service = new McpToolCallingService($ai, new ChatToolSourceRegistry([$source]));
        $this->app->instance(McpToolCallingService::class, $service);
        $service->chatWithTools('system', [['role' => 'user', 'content' => 'Total?']], user: $user,
            context: ['conversation_id' => $conversation->id, 'project_key' => 'sales']);
        $run = $source->runs[0];
        $run->update(['status' => 'completed', 'outputs' => ['total' => 42], 'physical_requests' => 1]);
        try { (new ResumeApiTool($run->id, 'acme'))->handle(app(ToolContinuation::class)); $this->fail('Expected provider failure'); }
        catch (\RuntimeException $exception) { $this->assertSame('provider unavailable', $exception->getMessage()); }
        $this->assertSame(2, $run->fresh()->continuation['context']['_api_tool_chat_budget']['iterations_used']);
        (new ResumeApiTool($run->id, 'acme'))->handle(app(ToolContinuation::class));
        $this->assertCount(1, $source->runs);
        $this->assertNotNull($run->fresh()->resumed_at);
        $this->assertSame('chat_iteration_limit', Message::query()->sole()->metadata['finish_reason']);
    }

    public function test_public_chat_source_caps_each_new_runtime_to_the_remaining_turn_allowance(): void
    {
        config(['api-tools.enabled' => true, 'connector-api.chat_tools.enabled' => true]);
        [$user, $conversation, $context] = $this->chatOwner();
        $connector = \Padosoft\AskMyDocsConnectorApi\Models\ApiConnector::create(['tenant_id' => 'acme', 'project_key' => 'sales', 'name' => 'Budget API', 'is_active' => true]);
        $route = \Padosoft\AskMyDocsConnectorApi\Models\ApiRoute::create([
            'tenant_id' => 'acme', 'api_connector_id' => $connector->id, 'project_key' => 'sales', 'name' => 'total', 'slug' => 'total',
            'http_method' => 'GET', 'url' => 'https://api.example.com/total', 'mode' => 'tool', 'status' => 'active',
            'input_schema' => ['type' => 'object'], 'output_schema' => ['type' => 'object', 'properties' => ['total' => ['type' => 'integer']]],
        ]);
        $source = app(\App\Ai\Tools\Sources\ApiConnectorChatToolSource::class);
        foreach ([5, 1] as $configured) {
            $tool = app(\App\ApiTools\ToolLifecycle::class)->saveDraft(null, ['name' => 'Capped '.$configured, 'definition' => [
                'version' => 1, 'input_schema' => ['type' => 'object'],
                'steps' => [['id' => 'total', 'operation' => 'api', 'route_id' => $route->id, 'bindings' => []]],
                'output' => ['total' => ['source' => 'step', 'step' => 'total', 'path' => 'total']],
                'limits' => ['physical_requests' => $configured, 'timeout_seconds' => 30],
            ]], $context);
            $tool->update(['status' => 'active', 'publication_intent' => true]);
            $definition = collect($source->catalog($user, 'sales'))->firstWhere('preset_id', $tool->id);
            $outcome = $source->invoke($definition, [], $user, ['conversation_id' => $conversation->id, 'project_key' => 'sales',
                '_api_tool_chat_budget' => ['physical_limit' => 7, 'physical_used' => 5]]);
            $run = ApiToolRun::query()->findOrFail($outcome->metadata['run_id']);
            $this->assertSame(min(2, $configured), $run->request_limit);
        }
    }

    public function test_revoked_chat_actor_consumes_handoff_without_a_model_or_new_tool_call(): void
    {
        [$user, $conversation, $context] = $this->chatOwner();
        $source = $this->asyncSource($context);
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('name')->andReturn('openai');
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('provider')->andReturn($provider);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse('', 'openai', 'test', toolCalls: [
            ['id' => 'call-total', 'type' => 'function', 'function' => ['name' => 'total', 'arguments' => '{}']],
        ]));
        $service = new McpToolCallingService($ai, new ChatToolSourceRegistry([$source]));
        $this->app->instance(McpToolCallingService::class, $service);
        $service->chatWithTools('system', [['role' => 'user', 'content' => 'Total?']], user: $user,
            context: ['conversation_id' => $conversation->id, 'project_key' => 'sales']);
        $run = $source->runs[0];
        $run->update(['status' => 'completed', 'outputs' => ['total' => 42]]);
        \App\Models\ProjectMembership::where('user_id', $user->id)->delete();
        (new ResumeApiTool($run->id, 'acme'))->handle(app(ToolContinuation::class));
        (new ResumeApiTool($run->id, 'acme'))->handle(app(ToolContinuation::class));
        $this->assertCount(1, $source->runs);
        $this->assertSame(0, Message::query()->count());
        $this->assertNotNull($run->fresh()->resumed_at);
        $this->assertSame('api_tool_authorization_revoked', $run->fresh()->continuation['resume_error']);
    }

    private function chatOwner(): array
    {
        $user = User::create(['name' => 'Budget owner', 'email' => 'chat-budget@example.com', 'password' => 'test-password', 'locale' => 'en']);
        \App\Models\ProjectMembership::create(['tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'sales', 'role' => 'member']);
        $conversation = Conversation::create(['tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'sales', 'title' => 'Totals']);
        $context = new AgentExecutionContext((string) Str::uuid(), 'acme', 'sales', 'chat', 'user', (string) $user->id, 'en', 'UTC');
        return [$user, $conversation, $context];
    }

    private function asyncSource(AgentExecutionContext $context): ChatToolSourceContract
    {
        return new class(fn () => $this->fixtureRun($context)) implements ChatToolSourceContract {
            public array $runs = [];
            public function __construct(private \Closure $factory) {}
            public function key(): string { return 'api_tool'; }
            public function catalog(User $user, ?string $projectKey = null): array { return [['name' => 'total', 'inputSchema' => ['type' => 'object']]]; }
            public function invoke(array $tool, array $arguments, User $user, array $context = []): ChatToolInvocationResult {
                $run = ($this->factory)();
                $budget = $context['_api_tool_chat_budget'];
                $run->update(['request_limit' => min($run->request_limit, $budget['physical_limit'] - $budget['physical_used'])]);
                $this->runs[] = $run;
                return new ChatToolInvocationResult('task_accepted', ['run_id' => $run->id], ['source' => 'api_tool', 'run_id' => $run->id]);
            }
        };
    }

    public function test_vendor_job_tenant_scope_is_applied_before_execution_and_restored_after_failure(): void
    {
        $id = (string) Str::uuid();
        DB::table('flow_runs')->insert(['id' => $id, 'tenant_id' => 'acme', 'definition_name' => 'askmydocs.api_tool', 'status' => 'running', 'created_at' => now(), 'updated_at' => now()]);
        $job = new CoordinatorJob($id, new GraphDefinition([new GraphNode('a', 'test')], []), 'askmydocs.api_tool', []);
        $tool = $this->fixtureRun(new AgentExecutionContext((string) Str::uuid(), 'acme', 'sales', 'widget', 'anonymous_widget', null, 'en', 'UTC'));
        $tool->update(['flow_run_id' => $id]);
        $beforeConnection = config('queue.default');
        config(['api-tools.connection' => 'tool-workers']);
        app(TenantContext::class)->set('other');
        app(ConnectorTenantContext::class)->set('other');
        try {
            (new ToolTenantScope)->handle($job, function () {
                $this->assertSame('acme', app(TenantContext::class)->current());
                $this->assertSame('acme', app(ConnectorTenantContext::class)->current());
                $this->assertSame('tool-workers', config('queue.default'));
                throw new \RuntimeException('Expected failure');
            });
            $this->fail('Exception was swallowed.');
        } catch (\RuntimeException $exception) { $this->assertSame('Expected failure', $exception->getMessage()); }
        $this->assertSame('other', app(TenantContext::class)->current());
        $this->assertSame('other', app(ConnectorTenantContext::class)->current());
        $this->assertSame($beforeConnection, config('queue.default'));
    }

    private function fixtureRun(AgentExecutionContext $context): ApiToolRun
    {
        $tool = ApiTool::create(['tenant_id' => 'acme', 'project_key' => 'sales', 'name' => (string) Str::uuid()]);
        $revision = ApiToolRevision::create(['tenant_id' => 'acme', 'api_tool_id' => $tool->id, 'number' => 1, 'definition' => [], 'definition_hash' => str_repeat('a', 64), 'created_at' => now()]);
        return ApiToolRun::create(['id' => (string) Str::uuid(), 'tenant_id' => 'acme', 'project_key' => 'sales', 'api_tool_id' => $tool->id,
            'api_tool_revision_id' => $revision->id, 'status' => 'running', 'mode' => 'production', 'content_hash' => str_repeat('a', 64),
            'snapshot' => [], 'context' => $context->jsonSerialize(), 'inputs' => [], 'request_limit' => 10, 'deadline_at' => now()->addMinutes(5)]);
    }
}
