<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Padosoft\AskMyDocsConnectorMcp\Contracts\SafeHttpClientContract;
use Padosoft\AskMyDocsConnectorMcp\Models\McpCredential;
use Padosoft\AskMyDocsConnectorMcp\Models\McpOAuthAttempt;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Tests\Support\Mcp\StubMcpTransport;
use Tests\TestCase;

final class AdminMcpOAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('test-tenant');
        app(ConnectorTenantContext::class)->set('test-tenant');
        config(['connector-mcp.enabled' => true, 'connector-mcp.oauth.enabled' => true,
            'connector-mcp.runtime_mode' => 'active', 'connector-mcp.http.internal_endpoint_allowlist' => ['mcp.example.test'],
            'connector-mcp.oauth.client_metadata_url' => 'https://askmydocs.example/.well-known/mcp-client.json']);
        $http = \Mockery::mock(SafeHttpClientContract::class);
        $http->shouldReceive('get')->andReturnUsing(function (string $url): Response {
            $data = match ($url) {
                'https://mcp.example.test/.well-known/oauth-protected-resource/mcp' => [
                    'resource' => 'https://mcp.example.test/mcp', 'authorization_servers' => ['https://auth.example.test/tenant'], 'scopes_supported' => ['files:read'],
                ],
                'https://auth.example.test/.well-known/oauth-authorization-server/tenant' => [
                    'issuer' => 'https://auth.example.test/tenant', 'authorization_endpoint' => 'https://auth.example.test/tenant/authorize',
                    'token_endpoint' => 'https://auth.example.test/tenant/token', 'code_challenge_methods_supported' => ['S256'],
                    'client_id_metadata_document_supported' => true, 'authorization_response_iss_parameter_supported' => true,
                ],
                default => [],
            };
            return new Response(new PsrResponse($data === [] ? 404 : 200, ['Content-Type' => 'application/json'], json_encode($data)));
        });
        $this->app->instance(SafeHttpClientContract::class, $http);
        McpClient::useTransportResolver(static function () {
            $transport = new StubMcpTransport;
            $transport->responses['server/discover'] = ['protocolVersion' => '2026-07-28', 'capabilities' => ['tools' => []]];
            $transport->responses['tools/list'] = ['tools' => [['name' => 'files.search', 'inputSchema' => ['type' => 'object'], 'annotations' => ['readOnlyHint' => true]]]];
            return $transport;
        });
    }

    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    private function start(): array
    {
        $user = User::create(['name' => 'MCP administrator', 'email' => 'oauth-admin@example.test', 'password' => bcrypt('secret')]);
        $user->assignRole('super-admin');
        $response = $this->actingAs($user)->postJson('/api/admin/connectors/mcp', [
            'name' => 'Files MCP', 'transport' => 'auto', 'auth_method' => 'oauth',
            'endpoint' => 'https://mcp.example.test/mcp', 'ui_destination' => '/app/autry/admin/mcp-tools',
        ])->assertCreated()->assertJsonPath('authorization_required', true)->assertJsonPath('connection.mode', 'shared');
        parse_str(parse_url($response->json('next_action.authorization_url'), PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('https://mcp.example.test/mcp', $query['resource']);
        $this->assertSame('files:read', $query['scope']);
        $this->assertSame(hash('sha256', $query['state']), McpOAuthAttempt::query()->firstOrFail()->state_hash);
        return [$response->json('connection.public_id'), $query];
    }

    public function test_shared_oauth_returns_to_the_mcp_tools_page_and_discovers_tools_without_exposing_credentials(): void
    {
        app(SafeHttpClientContract::class)->shouldReceive('postForm')->once()->withArgs(function ($url, $form) {
            $this->assertSame('https://auth.example.test/tenant/token', $url);
            $this->assertSame('https://mcp.example.test/mcp', $form['resource']);
            $this->assertNotEmpty($form['code_verifier']);
            return true;
        })->andReturn(new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'access-secret', 'refresh_token' => 'refresh-secret', 'token_type' => 'Bearer', 'scope' => 'files:read', 'expires_in' => 3600,
        ]))));
        [$id, $query] = $this->start();
        $this->get('/api/connectors/mcp/oauth/callback?'.http_build_query(['state' => $query['state'], 'code' => 'provider-code', 'iss' => 'https://auth.example.test/tenant']))
            ->assertRedirect('/app/autry/admin/mcp-tools?mcp=connected&mcp_connection='.$id);
        $response = $this->getJson('/api/admin/connectors/mcp')->assertOk()->assertJsonPath('0.status', 'active')->assertJsonPath('0.tools.0.remote_name', 'files.search');
        $this->assertStringNotContainsString('access-secret', $response->getContent());
        $this->assertStringNotContainsString('refresh-secret', $response->getContent());
        $this->assertSame(1, McpCredential::query()->count());
        $raw = json_encode(DB::table('mcp_connector_credentials')->first());
        $this->assertStringNotContainsString('access-secret', $raw);
        $this->assertStringNotContainsString('refresh-secret', $raw);
    }

    public function test_cancelled_oauth_returns_to_the_tools_page_without_saving_credentials(): void
    {
        app(SafeHttpClientContract::class)->shouldNotReceive('postForm');
        [$id, $query] = $this->start();
        $this->get('/api/connectors/mcp/oauth/callback?'.http_build_query(['state' => $query['state'], 'error' => 'access_denied', 'iss' => 'https://auth.example.test/tenant']))
            ->assertRedirect('/app/autry/admin/mcp-tools?mcp=oauth_denied&mcp_connection='.$id);
        $this->assertSame(0, McpCredential::query()->count());
    }
}
