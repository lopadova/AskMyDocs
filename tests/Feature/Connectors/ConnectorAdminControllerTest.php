<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Jobs\ConnectorSyncJob;
use App\Models\ConnectorCredential;
use App\Models\ConnectorInstallation;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * v4.5/W1 — REST surface coverage for the connector admin endpoints.
 *
 * Auth posture: every endpoint sits behind `can:manageConnectors`
 * (super-admin only by default). Cross-tenant isolation enforced
 * inside the controller via TenantContext::current().
 */
final class ConnectorAdminControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
    }

    public function test_index_returns_available_connectors_with_installation_status_for_tenant(): void
    {
        $admin = $this->makeSuperAdmin();

        // Pre-install google-drive for the active (default) tenant.
        ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'last_sync_at' => Carbon::parse('2026-05-15T10:00:00Z'),
            'created_by' => $admin->id,
        ]);

        $resp = $this->actingAs($admin)->getJson('/api/admin/connectors');

        $resp->assertOk();
        $data = $resp->json('data');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);

        $byKey = collect($data)->keyBy('key');
        $this->assertTrue($byKey->has('google-drive'));
        $this->assertSame('Google Drive', $byKey['google-drive']['display_name']);
        $this->assertSame(
            ConnectorInstallation::STATUS_ACTIVE,
            $byKey['google-drive']['installation']['status'],
        );
        $this->assertNotNull($byKey['google-drive']['installation']['last_sync_at']);
    }

    public function test_start_install_returns_oauth_url_with_state_token(): void
    {
        $admin = $this->makeSuperAdmin();

        $resp = $this->actingAs($admin)
            ->getJson('/api/admin/connectors/google-drive/install');

        $resp->assertOk();
        $installationId = $resp->json('data.installation_id');
        $redirectTo = $resp->json('data.redirect_to');

        $this->assertIsInt($installationId);
        $this->assertIsString($redirectTo);
        $this->assertStringContainsString('accounts.google.com', $redirectTo);
        $this->assertStringContainsString('drive.readonly', urldecode($redirectTo));
        $this->assertStringContainsString('state=', $redirectTo);

        $this->assertDatabaseHas('connector_installations', [
            'id' => $installationId,
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_PENDING,
        ]);
    }

    public function test_oauth_callback_persists_credentials_and_marks_installation_active(): void
    {
        $admin = $this->makeSuperAdmin();

        // First initiate install so the installation row exists +
        // the state token is in the cache.
        $startResp = $this->actingAs($admin)
            ->getJson('/api/admin/connectors/google-drive/install');
        $startResp->assertOk();
        $redirectTo = $startResp->json('data.redirect_to');
        parse_str(parse_url($redirectTo, PHP_URL_QUERY), $query);
        $state = $query['state'] ?? '';

        // Stub the token-exchange endpoint.
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-access-token',
                'refresh_token' => 'fresh-refresh-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/drive.readonly',
            ], 200),
        ]);

        $resp = $this->actingAs($admin)
            ->getJson('/api/admin/connectors/google-drive/oauth/callback?code=auth-code&state='.$state);

        $resp->assertOk();
        $installationId = $resp->json('data.installation_id');
        $this->assertSame(ConnectorInstallation::STATUS_ACTIVE, $resp->json('data.status'));

        $this->assertDatabaseHas('connector_credentials', [
            'connector_installation_id' => $installationId,
        ]);

        $row = ConnectorCredential::query()
            ->where('connector_installation_id', $installationId)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(
            'fresh-access-token',
            \Illuminate\Support\Facades\Crypt::decryptString($row->encrypted_access_token),
        );
    }

    public function test_oauth_callback_rejects_invalid_state_token(): void
    {
        $admin = $this->makeSuperAdmin();

        // Pre-create a pending installation but DON'T issue a state.
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_PENDING,
            'created_by' => $admin->id,
        ]);

        $resp = $this->actingAs($admin)
            ->getJson('/api/admin/connectors/google-drive/oauth/callback?code=auth-code&state=garbage');

        $resp->assertStatus(400);
        $this->assertStringContainsString('state token', strtolower($resp->json('error')));

        // iter2 finding #5 — OAuth failure leaves the row PENDING so
        // the operator can retry by re-clicking Install. The error
        // message is recorded in `error_json` for surface visibility
        // but the status itself does NOT flip to ERRORED.
        $installation->refresh();
        $this->assertSame(ConnectorInstallation::STATUS_PENDING, $installation->status);
        $this->assertNotNull($installation->error_json);
        $this->assertStringContainsString('state token', strtolower($installation->error_json['message']));
    }

    /**
     * iter2 finding #4 — clicking Install on an ACTIVE row re-arms it
     * to PENDING and clears error_json so the OAuth round-trip can
     * complete via the standard `oauthCallback()` path (which only
     * matches PENDING rows). Without this, reinstall + scope expansion
     * is impossible — the callback 404s because no PENDING row exists.
     */
    public function test_reinstall_arms_existing_active_row_to_pending(): void
    {
        $admin = $this->makeSuperAdmin();

        $active = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'last_sync_at' => Carbon::parse('2026-05-15T10:00:00Z'),
            'error_json' => null,
            'created_by' => $admin->id,
        ]);
        $originalId = $active->id;

        $resp = $this->actingAs($admin)
            ->getJson('/api/admin/connectors/google-drive/install');

        $resp->assertOk();
        // Same row, not a new one — composite unique on (tenant, name)
        // makes a second row impossible anyway.
        $this->assertSame($originalId, $resp->json('data.installation_id'));

        $active->refresh();
        $this->assertSame(ConnectorInstallation::STATUS_PENDING, $active->status);
        $this->assertNull($active->error_json);
    }

    /**
     * iter2 finding #4 — also re-arms ERRORED + DISABLED. These cases
     * existed in iter1 too but now share a single, simpler code path.
     */
    public function test_reinstall_arms_existing_errored_row_to_pending(): void
    {
        $admin = $this->makeSuperAdmin();

        $errored = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_ERRORED,
            'error_json' => ['message' => 'old failure'],
            'created_by' => $admin->id,
        ]);

        $resp = $this->actingAs($admin)
            ->getJson('/api/admin/connectors/google-drive/install');

        $resp->assertOk();
        $errored->refresh();
        $this->assertSame(ConnectorInstallation::STATUS_PENDING, $errored->status);
        $this->assertNull($errored->error_json);
    }

    public function test_sync_now_dispatches_connector_sync_job(): void
    {
        Queue::fake();
        $admin = $this->makeSuperAdmin();

        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => $admin->id,
        ]);

        $resp = $this->actingAs($admin)
            ->postJson("/api/admin/connectors/{$installation->id}/sync-now");

        $resp->assertStatus(202);
        $this->assertSame(true, $resp->json('data.queued'));
        Queue::assertPushed(ConnectorSyncJob::class, function (ConnectorSyncJob $job) use ($installation) {
            return $job->installationId === $installation->id
                && $job->tenantId === 'default';
        });
    }

    public function test_disable_marks_installation_disabled_without_revoking(): void
    {
        $admin = $this->makeSuperAdmin();

        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => $admin->id,
        ]);
        ConnectorCredential::create([
            'tenant_id' => 'default',
            'connector_installation_id' => $installation->id,
            'encrypted_access_token' => \Illuminate\Support\Facades\Crypt::encryptString('secret'),
        ]);

        $resp = $this->actingAs($admin)
            ->postJson("/api/admin/connectors/{$installation->id}/disable");

        $resp->assertOk();
        $this->assertSame(
            ConnectorInstallation::STATUS_DISABLED,
            $resp->json('data.status'),
        );
        // Credentials still in the DB.
        $this->assertDatabaseHas('connector_credentials', [
            'connector_installation_id' => $installation->id,
        ]);
    }

    public function test_destroy_clears_credentials_and_removes_installation(): void
    {
        $admin = $this->makeSuperAdmin();

        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => $admin->id,
        ]);
        ConnectorCredential::create([
            'tenant_id' => 'default',
            'connector_installation_id' => $installation->id,
            'encrypted_access_token' => \Illuminate\Support\Facades\Crypt::encryptString('secret'),
        ]);

        // Stub the revoke call so destroy() doesn't actually network.
        Http::fake([
            'oauth2.googleapis.com/revoke' => Http::response([], 200),
        ]);

        $resp = $this->actingAs($admin)
            ->deleteJson("/api/admin/connectors/{$installation->id}");

        $resp->assertStatus(204);
        $this->assertDatabaseMissing('connector_installations', ['id' => $installation->id]);
        $this->assertDatabaseMissing('connector_credentials', [
            'connector_installation_id' => $installation->id,
        ]);
    }

    public function test_non_super_admin_gets_403_on_every_endpoint(): void
    {
        $user = $this->makeRegularAdmin();

        // index
        $this->actingAs($user)->getJson('/api/admin/connectors')->assertStatus(403);
        // install
        $this->actingAs($user)->getJson('/api/admin/connectors/google-drive/install')->assertStatus(403);
        // callback
        $this->actingAs($user)->getJson('/api/admin/connectors/google-drive/oauth/callback')->assertStatus(403);
        // sync-now / disable / destroy
        $this->actingAs($user)->postJson('/api/admin/connectors/1/sync-now')->assertStatus(403);
        $this->actingAs($user)->postJson('/api/admin/connectors/1/disable')->assertStatus(403);
        $this->actingAs($user)->deleteJson('/api/admin/connectors/1')->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/admin/connectors')->assertStatus(401);
        $this->getJson('/api/admin/connectors/google-drive/install')->assertStatus(401);
        $this->postJson('/api/admin/connectors/1/sync-now')->assertStatus(401);
        $this->postJson('/api/admin/connectors/1/disable')->assertStatus(401);
        $this->deleteJson('/api/admin/connectors/1')->assertStatus(401);
    }

    public function test_tenant_isolation_blocks_cross_tenant_endpoints(): void
    {
        $admin = $this->makeSuperAdmin();

        // Installation belongs to tenant-foreign; active tenant is `default`.
        $foreignInstallation = ConnectorInstallation::create([
            'tenant_id' => 'tenant-foreign',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => $admin->id,
        ]);

        // sync-now 404s — cross-tenant id is invisible to the active tenant.
        $this->actingAs($admin)
            ->postJson("/api/admin/connectors/{$foreignInstallation->id}/sync-now")
            ->assertStatus(404);
        // disable 404s.
        $this->actingAs($admin)
            ->postJson("/api/admin/connectors/{$foreignInstallation->id}/disable")
            ->assertStatus(404);
        // destroy 404s.
        $this->actingAs($admin)
            ->deleteJson("/api/admin/connectors/{$foreignInstallation->id}")
            ->assertStatus(404);

        // index does NOT surface the foreign installation.
        $resp = $this->actingAs($admin)->getJson('/api/admin/connectors');
        $resp->assertOk();
        $entry = collect($resp->json('data'))->firstWhere('key', 'google-drive');
        $this->assertNull($entry['installation']);
    }

    private function makeSuperAdmin(): User
    {
        $user = User::create([
            'name' => 'Super',
            'email' => 'super-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('super-admin');

        return $user;
    }

    private function makeRegularAdmin(): User
    {
        $user = User::create([
            'name' => 'RegularAdmin',
            'email' => 'admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('admin');

        return $user;
    }
}
