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

    /** @param array<string,mixed> $counters */
    private function makeRun(array $counters = []): AgentRun
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
}
