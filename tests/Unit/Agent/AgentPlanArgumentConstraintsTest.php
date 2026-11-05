<?php

namespace Tests\Unit\Agent;

use App\Agent\Planning\AgentPlanArgumentConstraints;
use App\Agent\Planning\AgentPlanValidationException;
use App\Agent\Tools\AgentToolDefinition;
use PHPUnit\Framework\TestCase;

class AgentPlanArgumentConstraintsTest extends TestCase
{
    public function test_only_invalid_branches_and_their_dependents_are_rejected_without_changing_values(): void
    {
        $tool = $this->tool();
        $action = fn ($id, $value, $dependencies = []) => new \App\Agent\Planning\AgentPlannedAction(
            $id, 'orders', ['reference' => $value], $dependencies, 'Read the record');
        $plan = new \App\Agent\Planning\AgentPlan('tools', [
            $action('valid', 'ORDER-42'), $action('invalid', 'RETURN-42'),
            $action('dependent', ['$from' => 'invalid', 'path' => 'reference'], ['invalid']),
            $action('transitive', ['$from' => 'dependent', 'path' => 'reference'], ['dependent']),
            $action('implicit', ['$from' => 'invalid', 'path' => 'reference']),
            $action('other', 'ORDER-43'),
        ], new \App\Agent\AgentProgress);
        $safe = (new AgentPlanArgumentConstraints)->independentValidBranches($plan, ['orders' => $tool]);
        $this->assertSame(['valid', 'other'], array_column($safe->actions, 'id'));
        $this->assertSame(['invalid', 'dependent', 'transitive', 'implicit'], array_column($safe->rejectedActions, 'id'));
        $this->assertSame('RETURN-42', $safe->rejectedActions[0]->arguments['reference']);
        $this->assertSame(2, $safe->estimate->physicalLikely);
        $this->assertSame(['reference' => 'ORDER-42'], $safe->actions[0]->arguments);
    }

    public function test_no_valid_branch_does_not_become_an_answer_plan(): void
    {
        $plan = new \App\Agent\Planning\AgentPlan('tools', [new \App\Agent\Planning\AgentPlannedAction(
            'invalid', 'orders', ['reference' => 'RETURN-42'], [], 'Read return')], new \App\Agent\AgentProgress);
        $this->expectException(AgentPlanValidationException::class);
        (new AgentPlanArgumentConstraints)->independentValidBranches($plan, ['orders' => $this->tool()]);
    }

    private function tool(string $kind = 'api'): AgentToolDefinition
    {
        return new AgentToolDefinition(name: 'orders', displayName: 'Orders', description: 'Read orders', kind: $kind,
            inputSchema: ['type' => 'object', 'properties' => ['reference' => ['type' => 'string', 'pattern' => '^ORDER-[0-9]+$']]],
            readOnly: true, idempotent: true, physicalMinimum: 1, physicalLikely: 1, physicalMaximum: 1);
    }

    public function test_valid_values_and_absent_optional_arguments_are_preserved(): void
    {
        $guard = new AgentPlanArgumentConstraints;
        $arguments = ['reference' => 'ORDER-42'];
        $guard->validateArguments($this->tool(), $arguments);
        $guard->validateArguments($this->tool(), []);
        $this->assertSame(['reference' => 'ORDER-42'], $arguments);
    }

    public function test_a_wrong_entity_namespace_is_rejected_without_rewriting_it(): void
    {
        $this->expectException(AgentPlanValidationException::class);
        $this->expectExceptionMessage('Select the matching tool; never rewrite an entity identifier');
        (new AgentPlanArgumentConstraints)->validateArguments($this->tool(), ['reference' => 'RETURN-42']);
    }

    public function test_planned_references_are_deferred_but_resolved_values_must_match(): void
    {
        $guard = new AgentPlanArgumentConstraints;
        $guard->validateArguments($this->tool(), ['reference' => ['$from' => 'step_1', 'path' => 'id']], allowReferences: true);
        $this->expectException(AgentPlanValidationException::class);
        $guard->validateArguments($this->tool(), ['reference' => 'RETURN-42']);
    }

    public function test_literal_patterns_are_not_applied_to_other_tool_kinds(): void
    {
        (new AgentPlanArgumentConstraints)->validateArguments($this->tool('mcp'), ['reference' => 'RETURN-42']);
        $this->addToAssertionCount(1);
    }

    public function test_a_valid_format_does_not_authorize_a_derived_identifier(): void
    {
        $tool = $this->observedTool();
        $this->expectException(AgentPlanValidationException::class);
        $this->expectExceptionMessage('was not observed');
        (new AgentPlanArgumentConstraints)->validateArguments($tool, ['reference' => 'ORDER-43'],
            identifierSources: ['Read ORDER-42 and RETURN-43.']);
    }

    public function test_observed_identifiers_are_exact_tokens_and_never_prefixes(): void
    {
        $this->assertFalse(\App\Agent\Planning\AgentIdentifierSources::contains('U123', ['RU123 U123_1 U1234 U123-1']));
        $this->assertTrue(\App\Agent\Planning\AgentIdentifierSources::contains('U123', ['Order `U123`, please.']));
        (new AgentPlanArgumentConstraints)->validateArguments($this->observedTool(), ['reference' => 'ORDER-42'],
            identifierSources: ['The selected source says ORDER-42.']);
    }

    public function test_reference_resolution_still_requires_observed_values(): void
    {
        $guard = new AgentPlanArgumentConstraints;
        $guard->validateArguments($this->observedTool(), ['reference' => ['$from' => 'result', 'path' => 'reference']], allowReferences: true);
        $this->expectException(AgentPlanValidationException::class);
        $guard->validateArguments($this->observedTool(), ['reference' => 'ORDER-43'], identifierSources: ['ORDER-42']);
    }

    private function observedTool(): AgentToolDefinition
    {
        return new AgentToolDefinition(name: 'orders', displayName: 'Orders', description: 'Read orders', kind: 'api',
            inputSchema: ['type' => 'object', 'properties' => ['reference' => ['type' => 'string', 'pattern' => '^ORDER-[0-9]+$', 'x-require-observed-identifier' => true]]],
            readOnly: true, idempotent: true, physicalMinimum: 1, physicalLikely: 1, physicalMaximum: 1);
    }
}
