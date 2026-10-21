<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Auth;

use App\Models\OAuthAccessToken;
use App\Models\OAuthAuthorizationCode;
use App\Models\OAuthClient;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Auth\OAuthAccessPolicy;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PactAuthTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK = 'https://sorgente.test/acme-company/integrations/pact-auth/askmydocs/callback';
    private User $user;
    private OAuthClient $client;
    private string $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://askmydocsdev.test', 'pact-auth.enabled' => true,
            'pact-auth.allowed_client_origins' => ['https://sorgente.test'],
            'pact-auth.dynamic_registration' => true, 'pact-auth.allow_local_http' => false]);
        Http::preventStrayRequests();
        Mail::fake();
        app(TenantContext::class)->set('acme');
        foreach (['acme', 'other'] as $tenant) {
            Tenant::updateOrCreate(['slug' => $tenant], ['name' => ucfirst($tenant), 'status' => 'active', 'is_system' => false]);
        }
        $this->user = User::create(['name' => 'Pact User', 'email' => 'pact@example.test', 'password' => 'password']);
        $this->user->forceFill(['email_verified_at' => now()])->save();
        foreach (['acme', 'other'] as $tenant) {
            ProjectMembership::create(['user_id' => $this->user->id, 'tenant_id' => $tenant,
                'project_key' => $tenant === 'acme' ? 'engineering' : 'legal', 'role' => 'admin']);
        }
        $this->client = OAuthClient::create(['name' => 'Sorgente',
            'redirect_uris' => [self::CALLBACK], 'scopes' => ['pact:delegate']]);
        $this->verifier = str_repeat('v', 96);
    }

    private function registration(array $overrides = []): array
    {
        return array_replace(['client_name' => 'Sorgente', 'redirect_uris' => [self::CALLBACK],
            'grant_types' => ['authorization_code'], 'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none'], $overrides);
    }

    private function start(array $overrides = []): string
    {
        $response = $this->get('/oauth/authorize?'.http_build_query(array_replace([
            'response_type' => 'code', 'client_id' => $this->client->id, 'redirect_uri' => self::CALLBACK,
            'scope' => 'pact:delegate', 'state' => 'state%+&unchanged', 'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '='),
        ], $overrides)))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        return $query['request'];
    }

    private function code(): string
    {
        $handle = $this->start();
        $response = $this->actingAsWithoutTenant($this->user, 'web')->postJson('/api/oauth/authorization/'.$handle,
            ['decision' => 'approve', 'tenant_id' => 'acme', 'project_key' => 'engineering'])->assertOk();
        parse_str(parse_url($response->json('redirect_to'), PHP_URL_QUERY), $params);
        $this->assertSame('state%+&unchanged', $params['state']);
        $this->assertSame('https://askmydocsdev.test', $params['iss']);
        Auth::forgetGuards();
        return $params['code'];
    }

    private function exchange(string $code, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/oauth/token', array_replace(['grant_type' => 'authorization_code',
            'code' => $code, 'client_id' => $this->client->id, 'redirect_uri' => self::CALLBACK,
            'code_verifier' => $this->verifier], $overrides), ['Accept' => 'application/json']);
    }

    private function token(): string
    {
        $token = $this->exchange($this->code())->assertOk()->json('access_token');
        Auth::forgetGuards();
        return $token;
    }

    public function test_public_discovery_uses_only_the_configured_origin_and_is_honest_about_the_transport_gap(): void
    {
        $metadata = $this->getJson('/.well-known/oauth-authorization-server', ['Host' => 'attacker.test'])->assertOk()
            ->assertHeaderMissing('Location')->assertJsonPath('issuer', 'https://askmydocsdev.test')
            ->assertJsonPath('pact.producer_id', 'urn:askmydocs:pact')
            ->assertJsonPath('pact.transport_available', false)->json();
        $this->assertContains('pact:delegate', $metadata['scopes_supported']);
        foreach (['authorization_endpoint', 'token_endpoint', 'registration_endpoint'] as $key) {
            $this->assertStringStartsWith($metadata['issuer'].'/', $metadata[$key]);
            $this->assertNull(parse_url($metadata[$key], PHP_URL_QUERY));
        }
        foreach (['endpoint', 'card_url'] as $key) {
            $this->assertStringStartsWith($metadata['issuer'].'/', $metadata['pact'][$key]);
        }
        $this->assertStringNotContainsString('engineering', json_encode($metadata));
        $this->assertStringNotContainsString('pact@example.test', json_encode($metadata));
        $this->getJson('/.well-known/oauth-authorization-server/oauth/mcp')->assertOk()->assertJsonMissingPath('pact')
            ->assertJsonPath('scopes_supported', ['mcp:read', 'mcp:ingest']);
    }

    public function test_feature_flag_hides_discovery_and_prevents_new_authorization(): void
    {
        config(['pact-auth.enabled' => false]);
        $metadata = $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->assertJsonMissingPath('pact')->json();
        $this->assertNotContains('pact:delegate', $metadata['scopes_supported']);
        $this->postJson('/oauth/register', $this->registration())->assertBadRequest();
        $this->getJson('/oauth/authorize?'.http_build_query(['response_type' => 'code', 'client_id' => $this->client->id,
            'redirect_uri' => self::CALLBACK, 'scope' => 'pact:delegate', 'state' => 'state',
            'code_challenge_method' => 'S256', 'code_challenge' => str_repeat('x', 43)]))->assertBadRequest();
    }

    public function test_sorgente_registers_a_public_code_only_client_without_granting_any_access(): void
    {
        $registration = $this->postJson('/oauth/register', $this->registration())->assertCreated()
            ->assertJsonPath('token_endpoint_auth_method', 'none')->assertJsonPath('redirect_uris', [self::CALLBACK])
            ->assertJsonPath('grant_types', ['authorization_code'])->assertJsonPath('scope', 'pact:delegate')
            ->assertJsonMissingPath('client_secret')->json();
        $this->assertNotEmpty($registration['client_id']);
        $this->assertDatabaseCount('oauth_authorization_codes', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_registration_cannot_request_refresh_or_widen_the_pact_scope(): void
    {
        foreach ([['grant_types' => ['authorization_code', 'refresh_token']], ['scope' => 'pact:delegate kb:read']] as $override) {
            $this->postJson('/oauth/register', $this->registration($override))->assertBadRequest();
        }
        $this->assertDatabaseCount('oauth_clients', 1);
    }

    public function test_invalid_issuer_configuration_never_publishes_endpoints_on_another_origin(): void
    {
        foreach (['https://user@askmydocsdev.test', 'https://askmydocsdev.test/subpath', 'https://askmydocsdev.test?query=1'] as $url) {
            config(['app.url' => $url]);
            $this->getJson('/.well-known/oauth-authorization-server')->assertStatus(503)->assertJsonPath('error', 'server_error');
        }
    }

    #[DataProvider('unsafeCallbacks')]
    public function test_registration_rejects_unauthorized_callback_origins_and_paths(string $callback): void
    {
        $this->postJson('/oauth/register', $this->registration(['redirect_uris' => [$callback]]))
            ->assertBadRequest()->assertJsonPath('error', 'invalid_redirect_uri');
        $this->assertDatabaseCount('oauth_clients', 1);
    }

    public static function unsafeCallbacks(): array
    {
        return array_map(fn (string $uri) => [$uri], [
            'https://evil.test/acme-company/integrations/pact-auth/askmydocs/callback',
            'https://sorgente.test.evil.test/acme/integrations/pact-auth/askmydocs/callback',
            'https://sorgente.test:444/acme/integrations/pact-auth/askmydocs/callback',
            'https://user@sorgente.test/acme/integrations/pact-auth/askmydocs/callback',
            self::CALLBACK.'?redirect=https://evil.test', self::CALLBACK.'#fragment',
            'https://sorgente.test/*/integrations/pact-auth/askmydocs/callback',
            'https://sorgente.test/acme/integrations/pact-auth/tixora/callback',
            'https://sorgente.test/acme/../integrations/pact-auth/askmydocs/callback',
            'https://sorgente.test/acme%2fother/integrations/pact-auth/askmydocs/callback',
            'https://sorgente.test/acme/integrations/pact-auth/askmydocs/callback/',
            'http://sorgente.test/acme/integrations/pact-auth/askmydocs/callback',
        ]);
    }

    public function test_local_http_requires_explicit_configuration_and_is_always_rejected_in_production(): void
    {
        $callback = str_replace('https:', 'http:', self::CALLBACK);
        config(['pact-auth.allowed_client_origins' => ['http://sorgente.test']]);
        $this->postJson('/oauth/register', $this->registration(['redirect_uris' => [$callback]]))->assertBadRequest();
        config(['pact-auth.allow_local_http' => true]);
        $this->postJson('/oauth/register', $this->registration(['redirect_uris' => [$callback]]))->assertCreated();
        $this->app['env'] = 'production';
        $this->postJson('/oauth/register', $this->registration(['redirect_uris' => [$callback]]))->assertBadRequest();
    }

    public function test_preregistered_public_clients_work_with_dynamic_registration_disabled(): void
    {
        config(['pact-auth.dynamic_registration' => false]);
        $this->postJson('/oauth/register', $this->registration())->assertBadRequest();
        $this->artisan('pact-auth:client', ['--redirect-uri' => [self::CALLBACK]])->assertSuccessful();
        $this->client = OAuthClient::latest('created_at')->where('id', '!=', $this->client->id)->firstOrFail();
        $this->exchange($this->code())->assertOk()->assertJsonMissingPath('refresh_token');
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_consent_requires_login_and_verified_email_without_sending_verification_email(): void
    {
        $id = $this->start();
        $this->getJson('/api/oauth/authorization/'.$id)->assertUnauthorized();
        $this->user->forceFill(['email_verified_at' => null])->save();
        $this->actingAsWithoutTenant($this->user, 'web')->getJson('/api/oauth/authorization/'.$id)->assertForbidden();
        $this->postJson('/api/oauth/authorization/'.$id, ['decision' => 'approve', 'tenant_id' => 'acme', 'project_key' => 'engineering'])->assertForbidden();
        Mail::assertNothingOutgoing();
        $this->assertDatabaseCount('oauth_authorization_codes', 0);
    }

    public function test_consent_lists_only_memberships_and_demands_an_explicit_project(): void
    {
        $id = $this->start();
        $this->actingAsWithoutTenant($this->user, 'web')->getJson('/api/oauth/authorization/'.$id)->assertOk()
            ->assertJsonPath('requires_project', true)->assertJsonPath('requires_explicit_selection', true)
            ->assertJsonCount(2, 'teams')->assertJsonPath('teams.0.projects.0.project_key', 'engineering');
        foreach ([['tenant_id' => 'acme'], ['tenant_id' => 'acme', 'project_key' => 'legal'],
            ['tenant_id' => 'victim', 'project_key' => 'engineering'], ['tenant_id' => 'acme', 'project_key' => '*']] as $choice) {
            $this->postJson('/api/oauth/authorization/'.$id, ['decision' => 'approve', ...$choice])->assertForbidden();
        }
        $this->assertDatabaseCount('oauth_authorization_codes', 0);
    }

    public function test_admin_role_never_substitutes_for_explicit_project_membership(): void
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->user->assignRole('super-admin');
        ProjectMembership::where('user_id', $this->user->id)->delete();
        $id = $this->start();
        $this->actingAsWithoutTenant($this->user, 'web')->getJson('/api/oauth/authorization/'.$id)
            ->assertOk()->assertJsonCount(0, 'teams');
        $this->postJson('/api/oauth/authorization/'.$id, ['decision' => 'approve', 'tenant_id' => 'acme', 'project_key' => 'engineering'])->assertForbidden();
        $this->assertDatabaseCount('project_memberships', 0);
        $this->assertDatabaseCount('oauth_authorization_codes', 0);
    }

    public function test_rbac_permission_removal_invalidates_a_pact_grant(): void
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->user->assignRole('viewer');
        $token = $this->token();
        $this->user->syncRoles([]);
        $this->withToken($token)->getJson('/.well-known/agent-card.json')->assertUnauthorized();
    }

    public function test_backchannel_revocation_accepts_the_opaque_bearer_without_affecting_legacy_tokens(): void
    {
        $token = $this->token();
        $legacy = $this->user->createToken('Legacy', ['*'])->plainTextToken;
        $this->post('/oauth/revoke', ['client_id' => $this->client->id, 'token' => $token])->assertOk();
        $this->post('/oauth/revoke', ['client_id' => $this->client->id, 'token' => $token])->assertOk();
        $this->withToken($token)->postJson('/api/pact/a2a')->assertUnauthorized();
        $this->assertNotNull(PersonalAccessToken::findToken($legacy));
    }

    public function test_denial_preserves_state_without_issuing_a_code(): void
    {
        $id = $this->start();
        $response = $this->actingAsWithoutTenant($this->user, 'web')->postJson('/api/oauth/authorization/'.$id,
            ['decision' => 'deny'])->assertOk();
        parse_str(parse_url($response->json('redirect_to'), PHP_URL_QUERY), $params);
        $this->assertSame('state%+&unchanged', $params['state']);
        $this->assertSame('access_denied', $params['error']);
        $this->assertArrayNotHasKey('code', $params);
        $this->assertDatabaseCount('oauth_authorization_codes', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_consent_post_is_protected_by_csrf(): void
    {
        $id = $this->start();
        $this->app->instance(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            new class($this->app, $this->app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\PreventRequestForgery {
                protected function runningUnitTests() { return false; }
            });
        $this->actingAsWithoutTenant($this->user, 'web')->postJson('/api/oauth/authorization/'.$id,
            ['decision' => 'approve', 'tenant_id' => 'acme', 'project_key' => 'engineering'])->assertStatus(419);
        $this->assertDatabaseCount('oauth_authorization_codes', 0);
    }

    public function test_form_exchange_returns_a_hashed_revocable_rfc6750_bearer_bound_to_one_project(): void
    {
        $code = $this->code();
        $this->assertDatabaseHas('oauth_authorization_codes', ['code_hash' => hash('sha256', $code), 'pact_project_key' => 'engineering']);
        $response = $this->exchange($code)->assertOk()->assertJsonPath('scope', 'pact:delegate')
            ->assertJsonPath('token_type', 'Bearer')->assertJsonMissingPath('refresh_token')->assertJsonMissingPath('client_secret');
        $token = $response->json('access_token');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._~+\/=-]+$/D', $token);
        $this->assertGreaterThan(0, $response->json('expires_in'));
        $this->assertLessThanOrEqual(31536000, $response->json('expires_in'));
        $pat = PersonalAccessToken::findToken($token);
        $this->assertNotNull($pat);
        $this->assertSame(hash('sha256', $token), $pat->token);
        $this->assertSame(['pact:delegate', OAuthAccessPolicy::TOKEN_MARKER], $pat->abilities);
        $grant = OAuthAccessToken::firstOrFail();
        $this->assertSame('engineering', $grant->pact_project_key);
        $this->assertSame('acme', $grant->tenant_id);
        $this->assertSame($this->user->id, $grant->user_id);
        $this->assertSame($this->client->id, $grant->client_id);
        $this->assertStringNotContainsString($token, json_encode($grant));
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/.well-known/agent-card.json')->assertStatus(501)
            ->assertJsonPath('error', 'pact_transport_unavailable')->assertJsonPath('supported_contracts', []);
        Mail::assertNothingOutgoing();
        Http::assertNothingSent();
    }

    #[DataProvider('badVerifiers')]
    public function test_missing_or_wrong_pkce_never_mints_a_token(?string $verifier): void
    {
        $code = $this->code();
        $this->exchange($code, ['code_verifier' => $verifier])->assertBadRequest();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->exchange($code)->assertOk();
    }

    public static function badVerifiers(): array
    {
        return [[null], ['short'], [str_repeat('x', 96)]];
    }

    public function test_code_expiry_replay_wrong_client_and_exact_callback_are_enforced(): void
    {
        $code = $this->code();
        $other = OAuthClient::create(['name' => 'Other', 'redirect_uris' => [self::CALLBACK], 'scopes' => ['pact:delegate']]);
        $this->exchange($code, ['client_id' => $other->id])->assertBadRequest();
        $otherCallback = str_replace('acme-company', 'other-company', self::CALLBACK);
        $this->exchange($code, ['redirect_uri' => $otherCallback])->assertBadRequest();
        $this->client->update(['redirect_uris' => [self::CALLBACK, $otherCallback]]);
        $this->exchange($code, ['redirect_uri' => $otherCallback])->assertBadRequest();
        $this->exchange($code)->assertOk();
        $this->exchange($code)->assertBadRequest();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $next = $this->code();
        $this->travel(6)->minutes();
        $this->exchange($next)->assertBadRequest();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_concurrent_consumption_between_read_and_update_cannot_issue_another_token(): void
    {
        $code = $this->code();
        $interleaved = false;
        DB::listen(function ($query) use (&$interleaved, $code) {
            if (! $interleaved && str_starts_with(strtolower($query->sql), 'select')
                && str_contains($query->sql, 'oauth_authorization_codes')) {
                $interleaved = true;
                DB::table('oauth_authorization_codes')->where('code_hash', hash('sha256', $code))->update(['consumed_at' => now()]);
            }
        });
        $this->exchange($code)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->assertTrue($interleaved);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[DataProvider('authorityChanges')]
    public function test_changed_authority_prevents_exchange_and_invalidates_existing_tokens(string $change): void
    {
        $token = $this->token();
        $this->flushHeaders();
        $code = $this->code();
        match ($change) {
            'membership' => ProjectMembership::forTenant('acme')->where('user_id', $this->user->id)->delete(),
            'role' => ProjectMembership::forTenant('acme')->where('user_id', $this->user->id)->update(['role' => 'member']),
            'folder scope' => ProjectMembership::forTenant('acme')->where('user_id', $this->user->id)->update(['scope_allowlist' => json_encode(['folder_globs' => ['public/**']])]),
            'inactive' => $this->user->update(['is_active' => false]),
            'unverified' => $this->user->forceFill(['email_verified_at' => null])->save(),
            'suspended' => Tenant::where('slug', 'acme')->update(['status' => 'suspended']),
            'client disabled' => $this->client->update(['enabled' => false]),
            'client scope' => $this->client->update(['scopes' => ['kb:read']]),
            'feature flag' => config(['pact-auth.enabled' => false]),
            'client origin' => config(['pact-auth.allowed_client_origins' => []]),
        };
        $this->exchange($code)->assertBadRequest();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/.well-known/agent-card.json')->assertUnauthorized();
    }

    public static function authorityChanges(): array
    {
        return array_map(fn ($change) => [$change], ['membership', 'role', 'folder scope', 'inactive', 'unverified', 'suspended',
            'client disabled', 'client scope', 'feature flag', 'client origin']);
    }

    public function test_pact_bearer_cannot_use_other_rest_or_mcp_routes_or_change_tenants(): void
    {
        $token = $this->token();
        foreach (['/oauth/userinfo', '/api/kb/tree', '/api/admin/users', '/api/oauth/connections'] as $path) {
            Auth::forgetGuards();
            $this->withToken($token)->getJson($path)->assertUnauthorized();
        }
        $this->withToken($token)->getJson('/.well-known/agent-card.json', ['X-Tenant-Id' => 'other'])->assertUnauthorized();
        $this->withToken($token)->postJson('/mcp/kb', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
        $result = $this->withToken($token)->postJson('/api/pact/a2a', ['tenant_id' => 'other', 'project_key' => 'legal', 'method' => 'SendMessage'])
            ->assertStatus(501)->assertJsonPath('error', 'pact_transport_unavailable');
        $this->assertStringNotContainsString('legal', $result->getContent());
        $this->assertSame('acme', OAuthAccessToken::firstOrFail()->tenant_id);
        $this->assertSame('engineering', OAuthAccessToken::firstOrFail()->pact_project_key);
    }

    public function test_pact_endpoints_refuse_sessions_and_legacy_tokens(): void
    {
        $this->actingAsWithoutTenant($this->user, 'web')->getJson('/.well-known/agent-card.json')->assertUnauthorized();
        $legacy = $this->user->createToken('Legacy', ['*'])->plainTextToken;
        $this->withToken($legacy)->postJson('/api/pact/a2a')->assertUnauthorized();
        $this->assertNotNull(PersonalAccessToken::findToken($legacy));
    }

    public function test_bearer_gate_uses_the_pinned_project_and_restores_all_tenant_contexts(): void
    {
        $token = $this->token();
        $host = app(TenantContext::class);
        $connector = app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class);
        $compliance = app(\Padosoft\AiActCompliance\MultiTenancy\Services\TenantContext::class);
        $host->set('other');
        $connector->set('other');
        $compliance->set(null);
        $request = \Illuminate\Http\Request::create('/api/pact/a2a', 'POST', ['tenant_id' => 'other', 'project_key' => 'legal']);
        $request->headers->set('Authorization', 'Bearer '.$token);
        $resolver = static fn () => null;
        $request->setUserResolver($resolver);
        $middleware = new \App\Http\Middleware\AuthenticatePactDelegation;
        $response = $middleware->handle($request, function ($request) use ($host, $connector, $compliance) {
            $this->assertSame('acme', $host->current());
            $this->assertSame('acme', $connector->current());
            $this->assertSame('acme', $compliance->currentSlug());
            $this->assertSame('engineering', $request->attributes->get('pact.grant')->pact_project_key);
            return response('', 204);
        });
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('other', $host->current());
        $this->assertSame('other', $connector->current());
        $this->assertNull($compliance->current());
        $this->assertSame($resolver, $request->getUserResolver());
    }

    public function test_expired_or_deleted_grants_cannot_authenticate(): void
    {
        $token = $this->token();
        $this->travel(2)->days();
        $this->withToken($token)->getJson('/.well-known/agent-card.json')->assertUnauthorized();
        $this->travelBack();
        OAuthAccessToken::query()->delete();
        $this->withToken($token)->postJson('/api/pact/a2a')->assertUnauthorized();
    }

    public function test_management_shows_the_project_without_secrets_and_revokes_only_the_selected_grant(): void
    {
        $token = $this->token();
        $legacy = $this->user->createToken('Legacy', ['*'])->plainTextToken;
        $grant = OAuthAccessToken::firstOrFail();
        $this->actingAsWithoutTenant($this->user, 'web')->getJson('/api/oauth/connections')->assertOk()
            ->assertJsonPath('connections.0.project_key', 'engineering')->assertDontSee($token);
        $this->deleteJson('/api/oauth/connections/'.$grant->id)->assertNoContent();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/.well-known/agent-card.json')->assertUnauthorized();
        $this->assertNotNull(PersonalAccessToken::findToken($legacy));
    }

    public function test_binding_migration_rollback_revokes_only_pact_grants(): void
    {
        $token = $this->token();
        $legacyClient = OAuthClient::create(['name' => 'Legacy REST', 'redirect_uris' => ['https://legacy.test/callback'], 'scopes' => ['kb:read']]);
        $legacy = $this->user->createToken('Legacy REST', ['kb:read', OAuthAccessPolicy::TOKEN_MARKER]);
        $grant = OAuthAccessToken::create(['personal_access_token_id' => $legacy->accessToken->id,
            'user_id' => $this->user->id, 'tenant_id' => 'acme', 'client_id' => $legacyClient->id, 'scopes' => ['kb:read']]);
        $migration = require dirname(__DIR__, 4).'/database/migrations/2026_10_21_000002_add_pact_oauth_project_bindings.php';
        $migration->down();
        $this->assertNull(PersonalAccessToken::findToken($token));
        $this->assertNotNull(PersonalAccessToken::findToken($legacy->plainTextToken));
        $this->assertDatabaseHas('oauth_access_tokens', ['id' => $grant->id]);
        $migration->up();
    }
}
