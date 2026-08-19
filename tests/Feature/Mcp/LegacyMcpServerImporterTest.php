<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Migration\LegacyMcpServerImporter;
use App\Models\McpServer;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Tests\TestCase;

final class LegacyMcpServerImporterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('test-tenant');
        app(ConnectorTenantContext::class)->set('test-tenant');
    }

    public function test_it_imports_wildcards_headers_and_tools_idempotently(): void
    {
        $user = User::query()->create([
            'name' => 'Importer',
            'email' => 'importer@example.test',
            'password' => Hash::make('secret123'),
        ]);
        $legacy = McpServer::query()->create([
            'tenant_id' => 'test-tenant',
            'name' => 'Legacy knowledge',
            'transport' => McpServer::TRANSPORT_HTTP,
            'endpoint' => 'https://mcp.example.test/rpc',
            'auth_config_encrypted' => Crypt::encryptString(json_encode([
                'token' => 'legacy-secret',
                'headers' => ['X-Legacy' => 'kept'],
            ], JSON_THROW_ON_ERROR)),
            'enabled_tools_json' => ['*'],
            'status' => McpServer::STATUS_ACTIVE,
            'last_handshake_at' => now(),
            'handshake_response_json' => [
                'protocol_version' => '2025-11-25',
                'server_info' => ['name' => 'legacy'],
                'capabilities' => ['tools' => true],
                'tools' => [
                    [
                        'name' => 'docs.search',
                        'inputSchema' => ['type' => 'object'],
                        'annotations' => ['readOnlyHint' => true],
                    ],
                    [
                        'name' => 'docs.update',
                        'inputSchema' => ['type' => 'object'],
                        'annotations' => ['readOnlyHint' => false],
                    ],
                ],
            ],
            'created_by' => $user->getKey(),
        ]);

        $importer = app(LegacyMcpServerImporter::class);
        $first = $importer->importServer($legacy);
        $second = $importer->importServer($legacy->fresh());

        $this->assertTrue($first['connection_created']);
        $this->assertFalse($second['connection_created']);
        $this->assertSame(1, McpServerDefinition::withoutGlobalScopes()->count());
        $this->assertSame(1, McpConnection::withoutGlobalScopes()->count());
        $this->assertSame(2, McpConnectionTool::withoutGlobalScopes()->count());

        $definition = McpServerDefinition::withoutGlobalScopes()->sole();
        $this->assertSame('legacy_headers', $definition->auth_mode);
        $this->assertSame('legacy', $definition->negotiated_era);
        $this->assertSame('Bearer legacy-secret', $definition->legacy_headers_encrypted['Authorization']);
        $this->assertSame('kept', $definition->legacy_headers_encrypted['X-Legacy']);

        $read = McpConnectionTool::withoutGlobalScopes()->where('remote_name', 'docs.search')->sole();
        $write = McpConnectionTool::withoutGlobalScopes()->where('remote_name', 'docs.update')->sole();
        $this->assertTrue($read->enabled);
        $this->assertFalse($read->confirmation_required);
        $this->assertTrue($write->enabled);
        $this->assertTrue($write->confirmation_required);
        $this->assertSame('write', $write->risk);
    }

    public function test_it_marks_tools_missing_without_deleting_them(): void
    {
        $user = User::query()->create([
            'name' => 'Importer',
            'email' => 'missing@example.test',
            'password' => Hash::make('secret123'),
        ]);
        $legacy = McpServer::query()->create([
            'tenant_id' => 'test-tenant',
            'name' => 'Changing server',
            'transport' => McpServer::TRANSPORT_HTTP,
            'endpoint' => 'https://mcp.example.test/rpc',
            'enabled_tools_json' => ['docs.search'],
            'status' => McpServer::STATUS_ACTIVE,
            'handshake_response_json' => [
                'tools' => [[
                    'name' => 'docs.search',
                    'inputSchema' => ['type' => 'object'],
                    'annotations' => ['readOnlyHint' => true],
                ]],
            ],
            'created_by' => $user->getKey(),
        ]);

        $importer = app(LegacyMcpServerImporter::class);
        $importer->importServer($legacy);
        $legacy->forceFill(['handshake_response_json' => ['tools' => []]])->save();
        $importer->importServer($legacy->fresh());

        $tool = McpConnectionTool::withoutGlobalScopes()->sole();
        $this->assertFalse($tool->enabled);
        $this->assertNotNull($tool->removed_at);
    }
}
