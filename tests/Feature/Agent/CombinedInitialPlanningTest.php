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
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Tests\TestCase;

final class CombinedInitialPlanningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.waves.enabled' => true, 'agent.waves.combined_initial_planning' => true,
            'kb.investigation.enabled' => false, 'reasoning.enabled' => true,
            'reasoning.selective_validation' => false]);
    }

    public function test_combined_interpretation_dispatches_before_replanning_and_replans_after_the_wave(): void
    {
        $this->route();
        $run = $this->runFixture();
        $phase = 'before_interpretation';
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturnUsing(function () use (&$phase) {
            $this->assertSame('before_interpretation', $phase);
            $phase = 'interpreted';
            return $this->interpretation($this->action('get_orders'));
        });
        $ai->shouldReceive('chatWithHistory')->once()->andReturnUsing(function ($system, $messages) use (&$phase) {
            $this->assertSame('dispatched', $phase, 'The first tool wave must precede the ordinary planner call.');
            $input = json_decode($messages[0]['content'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['orders'], array_column($input['completed_actions'], 'id'));
            $phase = 'replanned';
            return $this->plan(['decision' => 'answer', 'actions' => []]);
        });
        $this->app->instance(AiManager::class, $ai);
        $executor = Mockery::mock(AgentWaveExecutor::class);
        $executor->shouldReceive('run')->once()->andReturnUsing(function (array $tasks) use (&$phase) {
            $this->assertSame('interpreted', $phase);
            $this->assertSame(['orders'], array_keys($tasks));
            $phase = 'dispatched';
            return ['orders' => new AgentToolActionResult(['order' => ['id' => 'ORDER-42']], 1)];
        });
        $this->app->instance(AgentWaveExecutor::class, $executor);

        $outcome = app(AgentLoop::class)->run($run, AgentExecutionContext::fromArray($run->toArray()));
        $this->assertSame('answer', $outcome->decision, (string) $outcome->stopReason);
        $this->assertSame('replanned', $phase);
        $this->assertSame(['orders'], array_column($outcome->completedActions, 'id'));
        $this->assertNotEmpty(data_get($run->fresh()->result_json, 'initial_plan.capability_hash'));
        $this->assertNull(data_get($run->fresh()->result_json, 'question_understanding.initial_plan'));
    }

    public function test_unknown_proposed_tool_falls_back_to_ordinary_planner_before_dispatch(): void
    {
        $this->assertFallbackFor($this->action('revoked_tool'));
    }

    public function test_malformed_arguments_fall_back_to_ordinary_planner_before_dispatch(): void
    {
        $action = $this->action('get_orders');
        $action['arguments_json'] = '[1,2]';
        $this->assertFallbackFor($action);
    }

    public function test_unobserved_identifier_is_rejected_and_repaired_before_dispatch(): void
    {
        $action = $this->action('get_orders');
        $action['arguments_json'] = '{"id":"ORDER-99"}';
        $this->assertFallbackFor($action, identifierSchema: true, reason: 'input_pattern_mismatch');
    }

    public function test_exhaustive_request_rejects_kb_only_proposal_and_repairs_with_live_api(): void
    {
        $action = ['id' => 'kb', 'tool' => 'search_knowledge_base', 'arguments_json' => '{"query":"ORDER-42"}',
            'depends_on' => [], 'purpose' => 'Search documents'];
        $this->assertFallbackFor($action, exhaustive: true, reason: 'live_source_coverage_required');
    }

    public function test_valid_proposal_can_be_consumed_only_once(): void
    {
        $run = $this->runFixture();
        $run->forceFill(['result_json' => [
            'question_understanding' => ['available' => true, 'intent' => 'Controlla ordine ORDER-42',
                'action' => 'research', 'requires_research' => true],
            'initial_plan' => ['proposal' => ['decision' => 'tools', 'actions' => [$this->action('get_orders')]],
                'capability_hash' => 'current-hash', 'status' => 'pending'],
        ]])->save();
        $tool = new \App\Agent\Tools\AgentToolDefinition('get_orders', 'Orders', 'Read orders', 'api',
            ['type' => 'object', 'properties' => []], true, true, 1, 1, 1);
        $context = AgentExecutionContext::fromArray($run->toArray());
        $snapshot = new \App\Agent\Capabilities\AgentCapabilitySnapshot([], 'current-hash', 0);
        $evidence = app(\App\Agent\Evidence\AgentEvidenceFactory::class)->empty();
        $planner = app(\App\Agent\Planning\AgentInitialPlanning::class);
        $this->assertNotNull($planner->consume($run, $context, ['get_orders' => $tool], $snapshot, $evidence));
        $this->assertSame('consumed', data_get($run->fresh()->result_json, 'initial_plan.status'));
        $this->assertNull($planner->consume($run, $context, ['get_orders' => $tool], $snapshot, $evidence));
    }

    public function test_stale_capability_hash_discards_the_proposal(): void
    {
        $run = $this->runFixture();
        $run->forceFill(['result_json' => [
            'question_understanding' => ['available' => true, 'intent' => 'Controlla ordine ORDER-42',
                'action' => 'research', 'requires_research' => true],
            'initial_plan' => ['proposal' => ['decision' => 'tools', 'actions' => [$this->action('get_orders')]],
                'capability_hash' => 'old-hash', 'status' => 'pending'],
        ]])->save();
        $context = AgentExecutionContext::fromArray($run->toArray());
        $snapshot = new \App\Agent\Capabilities\AgentCapabilitySnapshot([], 'new-hash', 0);
        $plan = app(\App\Agent\Planning\AgentInitialPlanning::class)->consume($run, $context, [], $snapshot,
            app(\App\Agent\Evidence\AgentEvidenceFactory::class)->empty());
        $this->assertNull($plan);
        $this->assertSame('discarded', data_get($run->fresh()->result_json, 'initial_plan.status'));
    }

    public function test_non_research_and_ambiguous_turns_cannot_consume_a_proposal(): void
    {
        $run = $this->runFixture();
        $context = AgentExecutionContext::fromArray($run->toArray());
        $snapshot = new \App\Agent\Capabilities\AgentCapabilitySnapshot([], 'current-hash', 0);
        foreach ([
            ['available' => true, 'action' => 'greeting', 'requires_research' => false],
            ['available' => true, 'action' => 'recap', 'requires_research' => false],
            ['available' => true, 'action' => 'research', 'requires_research' => true, 'needs_clarification' => true],
        ] as $understanding) {
            $run->forceFill(['result_json' => [
                'question_understanding' => $understanding + ['intent' => 'Controlla ordine ORDER-42'],
                'initial_plan' => ['proposal' => ['decision' => 'tools', 'actions' => [$this->action('get_orders')]],
                    'capability_hash' => 'current-hash', 'status' => 'pending'],
            ]])->save();
            $plan = app(\App\Agent\Planning\AgentInitialPlanning::class)->consume($run, $context, [], $snapshot,
                app(\App\Agent\Evidence\AgentEvidenceFactory::class)->empty());
            $this->assertNull($plan);
            $this->assertSame('discarded', data_get($run->fresh()->result_json, 'initial_plan.status'));
        }
    }

    private function assertFallbackFor(array $proposedAction, bool $identifierSchema = false, bool $exhaustive = false, ?string $reason = null): void
    {
        $this->route($identifierSchema);
        $run = $this->runFixture($exhaustive ? 'Cerca tutte le informazioni su ORDER-42' : 'Controlla ordine ORDER-42');
        $phase = 'initial';
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->interpretation($proposedAction));
        $ai->shouldReceive('chatWithHistory')->twice()->andReturnUsing(function () use (&$phase, $run, $reason, $identifierSchema) {
            if ($phase === 'initial') {
                if ($reason !== null) {
                    $this->assertSame('rejected', data_get($run->fresh()->result_json, 'initial_plan.status'));
                    $this->assertSame($reason, data_get($run->fresh()->result_json, 'initial_plan.reason'));
                }
                $phase = 'repaired';
                $action = $this->action('get_orders');
                $action['arguments'] = $identifierSchema ? ['id' => 'ORDER-42'] : json_decode($action['arguments_json'], true, flags: JSON_THROW_ON_ERROR);
                unset($action['arguments_json']);
                return $this->plan(['decision' => 'tools', 'actions' => [$action]]);
            }
            $this->assertSame('dispatched', $phase);
            return $this->plan(['decision' => 'answer', 'actions' => []]);
        });
        $this->app->instance(AiManager::class, $ai);
        $executor = Mockery::mock(AgentWaveExecutor::class);
        $executor->shouldReceive('run')->once()->andReturnUsing(function (array $tasks) use (&$phase) {
            $this->assertSame('repaired', $phase);
            $phase = 'dispatched';
            return ['orders' => new AgentToolActionResult(['order' => ['id' => 'ORDER-42']], 1)];
        });
        $this->app->instance(AgentWaveExecutor::class, $executor);
        $outcome = app(AgentLoop::class)->run($run, AgentExecutionContext::fromArray($run->toArray()));
        $this->assertSame('answer', $outcome->decision, (string) $outcome->stopReason);
        $this->assertSame(['get_orders'], array_column($outcome->completedActions, 'tool'));
    }

    private function action(string $tool): array
    {
        return ['id' => 'orders', 'tool' => $tool, 'arguments_json' => '{}', 'depends_on' => [], 'purpose' => 'Read the order'];
    }

    private function interpretation(array $action): AiResponse
    {
        $question = 'Controlla ordine ORDER-42';
        $focus = ['topic' => 'ordine', 'identifiers' => ['ORDER-42'], 'aspect' => 'status', 'fields' => []];
        return new AiResponse(json_encode(['language' => 'it', 'intent' => $question, 'action' => 'research',
            'kb_queries' => ['ORDER-42'], 'mentions' => [['text' => 'ORDER-42', 'type' => 'identifier']],
            'references_previous_turn' => false, 'focus' => $focus,
            'subquestions' => [$focus + ['question' => $question, 'kb_queries' => ['ORDER-42'], 'context_mode' => 'new', 'requires_research' => true]],
            'context_mode' => 'new', 'requires_research' => true, 'resolved_references' => [], 'transition' => 'new',
            'needs_clarification' => false, 'clarification' => '',
            'initial_plan' => ['decision' => 'tools', 'actions' => [$action]]], JSON_THROW_ON_ERROR), 'fake', 'interpreter');
    }

    private function plan(array $payload): AiResponse
    {
        return new AiResponse('', 'fake', 'planner', toolCalls: [['name' => 'submit_agent_plan', 'arguments' => $payload]]);
    }

    private function route(bool $identifierSchema = false): void
    {
        $connector = ApiConnector::create(['tenant_id' => 'acme', 'project_key' => 'crm', 'name' => 'ERP', 'is_active' => true]);
        ApiRoute::create(['tenant_id' => 'acme', 'api_connector_id' => $connector->id, 'project_key' => 'crm',
            'name' => 'get_orders', 'slug' => 'get_orders', 'http_method' => 'GET', 'url' => 'http://erp.example.test/orders',
            'mode' => 'tool', 'status' => 'active', 'endpoint_type' => 'detail',
            'tool_definition' => ['name' => 'get_orders', 'description' => 'Read order', 'input_schema' => ['type' => 'object', 'properties' => $identifierSchema
                ? ['id' => ['type' => 'string', 'pattern' => '^ORDER-[0-9]+$', 'x-require-observed-identifier' => true]] : []]]]);
    }

    private function runFixture(string $question = 'Controlla ordine ORDER-42'): AgentRun
    {
        app(\App\Support\TenantContext::class)->set('acme');
        $user = User::create(['name' => 'Tester', 'email' => 'combined@example.test', 'password' => 'password']);
        ProjectMembership::create(['tenant_id' => 'acme', 'project_key' => 'crm', 'user_id' => $user->id, 'role' => 'member']);
        $conversation = Conversation::create(['tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'crm']);
        $message = $conversation->messages()->create(['role' => 'user', 'content' => $question]);
        return AgentRun::create(['run_id' => Str::uuid()->toString(), 'tenant_id' => 'acme', 'project_key' => 'crm',
            'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => (string) $user->id, 'user_id' => $user->id,
            'conversation_id' => $conversation->id, 'locale' => 'it-IT', 'timezone' => 'Europe/Rome',
            'status' => AgentRun::STATUS_RUNNING, 'input_json' => ['question' => $message->content, 'user_message_id' => $message->id],
            'budget_json' => [], 'counters_json' => [], 'result_json' => [], 'started_at' => now()]);
    }
}
