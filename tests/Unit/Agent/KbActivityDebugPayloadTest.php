<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\Debug\KbActivityDebugPayload;
use App\Agent\Tools\AgentToolDefinition;
use Tests\TestCase;

/**
 * KbActivityDebugPayload is the search_knowledge_base / list_knowledge_documents
 * counterpart of McpActivityDebugPayload (which gates on kind==='mcp' and so
 * never captured anything for a KB tool call — the "why is the ⓘ activity
 * modal empty for KB searches" gap). Same local/stage-only posture and
 * redaction/bounding, composed from McpActivityDebugPayload rather than
 * duplicated.
 */
final class KbActivityDebugPayloadTest extends TestCase
{
    public function test_it_captures_the_query_and_response_in_local(): void
    {
        config()->set('app.env', 'local');

        $debug = app(KbActivityDebugPayload::class)->capture(
            $this->tool('knowledge', 'search_knowledge_base'),
            ['query' => 'SizeCharts manuale'],
            ['documents' => [['title' => 'SizeCharts Manual']]],
            37,
            'ok',
        );

        $this->assertSame('knowledge_base', $debug['surface']);
        $this->assertSame('search_knowledge_base', $debug['tool_name']);
        $this->assertSame('SizeCharts manuale', $debug['query']);
        $this->assertSame('SizeCharts Manual', data_get($debug, 'response.documents.0.title'));
        $this->assertSame(37, $debug['duration_ms']);
        $this->assertSame('ok', $debug['status']);
        $this->assertNull($debug['error']);
    }

    public function test_it_captures_the_catalog_tool_too(): void
    {
        config()->set('app.env', 'local');

        $debug = app(KbActivityDebugPayload::class)->capture(
            $this->tool('catalog', 'list_knowledge_documents'),
            [],
            ['count' => 2, 'documents' => []],
            12,
            'ok',
        );

        $this->assertSame('list_knowledge_documents', $debug['tool_name']);
        $this->assertNull($debug['query']);
        $this->assertSame(2, data_get($debug, 'response.count'));
    }

    public function test_it_captures_the_exception_on_failure(): void
    {
        config()->set('app.env', 'local');

        $debug = app(KbActivityDebugPayload::class)->capture(
            $this->tool('knowledge', 'search_knowledge_base'),
            ['query' => 'x'],
            null,
            5,
            'error',
            new \RuntimeException('embedding provider timed out'),
        );

        $this->assertSame('error', $debug['status']);
        $this->assertSame('embedding provider timed out', data_get($debug, 'error.message'));
    }

    public function test_it_does_not_capture_anything_for_a_non_kb_tool(): void
    {
        config()->set('app.env', 'local');

        $this->assertNull(app(KbActivityDebugPayload::class)->capture(
            $this->tool('api', 'get_orders'),
            ['customer_id' => 17],
            ['orders' => []],
            10,
            'ok',
        ));
    }

    public function test_it_does_not_capture_debug_data_in_production(): void
    {
        config()->set('app.env', 'production');

        $this->assertNull(app(KbActivityDebugPayload::class)->capture(
            $this->tool('knowledge', 'search_knowledge_base'),
            ['query' => 'x'],
            ['documents' => []],
            10,
            'ok',
        ));
    }

    private function tool(string $kind, string $name): AgentToolDefinition
    {
        return new AgentToolDefinition(
            name: $name,
            displayName: $name === 'search_knowledge_base' ? 'Knowledge base' : 'Document catalog',
            description: 'Test tool',
            kind: $kind,
            inputSchema: ['type' => 'object'],
            readOnly: true,
            idempotent: true,
            physicalMinimum: 0,
            physicalLikely: 0,
            physicalMaximum: 0,
        );
    }
}
