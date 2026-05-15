<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\McpServer;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * v5.0/W1 — Admin MCP registry surface (feature coverage).
 */
final class McpServersAdminControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        app(TenantContext::class)->set('default');
        $this->seed(RbacSeeder::class);
    }

    public function test_index_lists_only_current_tenant_servers_ordered_by_name(): void
    {
        $admin = $this->makeSuperAdmin();

        $this->createServer($admin, ['tenant_id' => 'default', 'name' => 'b-server']);
        $this->createServer($admin, ['tenant_id' => 'default', 'name' => 'a-server']);
        $this->createServer($admin, ['tenant_id' => 'tenant-x', 'name' => 'x-server']);

        $response = $this->actingAs($admin)->getJson('/api/admin/mcp-servers');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->values()->all();
        $this->assertSame(['a-server', 'b-server'], $names);
    }

    public function test_store_encrypts_auth_config_and_defaults_enabled_tools(): void
    {
        $admin = $this->makeSuperAdmin();
        $authConfig = ['api_key' => 'secret'];

        $response = $this->actingAs($admin)->postJson('/api/admin/mcp-servers', [
            'name' => 'kb-remote',
            'transport' => 'http',
            'endpoint' => 'http://127.0.0.1:3535',
            'auth_config' => $authConfig,
        ]);

        $response->assertStatus(201);
        $serverId = (int) $response->json('data.id');

        $server = McpServer::findOrFail($serverId);
        $this->assertSame(McpServer::STATUS_PENDING, $server->status);
        $this->assertSame(['*'], $server->enabled_tools_json);
        $this->assertSame('kb-remote', $server->name);
        $this->assertNotEquals(
            json_encode($authConfig, JSON_UNESCAPED_UNICODE),
            $server->getRawOriginal('auth_config_encrypted'),
        );
        $response->assertJsonPath('data.enabled_tools', ['*']);
        $response->assertJsonPath('data.status', McpServer::STATUS_PENDING);
    }

    public function test_store_rejects_invalid_payload(): void
    {
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)->postJson('/api/admin/mcp-servers', [
            'name' => '',
            'transport' => 'invalid',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'transport', 'endpoint']);
    }

    public function test_handshake_success_updates_status_and_response(): void
    {
        $super = $this->makeSuperAdmin();
        $server = $this->createServer($super, ['status' => McpServer::STATUS_PENDING]);

        // v7.0/W1.B — the handshake drives the package's McpClient
        // through the host's SidecarMcpTransport, which translates
        // JSON-RPC into the sidecar's REST routes. Both `initialize`
        // and `tools/list` are answered by a single cached POST to
        // /handshake (the package's stock HttpJsonRpcTransport is
        // NOT used; see app/Mcp/Bridge/SidecarMcpTransport.php).
        Http::fake([
            'http://127.0.0.1:3535/handshake' => Http::response([
                'capabilities' => ['tools' => (object) []],
                'tools' => [['name' => 'doc'], ['name' => 'graph']],
            ], 200),
        ]);

        $response = $this->actingAs($super)->postJson('/api/admin/mcp-servers/'.$server->id.'/handshake');

        $response->assertOk()->assertJsonPath('data.status', McpServer::STATUS_ACTIVE);
        $server->refresh();
        $this->assertSame(McpServer::STATUS_ACTIVE, $server->status);
        $payload = $server->handshake_response_json;
        $this->assertArrayHasKey('capabilities', $payload);
        $this->assertArrayHasKey('tools', $payload);
        $this->assertSame(['doc', 'graph'], array_column($payload['tools'], 'name'));

        // Contract gate: the request body MUST satisfy the sidecar's
        // HandshakeRequestSchema (see mcp-client/src/types/mcp.ts).
        // Regressions on the SidecarMcpTransport payload shape would
        // produce 400 Zod validation errors against the real sidecar;
        // failing here catches them at PHPUnit time.
        Http::assertSent(function ($request) use ($server) {
            if ($request->url() !== 'http://127.0.0.1:3535/handshake') {
                return false;
            }
            $body = $request->data();
            return isset($body['tenant_id'], $body['server_id'], $body['server_name'], $body['transport'], $body['endpoint'])
                && $body['server_id'] === $server->id
                && $body['server_name'] === $server->name
                && in_array($body['transport'], ['stdio', 'sse', 'http'], true)
                && is_string($body['tenant_id']) && $body['tenant_id'] !== '';
        });
    }

    public function test_handshake_failure_marks_server_as_errored(): void
    {
        $super = $this->makeSuperAdmin();
        $server = $this->createServer($super, ['status' => McpServer::STATUS_PENDING]);

        Http::fake([
            'http://127.0.0.1:3535/handshake' => Http::response('sidecar unavailable', 500),
        ]);

        $response = $this->actingAs($super)->postJson('/api/admin/mcp-servers/'.$server->id.'/handshake');

        $response->assertStatus(502)->assertJsonPath('error', 'MCP handshake failed.');
        $server->refresh();
        $this->assertSame(McpServer::STATUS_ERRORED, $server->status);
        $this->assertSame('error', $server->handshake_response_json['status']);
        // v7.0/W1.B — error excerpt comes through the host
        // SidecarMcpTransport wrapper around the non-2xx body.
        $this->assertStringContainsString('Sidecar /handshake', $server->handshake_response_json['message']);
    }

    public function test_update_enabled_tools_requires_validation_and_persists_allowed_tools(): void
    {
        $super = $this->makeSuperAdmin();
        $server = $this->createServer($super, ['enabled_tools_json' => ['a']]);

        $response = $this->actingAs($super)
            ->patchJson('/api/admin/mcp-servers/'.$server->id.'/tools', [
                'enabled_tools' => ['search_docs', 'graph'],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.enabled_tools', ['search_docs', 'graph']);

        $server->refresh();
        $this->assertSame(['search_docs', 'graph'], $server->enabled_tools_json);
    }

    public function test_update_enabled_tools_rejects_invalid_payload(): void
    {
        $super = $this->makeSuperAdmin();
        $server = $this->createServer($super);

        $this->actingAs($super)->patchJson('/api/admin/mcp-servers/'.$server->id.'/tools', [
            'enabled_tools' => 'bad',
        ])->assertStatus(422)->assertJsonValidationErrors(['enabled_tools']);
    }

    public function test_disable_marks_status_as_disabled(): void
    {
        $super = $this->makeSuperAdmin();
        $server = $this->createServer($super);

        $response = $this->actingAs($super)->postJson('/api/admin/mcp-servers/'.$server->id.'/disable');

        $response->assertOk()->assertJsonPath('data.status', McpServer::STATUS_DISABLED);
        $server->refresh();
        $this->assertSame(McpServer::STATUS_DISABLED, $server->status);
    }

    public function test_destroy_removes_server_row(): void
    {
        $super = $this->makeSuperAdmin();
        $server = $this->createServer($super);

        $this->actingAs($super)->deleteJson('/api/admin/mcp-servers/'.$server->id)->assertStatus(204);
        $this->assertDatabaseMissing('mcp_servers', ['id' => $server->id]);
    }

    public function test_non_super_admin_gets_403(): void
    {
        $admin = $this->makeAdmin();
        $server = $this->createServer($admin);

        $this->actingAs($admin)->getJson('/api/admin/mcp-servers')->assertStatus(403);
        $this->actingAs($admin)->postJson('/api/admin/mcp-servers', [
            'name' => 'guest',
            'transport' => 'http',
            'endpoint' => 'http://127.0.0.1',
        ])->assertStatus(403);
        $this->actingAs($admin)->patchJson('/api/admin/mcp-servers/'.$server->id.'/tools', [
            'enabled_tools' => ['x'],
        ])->assertStatus(403);
        $this->actingAs($admin)->postJson('/api/admin/mcp-servers/'.$server->id.'/disable')->assertStatus(403);
        $this->actingAs($admin)->deleteJson('/api/admin/mcp-servers/'.$server->id)->assertStatus(403);
        $this->actingAs($admin)->postJson('/api/admin/mcp-servers/'.$server->id.'/handshake')->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/admin/mcp-servers')->assertStatus(401);
        $this->postJson('/api/admin/mcp-servers')->assertStatus(401);
    }

    public function test_cross_tenant_server_is_not_visible_or_mutable(): void
    {
        $super = $this->makeSuperAdmin();
        $tenantServer = $this->createServer($super, ['tenant_id' => 'tenant-x']);
        $defaultServer = $this->createServer($super, ['name' => 'default-visible']);

        $index = $this->actingAs($super)->getJson('/api/admin/mcp-servers');
        $index->assertOk();
        $ids = collect($index->json('data'))->pluck('id')->all();
        $this->assertContains($defaultServer->id, $ids);
        $this->assertNotContains($tenantServer->id, $ids);

        $this->actingAs($super)->postJson('/api/admin/mcp-servers/'.$tenantServer->id.'/disable')->assertStatus(404);
        $this->actingAs($super)->deleteJson('/api/admin/mcp-servers/'.$tenantServer->id)->assertStatus(404);
        $this->actingAs($super)->patchJson('/api/admin/mcp-servers/'.$tenantServer->id.'/tools', [
            'enabled_tools' => ['x'],
        ])->assertStatus(404);
    }

    private function makeSuperAdmin(): User
    {
        $user = User::create([
            'name' => 'Super',
            'email' => 'super-'.uniqid().'@demo.local',
            'password' => \Illuminate\Support\Facades\Hash::make('secret123'),
        ]);
        $user->assignRole('super-admin');

        return $user;
    }

    private function makeAdmin(): User
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@demo.local',
            'password' => \Illuminate\Support\Facades\Hash::make('secret123'),
        ]);
        $user->assignRole('admin');

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createServer(User $user, array $overrides = []): McpServer
    {
        $payload = array_merge([
            'tenant_id' => 'default',
            'name' => 'server-'.uniqid(),
            'transport' => McpServer::TRANSPORT_HTTP,
            'endpoint' => 'http://127.0.0.1:3535',
            'auth_config_encrypted' => Crypt::encryptString(json_encode([], JSON_UNESCAPED_UNICODE)),
            'enabled_tools_json' => ['*'],
            'status' => McpServer::STATUS_ACTIVE,
            'created_by' => $user->id,
        ], $overrides);

        return McpServer::create($payload);
    }
}
