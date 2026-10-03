<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Auth;

use App\Models\OAuthAccessToken;
use App\Models\OAuthAuthorizationCode;
use App\Models\OAuthClient;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OAuthTest extends TestCase
{
    use RefreshDatabase;

    private OAuthClient $client;
    private User $user;
    private string $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['acme', 'other'] as $tenant) {
            Tenant::updateOrCreate(['slug' => $tenant], ['name' => ucfirst($tenant), 'status' => 'active', 'is_system' => false]);
        }
        app(TenantContext::class)->set('acme');
        $this->user = User::create(['name' => 'OAuth User', 'email' => 'oauth@example.test', 'password' => 'secret123']);
        foreach (['acme', 'other'] as $tenant) {
            ProjectMembership::create(['user_id' => $this->user->id, 'tenant_id' => $tenant, 'project_key' => 'docs', 'role' => 'member']);
        }
        $this->client = OAuthClient::create([
            'name' => 'External App', 'redirect_uris' => ['https://external.test/callback?app=one'],
            'scopes' => ['kb:read', 'kb:chat'],
        ]);
        $this->verifier = str_repeat('v', 64);
        // Inspect the real guard + tenant/ability middleware without running
        // retrieval or spending on an AI provider.
        Route::get('/api/kb/tree', fn () => response()->json(['tenant_id' => app(TenantContext::class)->current()]))
            ->middleware(['api', 'auth:sanctum', 'tenant.authorize', 'token.ability:kb:read']);
    }

    private function parameters(array $overrides = []): array
    {
        return array_replace([
            'client_id' => $this->client->id, 'redirect_uri' => $this->client->redirect_uris[0],
            'response_type' => 'code', 'state' => 'csrf-state', 'scope' => 'kb:read kb:chat',
            'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '='),
        ], $overrides);
    }

    private function start(array $overrides = []): string
    {
        $response = $this->get('/oauth/authorize?'.http_build_query($this->parameters($overrides)));
        $response->assertRedirect()->assertHeader('Cache-Control', 'no-store, private');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        return $query['request'];
    }

    private function code(array $overrides = []): string
    {
        $id = $this->start($overrides);
        $response = $this->actingAsWithoutTenant($this->user, 'web')->postJson('/api/oauth/authorization/'.$id, [
            'decision' => 'approve', 'tenant_id' => 'acme',
        ])->assertOk();
        parse_str(parse_url($response->json('redirect_to'), PHP_URL_QUERY), $query);
        $this->assertSame('csrf-state', $query['state']);
        $this->assertSame('one', $query['app']);
        $this->assertArrayNotHasKey('access_token', $query);
        Auth::forgetGuards();
        return $query['code'];
    }

    private function exchange(string $code, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/oauth/token', array_replace([
            'grant_type' => 'authorization_code', 'client_id' => $this->client->id,
            'code' => $code, 'redirect_uri' => $this->client->redirect_uris[0], 'code_verifier' => $this->verifier,
        ], $overrides));
    }

    public function test_guest_can_start_but_cannot_consent_before_login(): void
    {
        $id = $this->start();
        $this->getJson('/api/oauth/authorization/'.$id)->assertUnauthorized();
        $this->postJson('/api/oauth/authorization/'.$id, ['decision' => 'approve', 'tenant_id' => 'acme'])->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_preserves_the_request_and_shows_exact_permissions_and_active_companies(): void
    {
        $id = $this->start(['scope' => 'kb:read']);
        Tenant::where('slug', 'other')->update(['status' => 'suspended']);
        $this->postJson('/api/auth/login', ['email' => $this->user->email, 'password' => 'secret123'])->assertOk();
        $this->getJson('/api/oauth/authorization/'.$id)->assertOk()
            ->assertJsonPath('client.name', 'External App')->assertJsonPath('scopes.0.id', 'kb:read')
            ->assertJsonCount(1, 'scopes')->assertJsonCount(1, 'teams')->assertJsonPath('teams.0.tenant_id', 'acme');
    }

    public function test_approval_exchange_and_bearer_authentication_are_usable_and_tenant_bound(): void
    {
        $code = $this->code();
        $this->assertDatabaseMissing('oauth_authorization_codes', ['code_hash' => $code]);
        $this->assertDatabaseHas('oauth_authorization_codes', ['code_hash' => hash('sha256', $code)]);
        $response = $this->exchange($code)->assertOk()->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('scope', 'kb:read kb:chat')->assertJsonPath('tenant_id', 'acme')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertEqualsWithDelta(30 * 86400, $response->json('expires_in'), 5);
        $pat = PersonalAccessToken::firstOrFail();
        $this->assertFalse($pat->can('*'));
        $this->assertNotNull($pat->expires_at);
        $this->assertNotSame($response->json('access_token'), $pat->token);
        $token = $response->json('access_token');
        $this->withToken($token)->getJson('/oauth/userinfo')->assertOk()->assertJsonPath('sub', (string) $this->user->id);
        Auth::forgetGuards();
        app(TenantContext::class)->set('other');
        $this->withToken($token)->getJson('/api/kb/tree')->assertOk()->assertJsonPath('tenant_id', 'acme');
    }

    public function test_form_encoded_token_exchange_is_supported(): void
    {
        $code = $this->code();
        $this->post('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $this->client->id,
            'code' => $code, 'redirect_uri' => $this->client->redirect_uris[0], 'code_verifier' => $this->verifier])
            ->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_code_can_only_be_exchanged_once(): void
    {
        $code = $this->code();
        $this->exchange($code)->assertOk();
        $this->exchange($code)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_wrong_verifier_does_not_consume_a_code(): void
    {
        $code = $this->code();
        $this->exchange($code, ['code_verifier' => str_repeat('x', 64)])->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->exchange($code)->assertOk();
    }

    public function test_codes_are_bound_to_the_client_and_exact_callback(): void
    {
        $code = $this->code();
        $other = OAuthClient::create(['name' => 'Other', 'redirect_uris' => $this->client->redirect_uris, 'scopes' => ['kb:read']]);
        $this->exchange($code, ['client_id' => $other->id])->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->exchange($code, ['redirect_uri' => 'https://evil.test/callback'])->assertBadRequest();
        $this->client->update(['redirect_uris' => [...$this->client->redirect_uris, 'https://external.test/another']]);
        $this->exchange($code, ['redirect_uri' => 'https://external.test/another'])->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_expired_requests_and_codes_are_rejected(): void
    {
        $code = $this->code();
        $id = $this->start();
        $this->travel(11)->minutes();
        $this->exchange($code)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->actingAsWithoutTenant($this->user)->getJson('/api/oauth/authorization/'.$id)->assertBadRequest();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_denial_returns_state_without_minting_code_or_token(): void
    {
        $id = $this->start();
        $response = $this->actingAsWithoutTenant($this->user)->postJson('/api/oauth/authorization/'.$id, ['decision' => 'deny'])->assertOk();
        parse_str(parse_url($response->json('redirect_to'), PHP_URL_QUERY), $query);
        $this->assertSame('access_denied', $query['error']);
        $this->assertSame('csrf-state', $query['state']);
        $this->assertDatabaseCount('oauth_authorization_codes', 0);
        $this->postJson('/api/oauth/authorization/'.$id, ['decision' => 'approve', 'tenant_id' => 'acme'])->assertBadRequest();
    }

    #[DataProvider('invalidAuthorizationRequests')]
    public function test_invalid_authorization_never_redirects_to_an_external_url(array $overrides, string $error): void
    {
        $this->getJson('/oauth/authorize?'.http_build_query($this->parameters($overrides)))
            ->assertBadRequest()->assertJsonPath('error', $error)->assertHeaderMissing('Location');
    }

    public static function invalidAuthorizationRequests(): array
    {
        return [
            'callback prefix attack' => [['redirect_uri' => 'https://external.test/callback?app=one.evil'], 'invalid_request'],
            'wrong client' => [['client_id' => 'unknown'], 'invalid_client'],
            'wildcard scope' => [['scope' => '*'], 'invalid_scope'],
            'admin scope' => [['scope' => 'admin'], 'invalid_scope'],
            'plain PKCE' => [['code_challenge_method' => 'plain'], 'invalid_request'],
            'missing challenge' => [['code_challenge' => ''], 'invalid_request'],
            'missing state' => [['state' => ''], 'invalid_request'],
            'implicit flow' => [['response_type' => 'token'], 'invalid_request'],
        ];
    }

    public function test_browser_request_is_bound_to_the_session(): void
    {
        $id = $this->start();
        $this->app['session']->flush();
        $this->actingAsWithoutTenant($this->user)->postJson('/api/oauth/authorization/'.$id,
            ['decision' => 'approve', 'tenant_id' => 'acme'])->assertBadRequest();
    }

    public function test_consent_cannot_select_a_company_without_membership(): void
    {
        $id = $this->start();
        $this->actingAsWithoutTenant($this->user)->postJson('/api/oauth/authorization/'.$id,
            ['decision' => 'approve', 'tenant_id' => 'victim'])->assertForbidden();
        $this->assertDatabaseCount('oauth_authorization_codes', 0);
    }

    #[DataProvider('revokedAuthorizationCases')]
    public function test_changed_authorization_prevents_code_exchange(string $change): void
    {
        $code = $this->code();
        $this->changeAuthorization($change);
        $this->exchange($code)->assertBadRequest();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[DataProvider('revokedAuthorizationCases')]
    public function test_changed_authorization_invalidates_an_issued_api_key(string $change): void
    {
        $token = $this->exchange($this->code())->json('access_token');
        $this->changeAuthorization($change);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();
    }

    public static function revokedAuthorizationCases(): array
    {
        return array_map(static fn ($item) => [$item], ['membership', 'suspended', 'archived', 'inactive', 'client']);
    }

    private function changeAuthorization(string $change): void
    {
        match ($change) {
            'membership' => ProjectMembership::forTenant('acme')->where('user_id', $this->user->id)->delete(),
            'suspended', 'archived' => Tenant::where('slug', 'acme')->update(['status' => $change]),
            'inactive' => $this->user->update(['is_active' => false]),
            'client' => $this->client->update(['enabled' => false]),
        };
    }

    public function test_oauth_key_cannot_switch_tenants_or_access_admin_or_grant_management(): void
    {
        $token = $this->exchange($this->code())->json('access_token');
        foreach (['/api/kb/tree', '/api/admin/users', '/api/system-admin/tenants', '/api/auth/me', '/api/oauth/connections'] as $path) {
            Auth::forgetGuards();
            $this->withToken($token)->getJson($path, ['X-Tenant-Id' => 'other'])->assertUnauthorized();
        }
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/admin/users')->assertUnauthorized();
    }

    public function test_chat_only_key_cannot_read_documents(): void
    {
        $token = $this->exchange($this->code(['scope' => 'kb:chat']))->json('access_token');
        $this->withToken($token)->getJson('/api/kb/tree')->assertUnauthorized();
    }

    public function test_missing_oauth_metadata_and_expiry_fail_closed(): void
    {
        $token = $this->exchange($this->code())->json('access_token');
        $this->travel(31)->days();
        $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();
        $this->travelBack();
        OAuthAccessToken::query()->delete();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();
    }

    public function test_revocation_is_idempotent_and_does_not_revoke_other_client_keys(): void
    {
        $token = $this->exchange($this->code())->json('access_token');
        $this->postJson('/oauth/revoke', ['client_id' => 'other', 'token' => $token])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->postJson('/oauth/revoke', ['client_id' => $this->client->id, 'token' => $token])->assertOk();
        $this->postJson('/oauth/revoke', ['client_id' => $this->client->id, 'token' => $token])->assertOk();
        $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_connection_management_is_session_only_and_owned_by_the_user(): void
    {
        $token = $this->exchange($this->code())->json('access_token');
        $id = OAuthAccessToken::firstOrFail()->id;
        $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'secret123']);
        $this->actingAsWithoutTenant($other)->getJson('/api/oauth/connections')->assertOk()->assertJsonCount(0, 'connections');
        $this->deleteJson('/api/oauth/connections/'.$id)->assertNotFound();
        $this->actingAsWithoutTenant($this->user)->getJson('/api/oauth/connections')->assertOk()
            ->assertJsonCount(1, 'connections')->assertJsonPath('connections.0.name', 'External App');
        $this->deleteJson('/api/oauth/connections/'.$id)->assertNoContent();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();
    }

    public function test_discovery_describes_only_the_supported_flow(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('grant_types_supported', ['authorization_code'])
            ->assertJsonPath('token_endpoint_auth_methods_supported', ['none']);
        $this->postJson('/oauth/token', ['grant_type' => 'password'])->assertBadRequest()->assertJsonPath('error', 'unsupported_grant_type');
    }

    public function test_password_reset_revokes_pending_codes_and_keys(): void
    {
        $code = $this->code();
        $token = $this->exchange($this->code())->json('access_token');
        $this->postJson('/api/auth/reset-password', [
            'token' => \Illuminate\Support\Facades\Password::broker()->createToken($this->user),
            'email' => $this->user->email, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertNoContent();
        $this->exchange($code)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->withToken($token)->getJson('/oauth/userinfo')->assertUnauthorized();
        $this->assertDatabaseCount('oauth_authorization_codes', 0);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_migration_rollback_revokes_oauth_keys_and_preserves_desktop_keys(): void
    {
        $this->exchange($this->code())->assertOk();
        $desktop = $this->user->createToken('desktop', ['kb:read'])->accessToken->id;
        $migration = require dirname(__DIR__, 4).'/database/migrations/2026_10_03_000001_create_oauth_tables.php';
        $migration->down();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $desktop]);
        $migration->up();
        $this->assertDatabaseCount('oauth_clients', 0);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_consent_requires_csrf_even_with_a_valid_session(): void
    {
        $id = $this->start();
        $this->app->instance(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            new class($this->app, $this->app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\PreventRequestForgery {
                protected function runningUnitTests() { return false; }
            });
        $this->actingAsWithoutTenant($this->user)->postJson('/api/oauth/authorization/'.$id,
            ['decision' => 'approve', 'tenant_id' => 'acme'])->assertStatus(419);
        $this->assertDatabaseCount('oauth_authorization_codes', 0);
    }

    public function test_token_requests_are_throttled_without_blocking_revocation(): void
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/oauth/token', ['grant_type' => 'password'])->assertBadRequest();
        }
        $this->postJson('/oauth/token', ['grant_type' => 'password'])->assertStatus(429);
        $this->postJson('/oauth/revoke', ['client_id' => $this->client->id, 'token' => 'unknown'])->assertOk();
    }
}
