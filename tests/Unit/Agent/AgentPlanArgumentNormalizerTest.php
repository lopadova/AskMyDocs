<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\AgentProgress;
use App\Agent\Planning\AgentPlan;
use App\Agent\Planning\AgentPlanArgumentNormalizer;
use App\Agent\Planning\AgentPlannedAction;
use App\Agent\Tools\AgentToolDefinition;
use PHPUnit\Framework\TestCase;

final class AgentPlanArgumentNormalizerTest extends TestCase
{
    public function test_it_removes_only_optional_empty_values_recursively(): void
    {
        $tool = $this->tool([
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'minLength' => 1],
                'filters' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string'],
                        'required_code' => ['type' => 'string'],
                    ],
                    'required' => ['required_code'],
                    'additionalProperties' => false,
                ],
                'nullable' => ['type' => ['string', 'null']],
                'optional_null' => ['type' => 'string'],
                'sort' => ['type' => 'array', 'items' => ['type' => 'object']],
            ],
            'additionalProperties' => false,
        ]);
        $plan = $this->plan([
            'query' => '',
            'filters' => ['status' => '', 'required_code' => ''],
            'nullable' => null,
            'optional_null' => null,
            'sort' => [],
            'unknown' => '',
        ]);

        $normalized = (new AgentPlanArgumentNormalizer)->normalize($plan, ['orders' => $tool]);

        $this->assertSame([
            'filters' => ['required_code' => ''],
            'nullable' => null,
            'unknown' => '',
        ], $normalized->actions[0]->arguments);
    }

    public function test_it_preserves_required_empty_values_for_schema_rejection(): void
    {
        $tool = $this->tool([
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string', 'minLength' => 1]],
            'required' => ['query'],
            'additionalProperties' => false,
        ]);

        $normalized = (new AgentPlanArgumentNormalizer)->normalize(
            $this->plan(['query' => '']),
            ['orders' => $tool],
        );

        $this->assertSame(['query' => ''], $normalized->actions[0]->arguments);
    }

    public function test_it_preserves_result_references(): void
    {
        $tool = $this->tool([
            'type' => 'object',
            'properties' => ['customer_id' => ['type' => 'string']],
            'required' => ['customer_id'],
        ]);
        $reference = ['$from' => 'customers', 'path' => 'data.items.0.id'];

        $normalized = (new AgentPlanArgumentNormalizer)->normalize(
            $this->plan(['customer_id' => $reference]),
            ['orders' => $tool],
        );

        $this->assertSame($reference, $normalized->actions[0]->arguments['customer_id']);
    }

    /** @param array<string,mixed> $schema */
    private function tool(array $schema): AgentToolDefinition
    {
        return new AgentToolDefinition(
            'orders', 'Orders', 'Orders', 'mcp', $schema,
            true, true, 1, 1, 1,
        );
    }

    /** @param array<string,mixed> $arguments */
    private function plan(array $arguments): AgentPlan
    {
        return new AgentPlan('tools', [
            new AgentPlannedAction('orders', 'orders', $arguments, [], 'Cerco gli ordini'),
        ], new AgentProgress(logicalMinimum: 1, logicalLikely: 1, logicalMaximum: 1));
    }
}
