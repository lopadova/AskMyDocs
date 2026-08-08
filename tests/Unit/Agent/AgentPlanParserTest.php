<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\Planning\AgentPlanParser;
use App\Agent\Tools\AgentToolDefinition;
use PHPUnit\Framework\TestCase;

final class AgentPlanParserTest extends TestCase
{
    public function test_it_accepts_ordered_dependent_actions_and_estimates_physical_calls(): void
    {
        $plan = (new AgentPlanParser)->parse([
            'decision' => 'tools',
            'actions' => [
                [
                    'id' => 'customer',
                    'tool' => 'find_customer',
                    'arguments' => ['name' => 'Tizio'],
                    'depends_on' => [],
                    'purpose' => 'Cerco il cliente',
                ],
                [
                    'id' => 'orders',
                    'tool' => 'get_orders',
                    'arguments' => ['customer_id' => ['$from' => 'customer', 'path' => 'items.0.id']],
                    'depends_on' => ['customer'],
                    'purpose' => 'Recupero gli ordini',
                ],
            ],
        ], $this->tools());

        $this->assertTrue($plan->shouldCallTools());
        $this->assertSame(['customer'], $plan->actions[1]->dependsOn);
        $this->assertSame(2, $plan->estimate->logicalLikely);
        $this->assertSame(51, $plan->estimate->physicalMaximum);
    }

    public function test_it_rejects_unknown_tools(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new AgentPlanParser)->parse([
            'decision' => 'tools',
            'actions' => [[
                'id' => 'bad', 'tool' => 'delete_everything', 'arguments' => [],
                'depends_on' => [], 'purpose' => 'Bad',
            ]],
        ], $this->tools());
    }

    public function test_it_rejects_forward_or_cyclic_dependencies(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new AgentPlanParser)->parse([
            'decision' => 'tools',
            'actions' => [[
                'id' => 'orders', 'tool' => 'get_orders', 'arguments' => [],
                'depends_on' => ['customer'], 'purpose' => 'Ordini',
            ]],
        ], $this->tools());
    }

    /** @return array<string,AgentToolDefinition> */
    private function tools(): array
    {
        return [
            'find_customer' => $this->tool('find_customer', 1),
            'get_orders' => $this->tool('get_orders', 50),
        ];
    }

    private function tool(string $name, int $physicalMax): AgentToolDefinition
    {
        return new AgentToolDefinition(
            name: $name,
            displayName: $name,
            description: $name,
            kind: 'api',
            inputSchema: ['type' => 'object'],
            readOnly: true,
            idempotent: true,
            physicalMinimum: 1,
            physicalLikely: 1,
            physicalMaximum: $physicalMax,
        );
    }
}
