<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\Tools\AgentLiveSourceSelection;
use App\Agent\Tools\AgentToolDefinition;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AgentLiveSourceSelectionTest extends TestCase
{
    #[Test]
    public function it_only_narrows_live_tools_and_never_removes_knowledge_by_default(): void
    {
        $service = new AgentLiveSourceSelection;
        $tools = [
            'search_knowledge_base' => $this->tool('search_knowledge_base', 'knowledge'),
            'orders' => $this->tool('orders', 'api', 'api:7', 'Commerce'),
            'shipments' => $this->tool('shipments', 'mcp', 'mcp:hubhive', 'HubHive'),
        ];

        $this->assertSame($tools, $service->apply($tools, null));
        $this->assertSame(
            ['search_knowledge_base', 'shipments'],
            array_keys($service->apply($tools, ['api' => [], 'mcp' => ['mcp:hubhive']])),
        );
        $this->assertSame(
            ['search_knowledge_base'],
            array_keys($service->apply($tools, ['api' => ['api:not-authorized'], 'mcp' => []])),
        );
    }

    #[Test]
    public function it_groups_tools_into_a_compact_connection_catalog(): void
    {
        $catalog = (new AgentLiveSourceSelection)->catalog([
            'orders_list' => $this->tool('orders_list', 'api', 'api:7', 'Commerce'),
            'orders_get' => $this->tool('orders_get', 'api', 'api:7', 'Commerce'),
            'shipments' => $this->tool('shipments', 'mcp', 'mcp:hubhive', 'HubHive'),
        ]);

        $this->assertSame('Commerce', data_get($catalog, 'api.0.name'));
        $this->assertSame(2, data_get($catalog, 'api.0.tool_count'));
        $this->assertSame('mcp:hubhive', data_get($catalog, 'mcp.0.key'));
        $this->assertSame(1, data_get($catalog, 'mcp.0.tool_count'));
    }

    private function tool(string $name, string $kind, ?string $sourceKey = null, ?string $sourceName = null): AgentToolDefinition
    {
        return new AgentToolDefinition(
            name: $name,
            displayName: $sourceName ?? $name,
            description: $name,
            kind: $kind,
            inputSchema: ['type' => 'object', 'properties' => []],
            readOnly: true,
            idempotent: true,
            physicalMinimum: $kind === 'knowledge' ? 0 : 1,
            physicalLikely: $kind === 'knowledge' ? 0 : 1,
            physicalMaximum: $kind === 'knowledge' ? 0 : 1,
            metadata: $sourceKey === null ? [] : [
                'source_key' => $sourceKey,
                'source_name' => $sourceName,
            ],
        );
    }
}
