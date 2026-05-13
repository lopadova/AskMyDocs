<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\McpServer;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v5.0/W1 — internal MCP callbacks consumed by node sidecar.
 *
 * Covers both success and deny paths for:
 *   - /api/mcp/internal-auth (token self-check)
 *   - /api/mcp/credentials (auth bootstrap, tenant-bound lookup)
 */
final class McpInternalAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
    }

    public function test_verify_reports_no_guard_when_token_is_not_configured(): void
    {
        config()->set('mcp.internal_auth_token', null);

        $response = $this->postJson('/api/mcp/internal-auth');
        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('message', 'No token guard configured.');
    }

    public function test_verify_rejects_missing_token_when_token_guard_is_configured(): void
    {
        config()->set('mcp.internal_auth_token', 'token-123');

        $response = $this->postJson('/api/mcp/internal-auth');
        $response->assertStatus(401);
        $response->assertJsonPath('ok', false);
    }

    public function test_verify_accepts_matching_internal_token(): void
    {
        config()->set('mcp.internal_auth_token', 'token-123');

        $response = $this->postJson('/api/mcp/internal-auth', [], [
            'X-MCP-Internal-Token' => 'token-123',
        ]);
        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonMissingPath('message');
    }

    public function test_verify_rejects_wrong_internal_token(): void
    {
        config()->set('mcp.internal_auth_token', 'token-123');

        $response = $this->postJson('/api/mcp/internal-auth', [], [
            'X-MCP-Internal-Token' => 'wrong-token',
        ]);
        $response->assertStatus(401);
        $response->assertJsonPath('ok', false);
    }

    public function test_credentials_returns_decrypted_auth_config_and_enabled_tools_when_configured(): void
    {
        config()->set('mcp.internal_auth_token', null);
        $user = $this->makeUser();
        $server = $this->createServer($user, [
            'auth_config_encrypted' => Crypt::encryptString(json_encode([
                'api_key' => 'secret',
                'tenant' => 'mcp-x',
            ], JSON_UNESCAPED_UNICODE)),
            'enabled_tools_json' => ['search_docs', 'graph'],
        ]);

        $response = $this->actingAs($user)->postJson('/api/mcp/credentials', [
            'tenant_id' => 'default',
            'mcp_server_id' => $server->id,
        ]);

        $response->assertOk();
        $payload = $response->json('data');

        $this->assertSame('http', $payload['transport']);
        $this->assertSame('http://127.0.0.1:3535', $payload['endpoint']);
        $this->assertSame(['api_key' => 'secret', 'tenant' => 'mcp-x'], $payload['auth_config']);
        $this->assertSame(['search_docs', 'graph'], $payload['enabled_tools']);
    }

    public function test_credentials_returns_null_payload_when_encrypted_auth_config_is_missing(): void
    {
        $user = $this->makeUser();
        $server = $this->createServer($user, [
            'auth_config_encrypted' => null,
        ]);

        $response = $this->actingAs($user)->postJson('/api/mcp/credentials', [
            'tenant_id' => 'default',
            'mcp_server_id' => $server->id,
        ]);

        $response->assertOk();
        $this->assertNull($response->json('data.auth_config'));
    }

    public function test_credentials_denies_invalid_tenant_id_shape(): void
    {
        $user = $this->makeUser();
        $server = $this->createServer($user);

        $response = $this->actingAs($user)->postJson('/api/mcp/credentials', [
            'tenant_id' => 'BAD#TENANT',
            'mcp_server_id' => $server->id,
        ]);
        $response->assertStatus(404);
    }

    public function test_credentials_returns_404_when_server_not_found_for_tenant_scope(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->postJson('/api/mcp/credentials', [
            'tenant_id' => 'default',
            'mcp_server_id' => 9999,
        ]);
        $response->assertNotFound();
    }

    public function test_credentials_denies_when_token_is_required_and_missing(): void
    {
        config()->set('mcp.internal_auth_token', 'sidecar-token');

        $user = $this->makeUser();
        $server = $this->createServer($user);

        $response = $this->actingAs($user)->postJson('/api/mcp/credentials', [
            'tenant_id' => 'default',
            'mcp_server_id' => $server->id,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Invalid MCP internal token.');
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'MCP Sidecar',
            'email' => 'mcp-sidecar-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createServer(User $user, array $overrides = []): McpServer
    {
        return McpServer::create(array_merge([
            'tenant_id' => app(TenantContext::class)->current(),
            'name' => 'server-'.uniqid(),
            'transport' => McpServer::TRANSPORT_HTTP,
            'endpoint' => 'http://127.0.0.1:3535',
            'auth_config_encrypted' => Crypt::encryptString(json_encode([
                'api_key' => 'seed',
            ], JSON_UNESCAPED_UNICODE)),
            'enabled_tools_json' => ['*'],
            'status' => McpServer::STATUS_ACTIVE,
            'created_by' => $user->id,
        ], $overrides));
    }
}
