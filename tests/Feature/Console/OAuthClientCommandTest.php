<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\OAuthAccessToken;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\Auth\OAuthAccessPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OAuthClientCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_registers_a_public_client_with_exact_callbacks_and_scopes(): void
    {
        $this->artisan('oauth:client', ['name' => 'External app', '--redirect-uri' => ['https://external.test/callback'], '--scope' => ['kb:read']])->assertSuccessful();
        $client = OAuthClient::firstOrFail();
        $this->assertSame(['https://external.test/callback'], $client->redirect_uris);
        $this->assertSame(['kb:read'], $client->scopes);
        $this->assertTrue($client->enabled);
    }

    #[DataProvider('unsafeCallbacks')]
    public function test_rejects_unsafe_callback_urls(string $callback): void
    {
        $this->artisan('oauth:client', ['name' => 'App', '--redirect-uri' => [$callback]])->assertFailed();
        $this->assertDatabaseCount('oauth_clients', 0);
    }

    public static function unsafeCallbacks(): array
    {
        return array_map(static fn ($url) => [$url], [
            'javascript:alert(1)', '//external.test/callback', 'https://user:password@external.test/callback',
            'https://external.test/callback#fragment', 'https://external.test/callback?code=preexisting',
            'https://external.test/callback?state=preexisting', 'https://external.test/callback?iss=preexisting',
            'http://external.com/callback',
        ]);
    }

    public function test_local_http_requires_a_local_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('oauth:client', ['name' => 'App', '--redirect-uri' => ['http://external.test/callback']])->assertFailed();
        $this->app->detectEnvironment(fn () => 'local');
        $this->artisan('oauth:client', ['name' => 'App', '--redirect-uri' => ['http://external.test/callback']])->assertSuccessful();
    }

    public function test_rejects_unknown_scopes_and_missing_callbacks(): void
    {
        $this->artisan('oauth:client', ['name' => 'App', '--redirect-uri' => ['https://external.test/callback'], '--scope' => ['*']])->assertFailed();
        $this->artisan('oauth:client', ['name' => 'App'])->assertFailed();
        $this->assertDatabaseCount('oauth_clients', 0);
    }

    public function test_disabling_client_revokes_only_its_tokens(): void
    {
        $client = OAuthClient::create(['name' => 'App', 'redirect_uris' => ['https://external.test/callback'], 'scopes' => ['kb:read']]);
        $user = User::create(['name' => 'User', 'email' => 'user@example.test', 'password' => 'secret123']);
        $user->createToken('desktop', ['kb:read']);
        $pat = $user->createToken('oauth', ['kb:read', OAuthAccessPolicy::TOKEN_MARKER]);
        OAuthAccessToken::create(['client_id' => $client->id, 'user_id' => $user->id, 'tenant_id' => 'acme',
            'personal_access_token_id' => $pat->accessToken->id, 'scopes' => ['kb:read']]);
        $this->artisan('oauth:client-disable', ['client' => $client->id])->assertSuccessful();
        $this->assertFalse($client->fresh()->enabled);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }
}
