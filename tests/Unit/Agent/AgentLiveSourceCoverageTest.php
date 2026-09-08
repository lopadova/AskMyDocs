<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\AgentProgress;
use App\Agent\Planning\AgentExhaustiveSourceIntent;
use App\Agent\Planning\AgentLiveSourceCoverage;
use App\Agent\Planning\AgentPlan;
use App\Agent\Planning\AgentPlannedAction;
use App\Agent\Planning\AgentPlanValidationException;
use App\Agent\Tools\AgentToolDefinition;
use PHPUnit\Framework\TestCase;

final class AgentLiveSourceCoverageTest extends TestCase
{
    public function test_exhaustive_search_prefers_an_available_mcp_source(): void
    {
        $coverage = new AgentLiveSourceCoverage(new AgentExhaustiveSourceIntent);
        $tools = [
            'search_api' => $this->tool('search_api', 'api'),
            'search_mcp' => $this->tool('search_mcp', 'mcp'),
        ];
        $apiOnlyPlan = new AgentPlan('tools', [
            new AgentPlannedAction('api_lookup', 'search_api', [], [], 'Search API'),
        ], new AgentProgress);

        try {
            $coverage->validate(
                'Cerca tutto quello che riesci a trovare su Giulia Riva',
                $apiOnlyPlan,
                $tools,
                [],
            );
            $this->fail('An exhaustive plan without MCP coverage should be rejected.');
        } catch (AgentPlanValidationException $exception) {
            $this->assertSame('live_source_coverage_required', $exception->validationCode);
        }

        $mcpPlan = new AgentPlan('tools', [
            new AgentPlannedAction('mcp_lookup', 'search_mcp', [], [], 'Search MCP'),
        ], new AgentProgress);
        $coverage->validate(
            'Cerca tutto quello che riesci a trovare su Giulia Riva',
            $mcpPlan,
            $tools,
            [],
        );

        $this->addToAssertionCount(1);
    }

    public function test_a_completed_mcp_attempt_allows_the_final_answer(): void
    {
        $coverage = new AgentLiveSourceCoverage(new AgentExhaustiveSourceIntent);
        $tools = ['search_mcp' => $this->tool('search_mcp', 'mcp')];

        $coverage->validate(
            'Find everything you can find about Giulia Riva',
            new AgentPlan('answer', [], new AgentProgress),
            $tools,
            [[
                'id' => 'mcp_lookup',
                'tool' => 'search_mcp',
                'status' => 'completed',
            ]],
        );

        $this->addToAssertionCount(1);
    }

    private function tool(string $name, string $kind): AgentToolDefinition
    {
        return new AgentToolDefinition(
            name: $name,
            displayName: $name,
            description: $name,
            kind: $kind,
            inputSchema: ['type' => 'object', 'properties' => []],
            readOnly: true,
            idempotent: true,
            physicalMinimum: 1,
            physicalLikely: 1,
            physicalMaximum: 1,
        );
    }
}
