<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\Budget\AgentBudgetTracker;
use App\Agent\Tools\AgentToolDefinition;
use App\Models\AgentRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AgentBudgetTrackerTest extends TestCase
{
    use RefreshDatabase;

    public function test_safe_read_only_calls_extend_automatically_after_the_soft_limit(): void
    {
        $run = $this->makeRun(['logical_calls' => 12]);
        $decision = (new AgentBudgetTracker($run))->reserve($this->tool(), ['page' => 1], 1);

        $this->assertTrue($decision->allowed());
        $this->assertTrue($decision->autoExtended);
        $this->assertSame(13, $run->fresh()->counters_json['logical_calls']);
    }

    public function test_unsafe_call_requires_confirmation_at_the_soft_limit(): void
    {
        $run = $this->makeRun(['logical_calls' => 12]);
        $decision = (new AgentBudgetTracker($run))->reserve($this->tool(readOnly: false), [], 1);

        $this->assertTrue($decision->requiresConfirmation());
        $this->assertSame('logical_soft_limit', $decision->reason);
    }

    public function test_bulk_collection_uses_one_logical_call_but_reserves_physical_capacity(): void
    {
        $run = $this->makeRun();
        $tracker = new AgentBudgetTracker($run);
        $decision = $tracker->reserve($this->tool(physicalMaximum: 50), ['customer_id' => 7], 50);
        $tracker->recordResult(50, 1024, true);

        $this->assertTrue($decision->allowed());
        $this->assertSame(1, $tracker->snapshot()['logical_calls']);
        $this->assertSame(50, $tracker->snapshot()['physical_calls']);
    }

    public function test_hard_limit_requests_a_bounded_confirmation_for_safe_tools(): void
    {
        $run = $this->makeRun(['logical_calls' => 25, 'auto_extended' => true]);
        $decision = (new AgentBudgetTracker($run))->reserve($this->tool(), [], 1);

        $this->assertTrue($decision->requiresConfirmation());
        $this->assertSame('logical_hard_limit', $decision->reason);
    }

    public function test_third_identical_call_and_third_consecutive_error_are_stopped(): void
    {
        $run = $this->makeRun();
        $tracker = new AgentBudgetTracker($run);
        $this->assertTrue($tracker->reserve($this->tool(), ['id' => 7])->allowed());
        $this->assertTrue($tracker->reserve($this->tool(), ['id' => 7])->allowed());
        $this->assertSame('duplicate_call_limit', $tracker->reserve($this->tool(), ['id' => 7])->reason);

        $tracker->recordResult(1, 1, false);
        $tracker->recordResult(1, 1, false);
        $tracker->recordResult(1, 1, false);
        $this->assertSame('consecutive_error_limit', $tracker->reserve($this->tool(), ['id' => 8])->reason);
    }

    public function test_depth_3_is_the_unscaled_default_when_no_depth_was_set(): void
    {
        // Reach the DEFAULT (unscaled) logical_soft limit of 12 exactly —
        // a run with no `depth` in input_json must behave byte-identically
        // to before this feature existed.
        $run = $this->makeRun(['logical_calls' => 12]);
        $decision = (new AgentBudgetTracker($run))->reserve($this->tool(), [], 1);

        $this->assertTrue($decision->allowed());
        $this->assertTrue($decision->autoExtended);
    }

    public function test_depth_1_halves_the_logical_soft_limit(): void
    {
        config()->set('agent.depth.multipliers', [1 => 0.5, 2 => 0.75, 3 => 1.0, 4 => 2.0, 5 => 3.0]);
        // Default logical_soft is 12; depth 1 -> round(12 * 0.5) = 6. A call
        // that would push logical_calls to 7 must already require
        // confirmation for an unsafe tool, where depth 3 (default) would not.
        $run = $this->makeRun(['logical_calls' => 6], depth: 1);
        $decision = (new AgentBudgetTracker($run))->reserve($this->tool(readOnly: false), [], 1);

        $this->assertTrue($decision->requiresConfirmation());
        $this->assertSame('logical_soft_limit', $decision->reason);
    }

    public function test_depth_5_triples_the_iteration_limit(): void
    {
        config()->set('agent.depth.multipliers', [1 => 0.5, 2 => 0.75, 3 => 1.0, 4 => 2.0, 5 => 3.0]);
        // Default iterations is 8; depth 5 -> round(8 * 3.0) = 24. A run
        // that already ran 20 iterations must still be ALLOWED to begin a
        // 21st, where the unscaled default (8) would already have stopped
        // it 12 iterations earlier.
        $run = $this->makeRun(['iterations' => 20], depth: 5);
        $decision = (new AgentBudgetTracker($run))->beginIteration();

        $this->assertTrue($decision->allowed());
    }

    public function test_depth_never_scales_the_loop_safety_guards(): void
    {
        config()->set('agent.depth.multipliers', [1 => 0.5, 2 => 0.75, 3 => 1.0, 4 => 2.0, 5 => 3.0]);
        // consecutive_errors / duplicate_calls must stop the loop at the
        // SAME thresholds regardless of depth — they guard against a
        // runaway loop, not against "not enough depth".
        $run = $this->makeRun(depth: 5);
        $tracker = new AgentBudgetTracker($run);
        $this->assertTrue($tracker->reserve($this->tool(), ['id' => 7])->allowed());
        $this->assertTrue($tracker->reserve($this->tool(), ['id' => 7])->allowed());
        $this->assertSame('duplicate_call_limit', $tracker->reserve($this->tool(), ['id' => 7])->reason);
    }

    public function test_an_out_of_range_depth_is_clamped_to_1_to_5(): void
    {
        config()->set('agent.depth.multipliers', [1 => 0.5, 2 => 0.75, 3 => 1.0, 4 => 2.0, 5 => 3.0]);

        // Clamped to depth 5 (3.0x) -> logical_soft = round(12 * 3.0) = 36.
        $run = $this->makeRun(['logical_calls' => 30], depth: 99);
        $decision = (new AgentBudgetTracker($run))->reserve($this->tool(readOnly: false), [], 1);

        $this->assertTrue($decision->allowed());
    }

    public function test_knowledge_tool_stops_after_the_configured_run_of_unproductive_searches(): void
    {
        config()->set('agent.limits.consecutive_unproductive_searches', 3);
        $run = $this->makeRun(['consecutive_unproductive_searches' => 3]);

        $decision = (new AgentBudgetTracker($run))->reserve($this->knowledgeTool(), ['query' => 'anything']);

        $this->assertFalse($decision->allowed());
        $this->assertSame('unproductive_search_limit', $decision->reason);
    }

    public function test_the_unproductive_search_brake_does_not_block_other_tool_kinds(): void
    {
        config()->set('agent.limits.consecutive_unproductive_searches', 3);
        $run = $this->makeRun(['consecutive_unproductive_searches' => 3]);

        // Same run, same exhausted counter, but a DIFFERENT (api) tool — the
        // planner pivoting away from knowledge search must still be allowed.
        $decision = (new AgentBudgetTracker($run))->reserve($this->tool(), []);

        $this->assertTrue($decision->allowed());
    }

    public function test_recording_a_productive_search_resets_the_unproductive_counter(): void
    {
        $run = $this->makeRun(['consecutive_unproductive_searches' => 2]);
        $tracker = new AgentBudgetTracker($run);

        $tracker->recordKnowledgeSearchResult(true);

        $this->assertSame(0, $tracker->snapshot()['consecutive_unproductive_searches']);
    }

    public function test_recording_an_unproductive_search_increments_the_counter(): void
    {
        $run = $this->makeRun(['consecutive_unproductive_searches' => 1]);
        $tracker = new AgentBudgetTracker($run);

        $tracker->recordKnowledgeSearchResult(false);

        $this->assertSame(2, $tracker->snapshot()['consecutive_unproductive_searches']);
    }

    public function test_unproductive_search_limit_is_not_scaled_by_depth(): void
    {
        config()->set('agent.depth.multipliers', [1 => 0.5, 2 => 0.75, 3 => 1.0, 4 => 2.0, 5 => 3.0]);
        config()->set('agent.limits.consecutive_unproductive_searches', 3);
        // Depth 5 (3.0x) scales iterations/logical/physical/time/evidence —
        // it must NOT also raise this threshold to 9.
        $run = $this->makeRun(['consecutive_unproductive_searches' => 3], depth: 5);

        $decision = (new AgentBudgetTracker($run))->reserve($this->knowledgeTool(), ['query' => 'anything']);

        $this->assertFalse($decision->allowed());
        $this->assertSame('unproductive_search_limit', $decision->reason);
    }

    /** @param array<string,mixed> $counters */
    private function makeRun(array $counters = [], ?int $depth = null): AgentRun
    {
        return AgentRun::create([
            'run_id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => 'test-tenant',
            'channel' => 'chat',
            'actor_type' => 'user',
            'locale' => 'it-IT',
            'timezone' => 'Europe/Rome',
            'status' => AgentRun::STATUS_RUNNING,
            'started_at' => now(),
            'input_json' => $depth === null ? [] : ['depth' => $depth],
            'counters_json' => $counters,
            'budget_json' => [],
        ]);
    }

    private function tool(bool $readOnly = true, int $physicalMaximum = 1): AgentToolDefinition
    {
        return new AgentToolDefinition(
            name: 'get_orders',
            displayName: 'Orders',
            description: 'Orders',
            kind: 'api',
            inputSchema: ['type' => 'object'],
            readOnly: $readOnly,
            idempotent: $readOnly,
            physicalMinimum: 1,
            physicalLikely: 1,
            physicalMaximum: $physicalMaximum,
        );
    }

    private function knowledgeTool(): AgentToolDefinition
    {
        return new AgentToolDefinition(
            name: 'search_knowledge_base',
            displayName: 'Knowledge base',
            description: 'Search',
            kind: 'knowledge',
            inputSchema: ['type' => 'object'],
            readOnly: true,
            idempotent: true,
            physicalMinimum: 0,
            physicalLikely: 0,
            physicalMaximum: 0,
        );
    }
}
