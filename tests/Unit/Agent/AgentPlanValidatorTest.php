<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\AgentProgress;
use App\Agent\Capabilities\AgentCapabilityDefinition;
use App\Agent\Capabilities\AgentCapabilityRoute;
use App\Agent\Capabilities\AgentCapabilitySnapshot;
use App\Agent\Planning\AgentPlan;
use App\Agent\Planning\AgentPlanValidationException;
use App\Agent\Planning\AgentPlanValidator;
use App\Agent\Planning\AgentPlannedAction;
use App\Agent\Tools\AgentToolDefinition;
use PHPUnit\Framework\TestCase;

final class AgentPlanValidatorTest extends TestCase
{
    public function test_it_validates_arguments_and_declared_dependency_paths(): void
    {
        $tools = $this->tools();
        $snapshot = $this->snapshot($tools);
        $plan = new AgentPlan('tools', [
            new AgentPlannedAction('customers', 'search_customers', ['query' => 'Riccardo'], [], 'Cerco il cliente'),
            new AgentPlannedAction('orders', 'list_orders', [
                'customer_id' => ['$from' => 'customers', 'path' => 'items.0.id'],
            ], ['customers'], 'Carico gli ordini'),
        ], new AgentProgress(logicalMinimum: 2, logicalLikely: 2, logicalMaximum: 2));

        (new AgentPlanValidator)->validate(
            $plan,
            $tools,
            $snapshot,
            new AgentCapabilityRoute(true, 'orders', 'list', array_keys($tools), []),
            [],
            [],
        );

        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_premature_insufficient_when_a_candidate_is_untried(): void
    {
        $tools = $this->tools();
        $this->expectException(AgentPlanValidationException::class);
        $this->expectExceptionMessage('remain untried');

        (new AgentPlanValidator)->validate(
            new AgentPlan('insufficient', [], new AgentProgress),
            $tools,
            $this->snapshot($tools),
            new AgentCapabilityRoute(true, 'orders', 'list', ['list_orders'], []),
            [],
            [],
        );
    }

    public function test_it_rejects_an_undeclared_speculative_result_path(): void
    {
        $tools = $this->tools();
        $plan = new AgentPlan('tools', [
            new AgentPlannedAction('customers', 'search_customers', ['query' => 'Riccardo'], [], 'Cerco il cliente'),
            new AgentPlannedAction('orders', 'list_orders', [
                'customer_id' => ['$from' => 'customers', 'path' => 'data.first.magic_id'],
            ], ['customers'], 'Carico gli ordini'),
        ], new AgentProgress(logicalMinimum: 2, logicalLikely: 2, logicalMaximum: 2));

        try {
            (new AgentPlanValidator)->validate(
                $plan,
                $tools,
                $this->snapshot($tools),
                new AgentCapabilityRoute(true, 'orders', 'list', array_keys($tools), []),
                [],
                [],
            );
            $this->fail('Expected speculative path rejection.');
        } catch (AgentPlanValidationException $exception) {
            $this->assertSame('speculative_reference_path', $exception->validationCode);
        }
    }

    /** @return array<string,AgentToolDefinition> */
    private function tools(): array
    {
        return [
            'search_customers' => new AgentToolDefinition(
                'search_customers', 'Search customers', 'Remote description', 'api',
                ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'], 'additionalProperties' => false],
                true, true, 1, 1, 1, metadata: ['output_schema' => [
                    'type' => 'object',
                    'properties' => ['items' => ['type' => 'array', 'items' => [
                        'type' => 'object', 'properties' => ['id' => ['type' => 'integer']],
                    ]]],
                ]],
            ),
            'list_orders' => new AgentToolDefinition(
                'list_orders', 'List orders', 'Remote description', 'api',
                ['type' => 'object', 'properties' => ['customer_id' => ['type' => 'integer']], 'required' => ['customer_id'], 'additionalProperties' => false],
                true, true, 1, 1, 1,
            ),
        ];
    }

    /** @param array<string,AgentToolDefinition> $tools */
    private function snapshot(array $tools): AgentCapabilitySnapshot
    {
        $capabilities = [];
        foreach ($tools as $tool) {
            $capabilities[$tool->name] = new AgentCapabilityDefinition(
                $tool->name, 'api', 'data', 'get', [], null, [], [], [], true, true, false, 'read',
                is_array($tool->metadata['output_schema'] ?? null) ? $tool->metadata['output_schema'] : null,
                'standard',
            );
        }

        return new AgentCapabilitySnapshot($capabilities, str_repeat('a', 64), 100);
    }
}
