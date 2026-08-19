<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Audit\McpConnectorAuditRecorder;
use App\Models\McpToolCallAudit;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class McpConnectorAuditRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_provenance_and_hashes_without_raw_tool_payloads(): void
    {
        app(TenantContext::class)->set('test-tenant');
        $user = User::query()->create([
            'name' => 'Auditor',
            'email' => 'audit@example.test',
            'password' => Hash::make('secret123'),
        ]);
        $arguments = ['query' => 'sensitive words'];
        $payload = [
            'artifact' => [
                'text' => 'private result',
                'provenance' => [
                    'connection_id' => '01K2MCPTESTCONNECTION0000000',
                    'invocation_id' => 'f21774ca-bdd4-4df2-98eb-ad4c09ed2442',
                    'tool_remote_name' => 'docs.search',
                    'tool_local_name' => 'knowledge_docs_search_abcd1234',
                    'latency_ms' => 42,
                ],
            ],
        ];

        app(McpConnectorAuditRecorder::class)->record(
            $user,
            [
                'name' => 'knowledge_docs_search_abcd1234',
                'provenance' => ['server_name' => 'Knowledge MCP'],
            ],
            $arguments,
            [],
            'ok',
            $payload,
            42,
        );

        $audit = McpToolCallAudit::query()->sole();
        $this->assertSame('test-tenant', $audit->tenant_id);
        $this->assertSame('mcp_connector', $audit->source);
        $this->assertSame('01K2MCPTESTCONNECTION0000000', $audit->mcp_connection_id);
        $this->assertSame('docs.search', $audit->tool_remote_name);
        $this->assertSame('knowledge_docs_search_abcd1234', $audit->tool_local_name);
        $this->assertSame(McpToolCallAudit::canonicalHash($arguments), $audit->input_hash);
        $this->assertSame([], $audit->input_json_redacted);
        $this->assertNotSame(hash('sha256', 'private result'), $audit->result_hash);
        $this->assertNull($audit->mcp_server_id);
    }
}
