<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\Capabilities\AgentCapabilityHint;
use App\Agent\Capabilities\AgentCapabilityRanker;
use App\Agent\Capabilities\AgentCapabilityRouter;
use App\Agent\Capabilities\AgentCapabilitySnapshotBuilder;
use App\Agent\Tools\AgentToolDefinition;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use Mockery;
use Tests\TestCase;

final class AgentCapabilityRouterTest extends TestCase
{
    public function test_router_never_returns_more_than_eight_known_read_only_candidates(): void
    {
        $tools = [];
        for ($index = 1; $index <= 12; $index++) {
            $name = 'list_orders_'.$index;
            $tools[$name] = new AgentToolDefinition(
                $name, $name, 'remote', 'api', ['type' => 'object', 'properties' => []],
                true, true, 1, 1, 1,
            );
        }
        $snapshot = (new AgentCapabilitySnapshotBuilder(new AgentCapabilityHint))->build($tools);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse(
            content: '',
            provider: 'fake',
            model: 'fake',
            toolCalls: [['name' => 'submit_capability_route', 'arguments' => [
                'live_data_required' => true,
                'entity' => 'orders',
                'operation' => 'list',
                'candidate_tools' => array_keys($tools),
                'reason_codes' => ['live_orders'],
            ]]],
        ));

        $route = (new AgentCapabilityRouter($ai, new AgentCapabilityRanker))->route(
            'Fammi vedere tutti gli ordini', null, $snapshot, false,
        );

        $this->assertCount(8, $route->candidateTools);
        $this->assertEmpty(array_diff($route->candidateTools, array_keys($tools)));
    }
}
