<?php

namespace Tests\Feature\Chat;

use App\Agent\Grounding\DecisionOnce;
use App\Decisions\DecisionResult;
use App\Models\AgentRun;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DecisionOnceTest extends TestCase
{
    use RefreshDatabase;

    private function runFixture(): AgentRun
    {
        app(TenantContext::class)->set('default');
        return AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'default', 'project_key' => 'demo',
            'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => '1', 'locale' => 'it', 'timezone' => 'UTC',
            'status' => 'running', 'input_json' => ['question' => 'Test']]);
    }

    public function test_retry_uses_persisted_decision_without_a_second_call(): void
    {
        $run = $this->runFixture();
        $calls = 0;
        $call = function () use (&$calls) {
            $calls++;
            return new DecisionResult('id', 'test', ['claim_0' => ['type' => 'choice']], ['cost' => .01], 12.0);
        };
        $service = new DecisionOnce;
        $one = $service->run($run->run_id, 'signature', $call);
        $two = $service->run($run->run_id, 'signature', $call);
        $this->assertSame(1, $calls);
        $this->assertSame($one->answers, $two->answers);
        $this->assertSame('completed', $run->fresh()->result_json['focused_decision']['status']);
    }

    public function test_timeout_does_not_trigger_an_automatic_second_billable_request(): void
    {
        $run = $this->runFixture();
        $service = new DecisionOnce;
        try {
            $service->run($run->run_id, 'signature', fn () => throw new \RuntimeException('timeout'));
        } catch (\RuntimeException) {
        }
        $this->expectExceptionMessage('already dispatched');
        $service->run($run->run_id, 'signature', fn () => throw new \LogicException('Must not call again'));
    }

    public function test_stale_collection_checkpoint_cannot_erase_the_dispatch_marker(): void
    {
        $run = $this->runFixture();
        $calls = 0;
        $call = function () use (&$calls) {
            $calls++;
            return new DecisionResult('id', 'test', [], [], 1.0);
        };
        $service = new DecisionOnce;
        $service->run($run->run_id, 'signature', $call);
        // $run is intentionally stale: its in-memory JSON predates the decision.
        (new \ReflectionMethod(\App\Agent\AgentLoop::class, 'checkpoint'))->invoke(app(\App\Agent\AgentLoop::class),
            $run, app(\App\Agent\Evidence\AgentEvidenceFactory::class)->empty(), [], [], true);
        $service->run($run->run_id, 'signature', $call);
        $this->assertSame(1, $calls);
        $this->assertSame('completed', $run->fresh()->result_json['focused_decision']['status']);
    }
}
