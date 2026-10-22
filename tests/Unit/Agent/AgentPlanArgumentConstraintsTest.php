<?php

namespace Tests\Unit\Agent;

use App\Agent\Planning\AgentPlanArgumentConstraints;
use App\Agent\Planning\AgentPlanValidationException;
use App\Agent\Tools\AgentToolDefinition;
use PHPUnit\Framework\TestCase;

class AgentPlanArgumentConstraintsTest extends TestCase
{
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
}
