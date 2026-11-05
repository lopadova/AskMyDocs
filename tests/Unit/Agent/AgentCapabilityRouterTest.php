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

    public function test_router_recovers_read_only_tools_from_an_omitted_family(): void
    {
        config()->set('agent.planner.router_catalog_limit', 40);
        $tools = [];
        for ($index = 1; $index <= 100; $index++) {
            $name = 'list_orders_'.str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $tools[$name] = new AgentToolDefinition(
                $name,
                $name,
                'Order lookup',
                'api',
                ['type' => 'object', 'properties' => []],
                true,
                true,
                1,
                1,
                1,
                metadata: ['agent_capability_hint' => [
                    'entity' => 'orders',
                    'operation' => 'list',
                ]],
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
                'candidate_tools' => [],
                'candidate_families' => ['api:orders'],
                'reason_codes' => ['omitted_family_match'],
            ]]],
        ));

        $route = (new AgentCapabilityRouter($ai, new AgentCapabilityRanker))->route(
            'Fammi vedere gli ordini', null, $snapshot, false,
        );

        $this->assertCount(8, $route->candidateTools);
        $this->assertSame('list_orders_041', $route->candidateTools[0]);
        $this->assertSame('list_orders_048', $route->candidateTools[7]);
    }

    public function test_router_never_exposes_write_or_confirmation_required_tools(): void
    {
        $tools = [
            'list_orders' => new AgentToolDefinition(
                'list_orders', 'List orders', 'List orders', 'api',
                ['type' => 'object', 'properties' => []], true, true, 1, 1, 1,
            ),
            'delete_order' => new AgentToolDefinition(
                'delete_order', 'Delete order', 'Delete order', 'api',
                ['type' => 'object', 'properties' => []], false, false, 1, 1, 1,
            ),
            'confirm_order' => new AgentToolDefinition(
                'confirm_order', 'Confirm order', 'Confirm order', 'api',
                ['type' => 'object', 'properties' => []], true, true, 1, 1, 1,
                metadata: ['confirmation_required' => true],
            ),
        ];
        $snapshot = (new AgentCapabilitySnapshotBuilder(new AgentCapabilityHint))->build($tools);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->withArgs(
            function (string $system, array $history): bool {
                $payload = json_decode((string) data_get($history, '0.content'), true, flags: JSON_THROW_ON_ERROR);
                $names = array_map(
                    static fn (array $item): mixed => data_get($item, 'capability.tool'),
                    $payload['capabilities'],
                );

                $this->assertSame(['list_orders'], $names);

                return true;
            },
        )->andReturn(new AiResponse(
            content: '',
            provider: 'fake',
            model: 'fake',
            toolCalls: [['name' => 'submit_capability_route', 'arguments' => [
                'live_data_required' => true,
                'entity' => 'orders',
                'operation' => 'list',
                'candidate_tools' => ['delete_order', 'confirm_order', 'list_orders'],
                'reason_codes' => ['live_orders'],
            ]]],
        ));

        $route = (new AgentCapabilityRouter($ai, new AgentCapabilityRanker))->route(
            'Gestisci gli ordini', null, $snapshot, false,
        );

        $this->assertSame(['list_orders'], $route->candidateTools);
    }
}
