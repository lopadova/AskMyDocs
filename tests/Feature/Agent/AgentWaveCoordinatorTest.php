<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoop;
use App\Agent\Tools\AgentToolActionResult;
use App\Agent\Waves\AgentWaveExecutor;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\User;
use App\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use App\Services\Kb\Investigation\KbInvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Tests\TestCase;

final class AgentWaveCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.waves.enabled' => true, 'kb.investigation.enabled' => true,
            'reasoning.enabled' => true, 'reasoning.parallel_research' => true]);
    }

    public function test_research_is_planned_before_retrieval_and_siblings_join_before_replanning(): void
    {
        $this->route('get_orders');
        $this->route('get_followup');
        $this->mcpTool();
        $run = $this->makeRun();
        $investigation = Mockery::mock(KbInvestigationService::class);
        $investigation->shouldNotReceive('investigate');
        $this->app->instance(KbInvestigationService::class, $investigation);

        $phase = 'initial';
        $executor = Mockery::mock(AgentWaveExecutor::class);
        $executor->shouldReceive('run')->twice()->andReturnUsing(function (array $tasks) use (&$phase, $run) {
            if ($phase === 'planned') {
                $this->assertCount(3, $tasks);
                $this->assertSame(3, $run->toolExecutions()->count(), 'All executions must be registered before dispatch.');
                $phase = 'joined';
                $keys = array_keys($tasks);
                return [
                    $keys[2] => new AgentToolActionResult(['error' => 'mcp_remote_error'], 0, false, 'mcp_remote_error'),
                    $keys[1] => new AgentToolActionResult(['order' => ['id' => 'ORDER-42']], 1),
                    $keys[0] => new AgentToolActionResult(['documents' => []], 0),
                ];
            }
            $this->assertSame('replanned', $phase);
            $this->assertCount(1, $tasks);
            $phase = 'dependent_joined';
            return [array_key_first($tasks) => new AgentToolActionResult(['order' => ['id' => 'ORDER-43']], 1)];
        });
        $this->app->instance(AgentWaveExecutor::class, $executor);

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->times(3)->andReturnUsing(function ($system, $messages) use (&$phase) {
            if ($phase === 'initial') {
                $phase = 'planned';
                return $this->plan(['decision' => 'tools', 'actions' => [
                    ['id' => 'kb', 'tool' => 'search_knowledge_base', 'arguments' => ['query' => 'ordine e spedizione'], 'depends_on' => [], 'purpose' => 'Search documents'],
                    $this->action('orders', 'get_orders'),
                    $this->action('shipments', 'mcp_shipments_12345678'),
                    $this->action('followup', 'get_followup', ['orders']),
                ]]);
            }
            $input = json_decode($messages[0]['content'], true, flags: JSON_THROW_ON_ERROR);
            if ($phase === 'joined') {
                $this->assertCount(3, $input['completed_actions']);
                $this->assertContains('failed', array_column($input['completed_actions'], 'status'));
                $this->assertContains('completed', array_column($input['completed_actions'], 'status'));
                $this->assertNotEmpty($input['evidence_summary']['tool_results']);
                $this->assertNotContains('followup', array_column($input['completed_actions'], 'id'));
                $phase = 'replanned';
                return $this->plan(['decision' => 'tools', 'actions' => [$this->action('followup', 'get_followup', ['orders'])]]);
            }
            $this->assertSame('dependent_joined', $phase, 'Replanning must wait for every sibling.');
            return $this->plan(['decision' => 'answer', 'actions' => []]);
        });
        $this->app->instance(AiManager::class, $ai);

        $outcome = app(AgentLoop::class)->run($run, $this->context($run));
        $this->assertSame('answer', $outcome->decision, json_encode([$outcome->stopReason, $outcome->completedActions, $run->fresh()->counters_json]));
        $this->assertSame(4, $run->fresh()->counters_json['logical_calls']);
        $this->assertSame(2, $run->fresh()->counters_json['physical_calls']);
        $this->assertCount(4, $outcome->completedActions);
        $this->assertSame(1, AgentRun::query()->count(), 'Subquestions must not spawn autonomous research runs.');
    }

    public static function dependencyDelays(): array
    {
        return ['immediate parent' => [0], 'parent leaves less than the planning estimate' => [27]];
    }

    #[DataProvider('dependencyDelays')]
    public function test_dependent_calls_persist_the_exact_parent_field_proof_before_dispatch_and_ground_the_child(int $delay): void
    {
        $this->freezeTime();
        config(['agent.job_timeout_seconds' => 120, 'agent.synthesis.time_reserve_seconds' => 60,
            'agent.planner.time_reserve_seconds' => 30, 'agent.waves.minimum_dispatch_seconds' => 5]);
        $this->route('get_orders'); $this->route('get_followup');
        ApiRoute::where('tenant_id', 'acme')->where('slug', 'get_orders')->firstOrFail()->update(['identity_fields' => ['reference']]);
        ApiRoute::where('tenant_id', 'acme')->where('slug', 'get_followup')->firstOrFail()->update(['identity_fields' => ['resource_key'],
            'tool_definition' => ['name' => 'get_followup', 'description' => 'Read resource',
                'input_schema' => ['type' => 'object', 'properties' => ['lookup' => ['type' => 'integer']]]]]);
        $run = $this->makeRun(); $phase = 0;
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->times(3)->andReturnUsing(function () use (&$phase, $delay) {
            if ($phase === 1 && $delay > 0) {
                $timeout = app(\App\Ai\AgentChatModelScope::class)->options([])['timeout'];
                $this->assertGreaterThanOrEqual(22, $timeout);
                $this->assertLessThanOrEqual(23, $timeout, 'Replanning must fit before the useful wave and synthesis reserves.');
            }
            return $this->plan(match ($phase++) {
                0 => ['decision' => 'tools', 'actions' => [$this->action('source', 'get_orders')]],
                1 => ['decision' => 'tools', 'actions' => [['id' => 'resource', 'tool' => 'get_followup',
                    'arguments' => ['lookup' => ['$from' => 'source', 'path' => 'resource_key']], 'depends_on' => ['source'], 'purpose' => 'Read resource']]],
                default => ['decision' => 'answer', 'actions' => []],
            });
        });
        $this->app->instance(AiManager::class, $ai);
        $executor = Mockery::mock(AgentWaveExecutor::class);
        $executor->shouldReceive('run')->twice()->andReturnUsing(function (array $tasks) use ($run, $delay) {
            $id = array_key_first($tasks);
            if ($id === 'source') {
                if ($delay > 0) $this->travel($delay)->seconds();
                return [$id => new AgentToolActionResult(['reference' => 'PURCHASE-Q91', 'resource_key' => 72345], 1)];
            }
            $execution = $run->toolExecutions()->where('tool_name', 'get_followup')->firstOrFail();
            $this->assertSame('resource_key', $execution->result_meta_json['argument_bindings'][0]['source_path']);
            $this->assertSame(['lookup' => 72345], $execution->arguments_json);
            return [$id => new AgentToolActionResult(['resource_key' => 72345, 'available' => 0], 1)];
        });
        $this->app->instance(AgentWaveExecutor::class, $executor);
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));
        $this->assertSame('answer', $outcome->decision);
        $child = $outcome->evidence->apiTools()[1];
        $bound = app(\App\Services\Chat\Reasoning\EvidenceProjector::class)->bind($outcome->evidence->jsonSerialize(),
            ['tool_execution_id' => $child['execution_id'], 'evidence_hash' => $child['evidence_hash'], 'record_path' => '$'],
            ['PURCHASE-Q91'], 'acme', 'crm');
        $this->assertArrayNotHasKey('reason', $bound);
        $this->assertSame(0, $bound['record']['available']);
        $this->assertGreaterThanOrEqual(60, app(\App\Agent\AgentRunDeadline::class)->remaining($run));
    }

    public function test_completed_empty_knowledge_action_unblocks_dependency_after_replan(): void
    {
        $this->route('get_followup');
        $run = $this->makeRun();
        $phase = 0;
        $executor = Mockery::mock(AgentWaveExecutor::class);
        $executor->shouldReceive('run')->twice()->andReturnUsing(function (array $tasks) use (&$phase) {
            $this->assertCount(1, $tasks);
            $this->assertSame($phase === 0 ? 'kb' : 'followup', array_key_first($tasks));
            return [array_key_first($tasks) => $phase++ === 0
                ? new AgentToolActionResult(['documents' => []], 0)
                : new AgentToolActionResult(['result' => 'found'], 1)];
        });
        $this->app->instance(AgentWaveExecutor::class, $executor);
        $kb = ['id' => 'kb', 'tool' => 'search_knowledge_base', 'arguments' => ['query' => 'ordine'], 'depends_on' => [], 'purpose' => 'Search'];
        $dependent = $this->action('followup', 'get_followup', ['kb']);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->times(3)->andReturn(
            $this->plan(['decision' => 'tools', 'actions' => [$kb, $dependent]]),
            $this->plan(['decision' => 'tools', 'actions' => [$dependent]]),
            $this->plan(['decision' => 'answer', 'actions' => []]));
        $this->app->instance(AiManager::class, $ai);
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));
        $this->assertSame('answer', $outcome->decision, (string) $outcome->stopReason);
        $this->assertSame(['kb', 'followup'], array_column($outcome->completedActions, 'id'));
    }

    public function test_shared_physical_limit_waits_for_confirmation_after_allowed_sibling_is_merged(): void
    {
        config(['agent.limits.physical_hard' => 1]);
        $this->route('get_orders');
        $this->route('get_followup');
        $run = $this->makeRun();
        $executor = Mockery::mock(AgentWaveExecutor::class);
        $executor->shouldReceive('run')->once()->andReturnUsing(function (array $tasks) {
            $this->assertCount(1, $tasks);
            return [array_key_first($tasks) => new AgentToolActionResult(['order' => ['id' => 'ORDER-42']], 1)];
        });
        $this->app->instance(AgentWaveExecutor::class, $executor);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn($this->plan(['decision' => 'tools', 'actions' => [
            $this->action('orders', 'get_orders'), $this->action('followup', 'get_followup'),
        ]]));
        $this->app->instance(AiManager::class, $ai);
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));
        $this->assertSame('awaiting_confirmation', $outcome->decision);
        $this->assertNotEmpty($outcome->evidence->apiTools());
        $this->assertSame(1, $run->fresh()->counters_json['physical_calls']);
        $this->assertNotEmpty(data_get($run->fresh()->budget_json, 'requested_extension'));
    }

    public function test_all_mcp_interactions_are_preserved_alongside_completed_siblings(): void
    {
        $this->route('get_orders');
        $this->mcpTool();
        $this->mcpTool('mcp_tracking_12345678');
        $run = $this->makeRun();
        $executor = Mockery::mock(AgentWaveExecutor::class);
        $executor->shouldReceive('run')->once()->andReturnUsing(function (array $tasks) {
            $this->assertCount(3, $tasks);
            return [
                'orders' => new AgentToolActionResult(['order' => ['id' => 'ORDER-42']], 1),
                'one' => new AgentToolActionResult(['error' => 'input_required'], 1, false, 'mcp_input_required', ['mcp' => ['pending_interaction_id' => 'interaction-one']]),
                'two' => new AgentToolActionResult(['error' => 'input_required'], 1, false, 'mcp_input_required', ['mcp' => ['pending_interaction_id' => 'interaction-two']]),
            ];
        });
        $this->app->instance(AgentWaveExecutor::class, $executor);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn($this->plan(['decision' => 'tools', 'actions' => [
            $this->action('orders', 'get_orders'), $this->action('one', 'mcp_shipments_12345678'),
            $this->action('two', 'mcp_tracking_12345678'),
        ]]));
        $this->app->instance(AiManager::class, $ai);
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));
        $this->assertSame('awaiting_mcp_input', $outcome->decision);
        $this->assertCount(3, $outcome->completedActions);
        $this->assertNotEmpty($outcome->evidence->apiTools());
        $interactions = data_get($run->fresh()->result_json, 'pending_mcp_interaction.interactions');
        $this->assertCount(2, $interactions);
        $this->assertEqualsCanonicalizing(['interaction-one', 'interaction-two'], array_map(fn ($item) => data_get($item, 'interaction.pending_interaction_id'), $interactions));
    }

    public function test_interrupted_direct_call_is_accounted_without_being_reissued(): void
    {
        $this->route('get_orders');
        $run = $this->makeRun();
        $run->forceFill(['result_json' => [...$run->result_json, 'wave_dispatching' => true]])->save();
        $execution = $run->toolExecutions()->create(['logical_index' => 1, 'tool_name' => 'get_orders', 'tool_kind' => 'api',
            'status' => 'running', 'arguments_json' => [], 'started_at' => now(),
            'result_meta_json' => ['action_id' => 'original', 'wave_id' => 'interrupted', 'physical_quota' => 2,
                'arguments_hash' => hash('sha256', '[]')]]);
        $executor = Mockery::mock(AgentWaveExecutor::class);
        $executor->shouldNotReceive('run');
        $this->app->instance(AgentWaveExecutor::class, $executor);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn($this->plan([
            'decision' => 'tools', 'actions' => [$this->action('retry', 'get_orders')],
        ]));
        $this->app->instance(AiManager::class, $ai);
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));
        $this->assertSame('partial', $outcome->decision);
        $this->assertSame('wave_interrupted', $execution->fresh()->error_code);
        $this->assertSame(2, $run->fresh()->counters_json['physical_calls']);
        $this->assertSame('duplicate_call_avoided', $outcome->completedActions[1]['error_code']);
        $this->assertFalse($run->fresh()->result_json['wave_dispatching']);
    }

    public function test_a_slow_plan_cannot_dispatch_a_wave_without_time_to_collect_it(): void
    {
        $this->freezeTime();
        $this->route('get_orders');
        $run = $this->makeRun();
        $executor = Mockery::mock(AgentWaveExecutor::class);
        $executor->shouldNotReceive('run');
        $this->app->instance(AgentWaveExecutor::class, $executor);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturnUsing(function () {
            $this->travel(53)->seconds();
            return $this->plan(['decision' => 'tools', 'actions' => [$this->action('orders', 'get_orders')]]);
        });
        $this->app->instance(AiManager::class, $ai);

        $outcome = app(AgentLoop::class)->run($run, $this->context($run));

        $this->assertSame('partial', $outcome->decision);
        $this->assertSame('time_limit', $outcome->stopReason);
        $this->assertSame(0, $run->toolExecutions()->count());
        $this->assertSame(0, $run->fresh()->counters_json['logical_calls']);
        $this->assertSame(0, $run->fresh()->counters_json['physical_calls']);
        $this->assertEmpty(data_get($run->fresh()->result_json, 'waves', []));
    }

    public function test_first_research_can_use_a_bounded_plan_when_less_than_the_estimated_thirty_seconds_remain(): void
    {
        $this->freezeTime();
        config(['agent.job_timeout_seconds' => 120, 'agent.synthesis.time_reserve_seconds' => 60,
            'agent.planner.time_reserve_seconds' => 30, 'agent.waves.minimum_dispatch_seconds' => 5]);
        $this->route('get_orders');
        $run = $this->makeRun();
        $this->travel(23)->seconds();
        $executor = Mockery::mock(AgentWaveExecutor::class);
        $executor->shouldReceive('run')->once()->andReturnUsing(function (array $tasks) {
            $this->travel(2)->seconds();
            return [array_key_first($tasks) => new AgentToolActionResult(['order' => ['id' => 'ORDER-42']], 1)];
        });
        $this->app->instance(AgentWaveExecutor::class, $executor);
        $ai = Mockery::mock(AiManager::class);
        $planning = 0;
        $ai->shouldReceive('chatWithHistory')->twice()->andReturnUsing(function () use (&$planning) {
            $timeout = app(\App\Ai\AgentChatModelScope::class)->options([])['timeout'];
            if ($planning++ > 0) {
                $this->assertGreaterThanOrEqual(21, $timeout);
                $this->assertLessThanOrEqual(22, $timeout);
                return $this->plan(['decision' => 'answer', 'actions' => []]);
            }
            $this->assertGreaterThanOrEqual(26, $timeout);
            $this->assertLessThanOrEqual(27, $timeout, 'Milliseconds and HTTP seconds round down, never into the wave reserve.');
            $this->travel(3)->seconds();
            return $this->plan(['decision' => 'tools', 'actions' => [$this->action('orders', 'get_orders')]]);
        });
        $this->app->instance(AiManager::class, $ai);
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));
        $this->assertSame('ORDER-42', data_get($outcome->evidence->apiTools(), '0.result.order.id'));
        $this->assertSame(1, $run->toolExecutions()->count());
        $this->assertSame('answer', $outcome->decision);
        $this->assertNull($outcome->stopReason);
        $this->assertGreaterThanOrEqual(60, app(\App\Agent\AgentRunDeadline::class)->remaining($run));
        $this->assertArrayNotHasKey('timeout', app(\App\Ai\AgentChatModelScope::class)->options([]));
    }

    public function test_first_research_still_stops_when_no_bounded_plan_and_useful_wave_fit(): void
    {
        $this->freezeTime();
        $run = $this->makeRun();
        $this->travel(47)->seconds();
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('chatWithHistory');
        $this->app->instance(AiManager::class, $ai);
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));
        $this->assertSame('time_limit', $outcome->stopReason);
        $this->assertSame(0, $run->toolExecutions()->count());
    }

    public function test_a_first_plan_that_consumes_its_bounded_request_budget_returns_partial_instead_of_failing_the_worker(): void
    {
        $this->freezeTime();
        $run = $this->makeRun();
        $this->travel(23)->seconds();
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturnUsing(function () {
            $this->travel(26)->seconds();
            throw new \Illuminate\Http\Client\ConnectionException('Synthetic bounded request timeout');
        });
        $this->app->instance(AiManager::class, $ai);
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));
        $this->assertSame('partial', $outcome->decision);
        $this->assertSame('time_limit', $outcome->stopReason);
        $this->assertSame(0, $run->toolExecutions()->count());
        $this->assertGreaterThanOrEqual(60, app(\App\Agent\AgentRunDeadline::class)->remaining($run));
    }

    public function test_collection_stops_before_a_plan_that_cannot_leave_a_useful_next_wave(): void
    {
        $this->freezeTime();
        $this->route('get_orders');
        $run = $this->makeRun();
        $executor = Mockery::mock(AgentWaveExecutor::class);
        $executor->shouldReceive('run')->once()->andReturnUsing(function (array $tasks) {
            $this->travel(47)->seconds();
            return [array_key_first($tasks) => new AgentToolActionResult(['order' => ['id' => 'ORDER-42']], 1)];
        });
        $this->app->instance(AgentWaveExecutor::class, $executor);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn($this->plan([
            'decision' => 'tools', 'actions' => [$this->action('orders', 'get_orders')],
        ]));
        $this->app->instance(AiManager::class, $ai);

        $outcome = app(AgentLoop::class)->run($run, $this->context($run));

        $this->assertSame('partial', $outcome->decision);
        $this->assertSame('time_limit', $outcome->stopReason);
        $this->assertSame('ORDER-42', data_get($outcome->evidence->apiTools(), '0.result.order.id'));
        $this->assertSame(1, $run->toolExecutions()->count());
        $this->assertSame(1, $run->fresh()->counters_json['logical_calls']);
        $this->assertSame(1, $run->fresh()->counters_json['physical_calls']);
    }

    public function test_a_replan_that_exhausts_its_bounded_budget_keeps_evidence_and_the_answer_reserve(): void
    {
        $this->freezeTime();
        $this->route('get_orders');
        $run = $this->makeRun();
        $executor = Mockery::mock(AgentWaveExecutor::class);
        $executor->shouldReceive('run')->once()->andReturnUsing(function (array $tasks) {
            $this->travel(27)->seconds();
            return [array_key_first($tasks) => new AgentToolActionResult(['order' => ['id' => 'ORDER-42']], 1)];
        });
        $this->app->instance(AgentWaveExecutor::class, $executor);
        $phase = 0;
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturnUsing(function () use (&$phase) {
            if ($phase++ === 0) return $this->plan(['decision' => 'tools', 'actions' => [$this->action('orders', 'get_orders')]]);
            $this->assertLessThanOrEqual(23, app(\App\Ai\AgentChatModelScope::class)->options([])['timeout']);
            $this->travel(23)->seconds();
            throw new \Illuminate\Http\Client\ConnectionException('Synthetic bounded replan timeout');
        });
        $this->app->instance(AiManager::class, $ai);
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));
        $this->assertSame('partial', $outcome->decision);
        $this->assertSame('time_limit', $outcome->stopReason);
        $this->assertSame('ORDER-42', data_get($outcome->evidence->apiTools(), '0.result.order.id'));
        $this->assertSame(1, $run->toolExecutions()->count());
        $this->assertGreaterThanOrEqual(60, app(\App\Agent\AgentRunDeadline::class)->remaining($run));
        $this->assertArrayNotHasKey('timeout', app(\App\Ai\AgentChatModelScope::class)->options([]));
    }

    private function action(string $id, string $tool, array $dependsOn = []): array
    {
        return ['id' => $id, 'tool' => $tool, 'arguments' => [], 'depends_on' => $dependsOn, 'purpose' => 'Read source'];
    }

    private function plan(array $payload): AiResponse
    {
        return new AiResponse(content: '', provider: 'fake', model: 'fake-agent',
            toolCalls: [['name' => 'submit_agent_plan', 'arguments' => $payload]]);
    }

    private function route(string $slug): void
    {
        $connector = ApiConnector::firstOrCreate(['tenant_id' => 'acme', 'project_key' => 'crm', 'name' => 'ERP'], ['is_active' => true]);
        ApiRoute::create(['tenant_id' => 'acme', 'api_connector_id' => $connector->id, 'project_key' => 'crm',
            'name' => $slug, 'slug' => $slug, 'http_method' => 'GET', 'url' => 'http://erp.example.test/'.$slug,
            'mode' => 'tool', 'status' => 'active', 'endpoint_type' => 'detail',
            'tool_definition' => ['name' => $slug, 'description' => $slug, 'input_schema' => ['type' => 'object', 'properties' => []]]]);
    }

    private function mcpTool(string $name = 'mcp_shipments_12345678'): void
    {
        config(['connector-mcp.enabled' => true, 'connector-mcp.runtime_mode' => 'active']);
        app(TenantContext::class)->set('acme');
        app(ConnectorTenantContext::class)->set('acme');
        $server = McpServerDefinition::create(['tenant_id' => 'acme', 'name' => $name, 'catalog_scope' => 'tenant',
            'transport' => 'auto', 'auth_mode' => 'none', 'endpoint' => 'https://mcp.example.test/mcp',
            'status' => McpServerDefinition::STATUS_ACTIVE]);
        $connection = McpConnection::create(['tenant_id' => 'acme', 'mcp_connector_server_id' => $server->id,
            'mode' => 'shared', 'label' => $name, 'project_key' => 'crm', 'status' => McpConnection::STATUS_ACTIVE]);
        McpConnectionTool::create(['tenant_id' => 'acme', 'mcp_connector_connection_id' => $connection->id,
            'remote_name' => 'shipments', 'local_name' => $name, 'description' => 'Read shipments',
            'input_schema_json' => ['type' => 'object', 'properties' => []],
            'annotations_json' => ['readOnlyHint' => true, 'idempotentHint' => true], 'risk' => 'read',
            'policy' => 'enabled', 'enabled' => true, 'confirmation_required' => false]);
    }

    private function makeRun(): AgentRun
    {
        $user = User::create(['name' => 'Wave tester', 'email' => 'wave@example.test', 'password' => 'password']);
        $conversation = Conversation::create(['tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'crm']);
        return AgentRun::create(['run_id' => Str::uuid()->toString(), 'tenant_id' => 'acme', 'project_key' => 'crm',
            'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => (string) $user->id, 'user_id' => $user->id, 'conversation_id' => $conversation->id, 'locale' => 'it-IT', 'timezone' => 'Europe/Rome',
            'status' => AgentRun::STATUS_RUNNING, 'input_json' => ['question' => 'Controlla ordine e spedizione'],
            'budget_json' => [], 'counters_json' => [], 'started_at' => now(),
            'result_json' => ['question_understanding' => ['available' => true, 'language' => 'it', 'intent' => 'Controlla ordine e spedizione',
                'action' => 'research', 'kb_queries' => [], 'mentions' => [], 'references_previous_turn' => false,
                'context_mode' => 'new', 'requires_research' => true,
                'subquestions' => [
                    ['question' => 'Controlla ordine', 'topic' => 'ordine', 'identifiers' => [], 'aspect' => 'status', 'fields' => []],
                    ['question' => 'Controlla spedizione', 'topic' => 'spedizione', 'identifiers' => [], 'aspect' => 'status', 'fields' => []],
                ]]]]);
    }

    private function context(AgentRun $run): AgentExecutionContext
    {
        return AgentExecutionContext::fromArray(['run_id' => $run->run_id, 'tenant_id' => $run->tenant_id,
            'project_key' => $run->project_key, 'channel' => $run->channel, 'actor_type' => $run->actor_type,
            'actor_id' => $run->actor_id, 'locale' => $run->locale, 'timezone' => $run->timezone]);
    }
}
