<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Agent\Tools\AgentToolDefinition;
use App\Mcp\Debug\McpActivityDebugPayload;
use Tests\TestCase;

final class McpActivityDebugPayloadTest extends TestCase
{
    public function test_it_captures_connector_request_response_and_provenance_in_local(): void
    {
        config()->set('app.env', 'local');
        $tool = $this->tool();

        $debug = app(McpActivityDebugPayload::class)->capture(
            $tool,
            ['customer_id' => 17, 'api_key' => 'secret-key'],
            ['orders' => [['id' => 123]]],
            41,
            'ok',
        );

        $this->assertSame('tools/call', $debug['method']);
        $this->assertSame('connector', $debug['runtime']);
        $this->assertSame('Gescat', $debug['server_name']);
        $this->assertSame('connection-1', $debug['connection_id']);
        $this->assertSame('list-my-orders', $debug['tool_remote_name']);
        $this->assertSame(17, data_get($debug, 'parameters.customer_id'));
        $this->assertSame('[REDACTED]', data_get($debug, 'parameters.api_key'));
        $this->assertSame(123, data_get($debug, 'response.orders.0.id'));
        $this->assertSame(41, $debug['duration_ms']);
    }

    public function test_it_does_not_capture_debug_data_in_production(): void
    {
        config()->set('app.env', 'production');

        $this->assertNull(app(McpActivityDebugPayload::class)->capture(
            $this->tool(),
            ['customer_id' => 17],
            ['orders' => []],
            10,
            'ok',
        ));
    }

    public function test_it_accepts_stage_and_staging_environment_names(): void
    {
        foreach (['stage', 'staging'] as $environment) {
            config()->set('app.env', $environment);

            $this->assertTrue(app(McpActivityDebugPayload::class)->enabled());
        }
    }

    public function test_it_bounds_large_and_deep_debug_payloads(): void
    {
        config()->set('app.env', 'local');
        $deep = ['secret' => 'visible'];
        for ($depth = 0; $depth < 20; $depth++) {
            $deep = ['nested' => $deep];
        }

        $debug = app(McpActivityDebugPayload::class)->capture(
            $this->tool(),
            ['query' => str_repeat('x', 2_000_000), 'deep' => $deep],
            ['items' => array_fill(0, 500, ['value' => str_repeat('y', 2_000)])],
            10,
            'ok',
        );

        $encoded = json_encode($debug, JSON_THROW_ON_ERROR);
        $this->assertLessThanOrEqual(65_536, strlen($encoded));
        $this->assertTrue(data_get($debug, '_debug_meta.truncated'));
        $this->assertGreaterThan(2_000_000, data_get($debug, '_debug_meta.original_bytes'));
        $this->assertStringContainsString('[truncated]', data_get($debug, 'parameters.query'));
    }

    public function test_it_bounds_hostile_oversized_object_keys(): void
    {
        config()->set('app.env', 'local');
        $oversizedKey = str_repeat('remote-key-', 20_000);

        $debug = app(McpActivityDebugPayload::class)->capture(
            $this->tool(),
            [],
            [$oversizedKey => 'value'],
            10,
            'ok',
        );

        $encoded = json_encode($debug, JSON_THROW_ON_ERROR);
        $this->assertLessThanOrEqual(65_536, strlen($encoded));
        $this->assertTrue(data_get($debug, '_debug_meta.truncated'));
        $this->assertStringNotContainsString($oversizedKey, $encoded);
    }

    private function tool(): AgentToolDefinition
    {
        return new AgentToolDefinition(
            name: 'mcp_gescat_list_my_orders',
            displayName: 'list-my-orders',
            description: 'List the authenticated customer orders.',
            kind: 'mcp',
            inputSchema: ['type' => 'object'],
            readOnly: true,
            idempotent: true,
            physicalMinimum: 1,
            physicalLikely: 1,
            physicalMaximum: 1,
            executorReference: 'mcp_gescat_list_my_orders',
            metadata: [
                'mcp_runtime' => 'connector',
                'provenance' => [
                    'server_name' => 'Gescat',
                    'connection_id' => 'connection-1',
                    'tool_local_name' => 'mcp_gescat_list_my_orders',
                    'tool_remote_name' => 'list-my-orders',
                ],
            ],
        );
    }
}
