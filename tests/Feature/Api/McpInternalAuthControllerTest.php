<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v5.0/W1 → v7.0/W6.3.B — internal MCP callback (token-presence probe).
 *
 * The richer `/api/mcp/credentials` endpoint was removed in W6.3.B
 * (Copilot iter-3 flagged it as a latent decrypted-secret pathway
 * reachable by ordinary authenticated users when
 * `MCP_INTERNAL_AUTH_TOKEN` is empty). Only the `/internal-auth`
 * token-presence probe survives until v7.0/W6.3.C also retires it.
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

    public function test_credentials_endpoint_is_removed(): void
    {
        // v7.0/W6.3.B — `/api/mcp/credentials` was the Node sidecar's
        // entry point for decrypted `auth_config`. The route was
        // removed to close a latent secret-exfiltration pathway. The
        // test stays in place to PROVE the endpoint is gone — if a
        // future refactor brings it back without explicit security
        // review, this assertion fails.
        $response = $this->postJson('/api/mcp/credentials', [
            'tenant_id' => 'default',
            'mcp_server_id' => 1,
        ]);
        $response->assertNotFound();
    }
}
