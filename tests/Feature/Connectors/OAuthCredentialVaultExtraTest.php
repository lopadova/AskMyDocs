<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Auth\OAuthCredentialVault;
use App\Models\ConnectorCredential;
use App\Models\ConnectorInstallation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v4.5/W2 — OAuthCredentialVault::getExtraKey() / setExtraKey() tests.
 *
 * These granular helpers were extracted during W2 refinement so
 * connectors can mutate one cursor/metadata field at a time without
 * re-threading access/refresh/expiry through setCredentials(). They
 * also preserve siblings — clobbering "bot_id" while writing
 * "changes_page_token" would be a regression.
 */
final class OAuthCredentialVaultExtraTest extends TestCase
{
    use RefreshDatabase;

    private function vault(): OAuthCredentialVault
    {
        return $this->app->make(OAuthCredentialVault::class);
    }

    private function makeInstallationWithCredential(array $initialExtra = []): ConnectorInstallation
    {
        $user = User::create([
            'name' => 'Tester',
            'email' => 'u-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);

        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'notion',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => $user->id,
        ]);

        ConnectorCredential::create([
            'tenant_id' => 'default',
            'connector_installation_id' => $installation->id,
            'encrypted_access_token' => Crypt::encryptString('AT-xyz'),
            'encrypted_refresh_token' => null,
            'expires_at' => Carbon::now()->addYears(10),
            'extra_json' => $initialExtra === [] ? null : $initialExtra,
        ]);

        return $installation;
    }

    public function test_setExtra_stores_value_in_extra_json(): void
    {
        $installation = $this->makeInstallationWithCredential();

        $this->vault()->setExtraKey($installation->id, 'bot_id', 'bot-123');

        $row = ConnectorCredential::query()
            ->where('connector_installation_id', $installation->id)
            ->first();

        $this->assertSame('bot-123', $row->extra_json['bot_id']);
    }

    public function test_getExtra_returns_null_for_missing_key(): void
    {
        $installation = $this->makeInstallationWithCredential([
            'workspace_id' => 'ws-1',
        ]);

        $this->assertNull(
            $this->vault()->getExtraKey($installation->id, 'never_set')
        );
    }

    public function test_setExtra_overrides_existing_value(): void
    {
        $installation = $this->makeInstallationWithCredential([
            'bot_id' => 'bot-old',
        ]);

        $this->vault()->setExtraKey($installation->id, 'bot_id', 'bot-new');

        $this->assertSame(
            'bot-new',
            $this->vault()->getExtraKey($installation->id, 'bot_id')
        );
    }

    public function test_setExtra_preserves_other_keys(): void
    {
        $installation = $this->makeInstallationWithCredential([
            'bot_id' => 'bot-123',
            'workspace_id' => 'ws-456',
            'workspace_name' => 'Acme Workspace',
        ]);

        $this->vault()->setExtraKey($installation->id, 'changes_page_token', 'cursor-1');

        $extra = $this->vault()->getExtra($installation->id);
        $this->assertSame('bot-123', $extra['bot_id']);
        $this->assertSame('ws-456', $extra['workspace_id']);
        $this->assertSame('Acme Workspace', $extra['workspace_name']);
        $this->assertSame('cursor-1', $extra['changes_page_token']);
    }

    public function test_setExtra_is_noop_when_no_credential_row(): void
    {
        $user = User::create([
            'name' => 'Tester',
            'email' => 'u-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);

        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'notion',
            'status' => ConnectorInstallation::STATUS_PENDING,
            'created_by' => $user->id,
        ]);

        // No credential row exists for this installation — the call
        // is a silent no-op so a sync running against a half-installed
        // connector doesn't crash.
        $this->vault()->setExtraKey($installation->id, 'bot_id', 'bot-x');

        $this->assertDatabaseMissing('connector_credentials', [
            'connector_installation_id' => $installation->id,
        ]);
    }

    public function test_setExtra_does_not_corrupt_encrypted_tokens(): void
    {
        $installation = $this->makeInstallationWithCredential();

        $this->vault()->setExtraKey($installation->id, 'workspace_id', 'ws-1');

        // The access token must still decrypt to its original value.
        $this->assertSame('AT-xyz', $this->vault()->getAccessToken($installation->id));
    }
}
