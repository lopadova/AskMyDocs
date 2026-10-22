<?php

namespace Tests\Unit\Decisions;

use App\Agent\Grounding\DecisionOnce;
use App\Decisions\DecisionResult;
use App\Models\AgentRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndependentDecisionMemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_durable_replay_keeps_item_errors_without_reissuing_the_decision(): void
    {
        $run = AgentRun::create(['run_id' => 'partial-decision', 'tenant_id' => 'default', 'project_key' => 'demo',
            'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => '1', 'locale' => 'en', 'timezone' => 'UTC',
            'status' => 'running', 'input_json' => []]);
        $decision = new DecisionResult('decision-1', 'jev-test', ['good' => ['type' => 'noul', 'probability_true' => .95]],
            [], 1, ['bad' => 'Invalid choice distribution.']);
        $once = app(DecisionOnce::class);
        $once->run($run->run_id, 'same-sources', fn () => $decision);
        $cached = $once->run($run->run_id, 'same-sources', fn () => throw new \LogicException('Must not redispatch.'));
        $this->assertSame($decision->answers, $cached->answers);
        $this->assertSame($decision->answerErrors, $cached->answerErrors);
        $this->assertTrue($cached->toBool('good', .8));
        $this->assertArrayNotHasKey('bad', $cached->answers);
    }
}
