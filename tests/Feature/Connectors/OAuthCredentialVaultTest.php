<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Auth\OAuthCredentialVault;
use App\Models\ConnectorCredential;
use App\Models\ConnectorInstallation;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v4.5/W1 — OAuthCredentialVault tests: encryption-at-rest,
 * tenant scoping (R30), credential lifecycle.
 */
final class OAuthCredentialVaultTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(?string $email = null): User
    {
        return User::create([
            'name' => 'Tester',
            'email' => $email ?? ('u-'.uniqid().'@demo.local'),
            'password' => Hash::make('secret123'),
        ]);
    }

    private function vault(): OAuthCredentialVault
    {
        return $this->app->make(OAuthCredentialVault::class);
    }

    private function tenantContext(): TenantContext
    {
        return $this->app->make(TenantContext::class);
    }

    public function test_set_credentials_persists_encrypted_tokens(): void
    {
        $user = $this->makeUser();
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_PENDING,
            'created_by' => $user->id,
        ]);

        $this->vault()->setCredentials(
            $installation->id,
            accessToken: 'plain-access-token',
            refreshToken: 'plain-refresh-token',
            expiresAt: Carbon::now()->addHour(),
            extra: ['scope' => 'drive.readonly'],
        );

        $row = ConnectorCredential::query()
            ->where('connector_installation_id', $installation->id)
            ->first();

        $this->assertNotNull($row);

        // The DB row never holds plaintext.
        $this->assertNotSame('plain-access-token', $row->encrypted_access_token);
        $this->assertNotSame('plain-refresh-token', $row->encrypted_refresh_token);

        // The Crypt round-trip recovers the originals.
        $this->assertSame('plain-access-token', Crypt::decryptString($row->encrypted_access_token));
        $this->assertSame('plain-refresh-token', Crypt::decryptString($row->encrypted_refresh_token));
        $this->assertSame(['scope' => 'drive.readonly'], $row->extra_json);
    }

    public function test_get_access_token_returns_decrypted_value(): void
    {
        $user = $this->makeUser();
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => $user->id,
        ]);

        $this->vault()->setCredentials(
            $installation->id,
            accessToken: 'plain-access-token',
            expiresAt: Carbon::now()->addHour(),
        );

        $this->assertSame('plain-access-token', $this->vault()->getAccessToken($installation->id));
    }

    public function test_expired_access_token_returns_null(): void
    {
        $user = $this->makeUser();
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => $user->id,
        ]);

        $this->vault()->setCredentials(
            $installation->id,
            accessToken: 'plain-access-token',
            refreshToken: 'plain-refresh-token',
            // Expired 1 second ago.
            expiresAt: Carbon::now()->subSecond(),
        );

        $this->assertNull($this->vault()->getAccessToken($installation->id));

        // Refresh token is still recoverable so the connector can mint
        // a new access token.
        $this->assertSame('plain-refresh-token', $this->vault()->getRefreshToken($installation->id));
    }

    public function test_tenant_isolation_blocks_cross_tenant_access(): void
    {
        $userA = $this->makeUser('a@demo.local');
        $userB = $this->makeUser('b@demo.local');

        $installationA = ConnectorInstallation::create([
            'tenant_id' => 'tenant-a',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => $userA->id,
        ]);

        // Persist credentials under tenant-a's active context.
        $this->tenantContext()->set('tenant-a');
        $this->vault()->setCredentials(
            $installationA->id,
            accessToken: 'tenant-a-secret',
            expiresAt: Carbon::now()->addHour(),
        );

        // Now switch to tenant-b — vault must refuse to return tenant-a's
        // installation's credentials.
        $this->tenantContext()->set('tenant-b');
        $this->assertNull($this->vault()->getAccessToken($installationA->id));
        $this->assertNull($this->vault()->getRefreshToken($installationA->id));
        $this->assertNull($this->vault()->getCredentialRow($installationA->id));
        $this->assertSame([], $this->vault()->getExtra($installationA->id));

        // clearCredentials on a cross-tenant id is a no-op (returns 0).
        $this->assertSame(0, $this->vault()->clearCredentials($installationA->id));
        $this->assertDatabaseHas('connector_credentials', [
            'connector_installation_id' => $installationA->id,
        ]);

        $this->tenantContext()->reset();
    }

    public function test_clear_credentials_removes_row(): void
    {
        $user = $this->makeUser();
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => $user->id,
        ]);

        $this->vault()->setCredentials(
            $installation->id,
            accessToken: 'plain-access-token',
            expiresAt: Carbon::now()->addHour(),
        );

        $this->assertSame(1, $this->vault()->clearCredentials($installation->id));
        $this->assertDatabaseMissing('connector_credentials', [
            'connector_installation_id' => $installation->id,
        ]);

        // Idempotent — second clear returns 0.
        $this->assertSame(0, $this->vault()->clearCredentials($installation->id));
    }

    public function test_set_credentials_overwrites_existing_row(): void
    {
        $user = $this->makeUser();
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => $user->id,
        ]);

        $this->vault()->setCredentials($installation->id, 'token-1');
        $this->vault()->setCredentials($installation->id, 'token-2');

        $this->assertSame(
            1,
            ConnectorCredential::query()
                ->where('connector_installation_id', $installation->id)
                ->count(),
        );
        $this->assertSame('token-2', $this->vault()->getAccessToken($installation->id));
    }

    public function test_set_credentials_throws_when_installation_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->vault()->setCredentials(999_999, 'token');
    }
}
