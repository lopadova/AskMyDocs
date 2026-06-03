<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Models\WidgetSessionStep;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * M6.2 + M6.3 — Feature tests for WidgetKeyAdminController + WidgetSessionAdminController.
 *
 * Covers: CRUD, rotate, revoke, tenant scoping, session inspection.
 */
final class WidgetAdminControllerTest extends TestCase
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
    }

    private function superAdmin(): User
    {
        $user = User::create([
            'name' => 'SuperAdmin',
            'email' => 'sa-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
        ]);
        $user->assignRole('super-admin');

        return $user;
    }

    private function adminUser(): User
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'adm-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
        ]);
        $user->assignRole('admin');

        return $user;
    }

    private function viewerUser(): User
    {
        $user = User::create([
            'name' => 'Viewer',
            'email' => 'view-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
        ]);
        $user->assignRole('viewer');

        return $user;
    }

    // ── M6.2: WidgetKeyAdminController ──

    public function test_index_returns_keys_for_tenant(): void
    {
        $user = $this->superAdmin();

        WidgetKey::query()->create([
            'tenant_id' => 'default',
            'project_key' => 'test-project',
            'public_key' => 'pk_test_abc123',
            'secret_hash' => bcrypt('sk_test_secret'),
            'label' => 'Test Key',
            'allowed_origins' => ['https://example.com'],
            'rate_limit' => 60,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/admin/widget-keys');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.label', 'Test Key');
        $response->assertJsonPath('data.0.public_key', 'pk_test_abc123');
        // secret_hash must never be exposed
        $this->assertArrayNotHasKey('secret_hash', $response->json('data.0'));
    }

    public function test_store_creates_key_and_returns_secret_once(): void
    {
        $user = $this->superAdmin();

        $response = $this->actingAs($user)->postJson('/api/admin/widget-keys', [
            'label' => 'New Key',
            'project_key' => 'my-project',
            'allowed_origins' => ['https://app.example.com'],
            'rate_limit' => 120,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.label', 'New Key');
        $this->assertNotEmpty($response->json('plain_secret'));
        $this->assertStringStartsWith('sk_', $response->json('plain_secret'));
        $this->assertStringStartsWith('pk_', $response->json('public_key'));

        // Verify key was actually created in the DB
        $this->assertDatabaseHas('widget_keys', [
            'label' => 'New Key',
            'project_key' => 'my-project',
            'tenant_id' => 'default',
        ]);
    }

    public function test_update_modifies_mutable_fields(): void
    {
        $user = $this->superAdmin();
        $key = WidgetKey::query()->create([
            'tenant_id' => 'default',
            'project_key' => 'test-project',
            'public_key' => 'pk_test_update',
            'secret_hash' => bcrypt('sk_test_secret'),
            'label' => 'Old Label',
            'allowed_origins' => [],
            'rate_limit' => 60,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->patchJson("/api/admin/widget-keys/{$key->id}", [
            'label' => 'New Label',
            'allowed_origins' => ['https://new.example.com'],
            'rate_limit' => 200,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.label', 'New Label');
        $response->assertJsonPath('data.rate_limit', 200);
    }

    public function test_rotate_generates_new_credentials(): void
    {
        $user = $this->superAdmin();
        $key = WidgetKey::query()->create([
            'tenant_id' => 'default',
            'project_key' => 'test-project',
            'public_key' => 'pk_test_old',
            'secret_hash' => bcrypt('sk_test_old'),
            'label' => 'Rotate Me',
            'allowed_origins' => [],
            'rate_limit' => 60,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
        ]);

        $oldPublicKey = $key->public_key;

        $response = $this->actingAs($user)->postJson("/api/admin/widget-keys/{$key->id}/rotate");

        $response->assertOk();
        $newPk = $response->json('public_key');
        $newSk = $response->json('plain_secret');
        $this->assertNotEquals($oldPublicKey, $newPk);
        $this->assertStringStartsWith('pk_', $newPk);
        $this->assertStringStartsWith('sk_', $newSk);

        // Old public key no longer works — check it was replaced in DB
        $this->assertDatabaseMissing('widget_keys', ['public_key' => $oldPublicKey]);
        $this->assertDatabaseHas('widget_keys', ['public_key' => $newPk]);
    }

    public function test_revoke_sets_inactive(): void
    {
        $user = $this->superAdmin();
        $key = WidgetKey::query()->create([
            'tenant_id' => 'default',
            'project_key' => 'test-project',
            'public_key' => 'pk_test_revoke',
            'secret_hash' => bcrypt('sk_test_revoke'),
            'label' => 'Revoke Me',
            'allowed_origins' => [],
            'rate_limit' => 60,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson("/api/admin/widget-keys/{$key->id}/revoke");

        $response->assertOk();
        $response->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('widget_keys', ['id' => $key->id, 'is_active' => false]);
    }

    public function test_destroy_removes_key(): void
    {
        $user = $this->superAdmin();
        $key = WidgetKey::query()->create([
            'tenant_id' => 'default',
            'project_key' => 'test-project',
            'public_key' => 'pk_test_destroy',
            'secret_hash' => bcrypt('sk_test_destroy'),
            'label' => 'Delete Me',
            'allowed_origins' => [],
            'rate_limit' => 60,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/admin/widget-keys/{$key->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('widget_keys', ['id' => $key->id]);
    }

    public function test_tenant_scoping_hides_other_tenant_keys(): void
    {
        $user = $this->superAdmin();

        // Key in default tenant
        WidgetKey::query()->create([
            'tenant_id' => 'default',
            'project_key' => 'default-project',
            'public_key' => 'pk_visible',
            'secret_hash' => bcrypt('sk_visible'),
            'label' => 'Visible',
            'allowed_origins' => [],
            'rate_limit' => 60,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
        ]);

        // Key in another tenant
        WidgetKey::query()->create([
            'tenant_id' => 'other-tenant',
            'project_key' => 'other-project',
            'public_key' => 'pk_hidden',
            'secret_hash' => bcrypt('sk_hidden'),
            'label' => 'Hidden',
            'allowed_origins' => [],
            'rate_limit' => 60,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/admin/widget-keys');
        $response->assertOk();

        // Only the default-tenant key should appear
        $labels = collect($response->json('data'))->pluck('label')->all();
        $this->assertContains('Visible', $labels);
        $this->assertNotContains('Hidden', $labels);
    }

    public function test_viewer_is_denied_widget_key_management(): void
    {
        $viewer = $this->viewerUser();

        $this->actingAs($viewer)
            ->getJson('/api/admin/widget-keys')
            ->assertForbidden();
    }

    // ── M6.3: WidgetSessionAdminController ──

    public function test_session_index_returns_sessions_for_tenant(): void
    {
        $user = $this->adminUser(); // admin can view sessions

        $key = WidgetKey::query()->create([
            'tenant_id' => 'default',
            'project_key' => 'test-project',
            'public_key' => 'pk_sess_test',
            'secret_hash' => bcrypt('sk_sess_test'),
            'label' => 'Session Key',
            'allowed_origins' => [],
            'rate_limit' => 60,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
        ]);

        $session = WidgetSession::query()->create([
            'tenant_id' => 'default',
            'widget_key_id' => $key->id,
            'project_key' => 'test-project',
            'public_session_id' => \Illuminate\Support\Str::uuid(),
            'status' => 'completed',
            'skill' => 'askmydocs-assistant@1',
            'origin' => 'https://example.com',
        ]);

        $response = $this->actingAs($user)->getJson('/api/admin/widget-sessions');

        $response->assertOk();
        $response->assertJsonStructure(['data', 'meta']);
    }

    public function test_session_show_returns_detail_with_steps(): void
    {
        $user = $this->adminUser();

        $key = WidgetKey::query()->create([
            'tenant_id' => 'default',
            'project_key' => 'test-project',
            'public_key' => 'pk_detail_test',
            'secret_hash' => bcrypt('sk_detail_test'),
            'label' => 'Detail Key',
            'allowed_origins' => [],
            'rate_limit' => 60,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
        ]);

        $session = WidgetSession::query()->create([
            'tenant_id' => 'default',
            'widget_key_id' => $key->id,
            'project_key' => 'test-project',
            'public_session_id' => \Illuminate\Support\Str::uuid(),
            'status' => 'active',
            'skill' => 'askmydocs-assistant@1',
        ]);

        WidgetSessionStep::query()->create([
            'tenant_id' => 'default',
            'widget_session_id' => $session->id,
            'step_index' => 0,
            'kind' => 'user_message',
        ]);

        $response = $this->actingAs($user)->getJson("/api/admin/widget-sessions/{$session->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $session->id);
        $response->assertJsonCount(1, 'data.steps');
    }

    public function test_viewer_is_denied_session_inspection(): void
    {
        $viewer = $this->viewerUser();

        $this->actingAs($viewer)
            ->getJson('/api/admin/widget-sessions')
            ->assertForbidden();
    }

    public function test_session_filter_by_widget_key_id(): void
    {
        $user = $this->adminUser();

        $key1 = WidgetKey::query()->create([
            'tenant_id' => 'default',
            'project_key' => 'project-1',
            'public_key' => 'pk_filter_1',
            'secret_hash' => bcrypt('sk_filter_1'),
            'label' => 'Key 1',
            'allowed_origins' => [],
            'rate_limit' => 60,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
        ]);

        $key2 = WidgetKey::query()->create([
            'tenant_id' => 'default',
            'project_key' => 'project-2',
            'public_key' => 'pk_filter_2',
            'secret_hash' => bcrypt('sk_filter_2'),
            'label' => 'Key 2',
            'allowed_origins' => [],
            'rate_limit' => 60,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
        ]);

        // Session on key1
        WidgetSession::query()->create([
            'tenant_id' => 'default',
            'widget_key_id' => $key1->id,
            'project_key' => 'project-1',
            'public_session_id' => \Illuminate\Support\Str::uuid(),
            'status' => 'completed',
        ]);

        // Session on key2
        WidgetSession::query()->create([
            'tenant_id' => 'default',
            'widget_key_id' => $key2->id,
            'project_key' => 'project-2',
            'public_session_id' => \Illuminate\Support\Str::uuid(),
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/admin/widget-sessions?widget_key_id='.$key1->id);

        $response->assertOk();
        // Only sessions for key1 should be returned
        $this->assertCount(1, $response->json('data'));
    }
}