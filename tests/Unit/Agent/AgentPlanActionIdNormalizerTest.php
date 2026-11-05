<?php

namespace Tests\Unit\Agent;

use App\Agent\Planning\AgentPlanActionIdNormalizer;
use PHPUnit\Framework\TestCase;

class AgentPlanActionIdNormalizerTest extends TestCase
{
    private function plan(array $actions): array
    {
        return ['decision' => 'tools', 'actions' => $actions];
    }

    private function action(string $id, array $dependencies = [], array $arguments = []): array
    {
        return ['id' => $id, 'tool' => 'get_returns', 'purpose' => 'Read the requested return',
            'depends_on' => $dependencies, 'arguments' => $arguments];
    }

    public function test_only_unreferenced_reused_keys_are_renamed_without_changing_the_operation(): void
    {
        $plan = $this->plan([$this->action('step_1', [], ['id' => 'RETURN-A']), $this->action('step_2')]);
        $actual = (new AgentPlanActionIdNormalizer)->normalize($plan, ['step_1', 'step_2']);
        $this->assertSame(['step_3', 'step_4'], array_column($actual['actions'], 'id'));
        foreach ($actual['actions'] as $i => $action) {
            $this->assertSame(array_diff_key($plan['actions'][$i], ['id' => true]), array_diff_key($action, ['id' => true]));
        }
    }

    public function test_ambiguous_dependencies_and_nested_references_are_left_for_strict_rejection(): void
    {
        foreach ([$this->action('new', ['step_1']), $this->action('new', [], ['id' => ['$from' => 'step_1', 'path' => 'id']])] as $dependent) {
            $plan = $this->plan([$this->action('step_1'), $dependent]);
            $this->assertSame($plan, (new AgentPlanActionIdNormalizer)->normalize($plan, ['step_1']));
        }
    }

    public function test_duplicate_new_keys_and_invalid_keys_are_never_salvaged(): void
    {
        foreach ([[$this->action('step_1'), $this->action('step_1')], [$this->action('invalid key')]] as $actions) {
            $plan = $this->plan($actions);
            $this->assertSame($plan, (new AgentPlanActionIdNormalizer)->normalize($plan, ['step_1']));
        }
    }

    public function test_references_to_distinct_completed_actions_are_preserved(): void
    {
        $plan = $this->plan([$this->action('step_1', ['previous'], ['id' => ['$from' => 'previous', 'path' => 'id']])]);
        $actual = (new AgentPlanActionIdNormalizer)->normalize($plan, ['step_1', 'previous']);
        $this->assertSame('step_2', $actual['actions'][0]['id']);
        $this->assertSame($plan['actions'][0]['arguments'], $actual['actions'][0]['arguments']);
        $this->assertSame(['previous'], $actual['actions'][0]['depends_on']);
    }
}
