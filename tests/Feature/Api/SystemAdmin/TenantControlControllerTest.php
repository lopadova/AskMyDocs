<?php

declare(strict_types=1);

namespace Tests\Feature\Api\SystemAdmin;

use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Auth\UserTeamsResolver;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Tests\TestCase;

final class TenantControlControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
    }

    public function test_only_system_admin_can_open_the_global_registry(): void
    {
        $system = $this->userWithRole('system-admin', 'system@example.com');
        $super = $this->userWithRole('super-admin', 'super@example.com');
        $admin = $this->userWithRole('admin', 'admin@example.com');

        $this->getJson('/api/system-admin/tenants')->assertUnauthorized();
        $this->actingAs($system)->getJson('/api/system-admin/tenants')->assertOk();
        $this->actingAs($super)->getJson('/api/system-admin/tenants')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/system-admin/tenants')->assertForbidden();
        $this->actingAs($system)->getJson('/api/super-admin/tenants')->assertNotFound();
    }

    public function test_provisions_tenant_project_new_user_role_and_membership_atomically(): void
    {
        $system = $this->userWithRole('system-admin', 'system@example.com');

        $response = $this->actingAs($system)->postJson('/api/system-admin/tenants', [
            'tenant_name' => 'Acme S.r.l.',
            'tenant_slug' => 'acme',
            'user_email' => 'Owner@Acme.test',
            'user_name' => 'Acme Owner',
            'password' => 'Temporary-Secret-123',
            'role' => 'admin',
            'attach_existing' => false,
        ])->assertCreated()
            ->assertJsonPath('data.tenant.slug', 'acme')
            ->assertJsonPath('data.user.email', 'owner@acme.test')
            ->assertJsonPath('data.attached_existing', false);

        $userId = (int) $response->json('data.user.id');
        $user = User::findOrFail($userId);

        $this->assertTrue(Hash::check('Temporary-Secret-123', $user->password));
        $this->assertTrue($user->hasRole('admin'));
        $this->assertDatabaseHas('tenants', ['slug' => 'acme', 'name' => 'Acme S.r.l.', 'status' => 'active']);
        $this->assertDatabaseHas('projects', ['tenant_id' => 'acme', 'project_key' => 'acme']);
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => 'acme',
            'user_id' => $userId,
            'project_key' => 'acme',
            'role' => 'admin',
        ]);
        $this->assertDatabaseHas('admin_command_audit', [
            'user_id' => $system->id,
            'tenant_id' => 'acme',
            'command' => 'system-admin:tenant-provision',
            'status' => 'completed',
        ]);
    }

    public function test_provisioning_rolls_back_when_the_success_audit_cannot_be_written(): void
    {
        $system = $this->userWithRole('system-admin', 'system@example.com');
        DB::statement(<<<'SQL'
            CREATE TRIGGER reject_tenant_provision_audit_completion
            BEFORE UPDATE ON admin_command_audit
            WHEN OLD.command = 'system-admin:tenant-provision'
            BEGIN
                SELECT RAISE(ABORT, 'audit completion unavailable');
            END
            SQL);

        $this->actingAs($system)->postJson('/api/system-admin/tenants', [
            'tenant_name' => 'Audit Failure',
            'tenant_slug' => 'audit-failure',
            'user_email' => 'owner@audit-failure.test',
            'user_name' => 'Audit Owner',
            'password' => 'Temporary-Secret-123',
            'role' => 'super-admin',
            'attach_existing' => false,
        ])->assertServerError();

        $this->assertDatabaseMissing('tenants', ['slug' => 'audit-failure']);
        $this->assertDatabaseMissing('projects', ['tenant_id' => 'audit-failure']);
        $this->assertDatabaseMissing('project_memberships', ['tenant_id' => 'audit-failure']);
        $this->assertDatabaseMissing('users', ['email' => 'owner@audit-failure.test']);
    }

    public function test_preflight_detects_case_insensitive_existing_user_and_store_can_attach_it_without_changing_password(): void
    {
        $system = $this->userWithRole('system-admin', 'system@example.com');
        $existing = $this->userWithRole('admin', 'person@example.com');
        $passwordBefore = $existing->password;

        $this->actingAs($system)->postJson('/api/system-admin/tenants/availability', [
            'tenant_name' => 'Globex',
            'tenant_slug' => 'globex',
            'user_email' => 'PERSON@EXAMPLE.COM',
            'role' => 'editor',
        ])->assertOk()
            ->assertJsonPath('data.tenant.available', true)
            ->assertJsonPath('data.user.status', 'existing')
            ->assertJsonPath('data.user.id', $existing->id)
            ->assertJsonPath('data.user.effective_role', 'admin')
            ->assertJsonPath('data.user.role_compatible', true);

        $this->actingAs($system)->postJson('/api/system-admin/tenants', [
            'tenant_name' => 'Globex',
            'tenant_slug' => 'globex',
            'user_email' => 'PERSON@EXAMPLE.COM',
            'role' => 'editor',
            'attach_existing' => true,
        ])->assertCreated()
            ->assertJsonPath('data.attached_existing', true)
            ->assertJsonPath('data.user.id', $existing->id);

        $existing->refresh();
        $this->assertSame($passwordBefore, $existing->password);
        $this->assertTrue($existing->hasRole('admin'));
        $this->assertFalse($existing->hasRole('editor'));
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => 'globex',
            'user_id' => $existing->id,
        ]);
    }

    public function test_existing_account_is_never_silently_promoted_during_tenant_attachment(): void
    {
        $system = $this->userWithRole('system-admin', 'system@example.com');
        $existing = $this->userWithRole('viewer', 'viewer@example.com');

        $this->actingAs($system)->postJson('/api/system-admin/tenants', [
            'tenant_name' => 'Globex',
            'tenant_slug' => 'globex',
            'user_email' => $existing->email,
            'role' => 'admin',
            'attach_existing' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('error', 'role_global_mismatch')
            ->assertJsonPath('details.requested_role', 'admin')
            ->assertJsonPath('details.effective_role', 'viewer');

        $existing->refresh();
        $this->assertTrue($existing->hasRole('viewer'));
        $this->assertFalse($existing->hasRole('admin'));
        $this->assertDatabaseMissing('tenants', ['slug' => 'globex']);
    }

    public function test_deleted_account_and_duplicate_tenant_are_blocked_without_partial_writes(): void
    {
        $system = $this->userWithRole('system-admin', 'system@example.com');
        $deleted = $this->userWithRole('viewer', 'deleted@example.com');
        $deleted->delete();
        Tenant::create(['slug' => 'taken', 'name' => 'Taken', 'status' => 'active']);

        $this->actingAs($system)->postJson('/api/system-admin/tenants', [
            'tenant_name' => 'Taken Again',
            'tenant_slug' => 'taken',
            'user_email' => 'new@example.com',
            'user_name' => 'New User',
            'password' => 'Temporary-Secret-123',
            'role' => 'admin',
            'attach_existing' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('tenant_slug');

        $this->actingAs($system)->postJson('/api/system-admin/tenants', [
            'tenant_name' => 'Fresh Tenant',
            'tenant_slug' => 'fresh',
            'user_email' => 'DELETED@example.com',
            'role' => 'admin',
            'attach_existing' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_email');

        $this->assertDatabaseMissing('tenants', ['slug' => 'fresh']);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_detail_lists_only_target_tenant_users_with_roles_permissions_and_project_access(): void
    {
        $system = $this->userWithRole('system-admin', 'system@example.com');
        $acmeUser = $this->userWithRole('editor', 'editor@acme.test');
        $otherUser = $this->userWithRole('viewer', 'viewer@other.test');
        Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'status' => 'active']);
        Tenant::create(['slug' => 'other', 'name' => 'Other', 'status' => 'active']);
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $acmeUser->id,
            'project_key' => 'acme-kb',
            'role' => 'editor',
        ]);
        ProjectMembership::create([
            'tenant_id' => 'other',
            'user_id' => $otherUser->id,
            'project_key' => 'other-kb',
            'role' => 'viewer',
        ]);

        $response = $this->actingAs($system)->getJson('/api/system-admin/tenants/acme')
            ->assertOk()
            ->assertJsonPath('data.tenant.slug', 'acme')
            ->assertJsonPath('data.users.meta.total', 1)
            ->assertJsonPath('data.users.data.0.id', $acmeUser->id)
            ->assertJsonPath('data.users.data.0.memberships.0.project_key', 'acme-kb')
            ->assertJsonStructure([
                'data' => [
                    'users' => [
                        'data' => [['roles', 'permissions', 'all_projects', 'memberships']],
                    ],
                ],
            ]);

        $this->assertNotContains($otherUser->id, array_column($response->json('data.users.data'), 'id'));
    }

    public function test_status_update_removes_membership_user_from_switcher_until_reactivated(): void
    {
        $system = $this->userWithRole('system-admin', 'system@example.com');
        $member = $this->userWithRole('viewer', 'member@example.com');
        Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'status' => 'active']);
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $member->id,
            'project_key' => 'acme',
            'role' => 'viewer',
        ]);

        $this->assertContains('acme', array_column(app(UserTeamsResolver::class)->resolve($member), 'tenant_id'));

        $token = $this->actingAs($system)
            ->postJson('/api/system-admin/tenants/acme/lifecycle-preview', ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.transition.from', 'active')
            ->assertJsonPath('data.transition.to', 'suspended')
            ->json('data.confirm_token');

        $this->actingAs($system)->patchJson('/api/system-admin/tenants/acme', [
            'status' => 'suspended',
            'confirm_token' => $token,
        ])
            ->assertOk()->assertJsonPath('data.status', 'suspended');

        $this->assertNotContains('acme', array_column(app(UserTeamsResolver::class)->resolve($member), 'tenant_id'));
        $this->assertDatabaseHas('admin_command_audit', [
            'user_id' => $system->id,
            'tenant_id' => 'acme',
            'command' => 'system-admin:tenant-update',
            'status' => 'completed',
        ]);
    }

    public function test_lifecycle_confirmation_is_required_single_use_and_bound_to_exact_transition(): void
    {
        $system = $this->userWithRole('system-admin', 'system@example.com');
        Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'status' => 'active']);

        $this->actingAs($system)
            ->patchJson('/api/system-admin/tenants/acme', ['status' => 'suspended'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm_token');

        $token = $this->actingAs($system)
            ->postJson('/api/system-admin/tenants/acme/lifecycle-preview', ['status' => 'suspended'])
            ->assertOk()
            ->json('data.confirm_token');

        $this->actingAs($system)
            ->patchJson('/api/system-admin/tenants/acme', [
                'status' => 'archived',
                'confirm_token' => $token,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm_token');
        $this->assertDatabaseHas('tenants', ['slug' => 'acme', 'status' => 'active']);

        $this->actingAs($system)
            ->patchJson('/api/system-admin/tenants/acme', [
                'status' => 'suspended',
                'confirm_token' => $token,
            ])
            ->assertOk();

        $this->actingAs($system)
            ->patchJson('/api/system-admin/tenants/acme', [
                'status' => 'active',
                'confirm_token' => $token,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm_token');

        $this->assertDatabaseHas('admin_command_audit', [
            'user_id' => $system->id,
            'command' => 'system-admin:tenant-update',
            'status' => 'rejected',
        ]);
    }

    public function test_super_and_system_admin_switchers_both_list_only_membership_tenants(): void
    {
        $system = $this->userWithRole('system-admin', 'system@example.com');
        $super = $this->userWithRole('super-admin', 'super@example.com');
        Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'status' => 'active']);
        Tenant::create(['slug' => 'globex', 'name' => 'Globex', 'status' => 'active']);
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $super->id,
            'project_key' => 'acme',
            'role' => 'admin',
        ]);
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $system->id,
            'project_key' => 'acme',
            'role' => 'admin',
        ]);
        ProjectMembership::create([
            'tenant_id' => 'globex',
            'user_id' => $system->id,
            'project_key' => 'globex',
            'role' => 'admin',
        ]);

        $this->assertSame(
            ['acme'],
            array_column(app(UserTeamsResolver::class)->resolve($super), 'tenant_id'),
        );
        $this->assertSame(
            ['acme', 'globex'],
            array_column(app(UserTeamsResolver::class)->resolve($system), 'tenant_id'),
        );
    }

    private function userWithRole(string $role, string $email): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => Hash::make('secret-password'),
        ]);
        $user->assignRole(
            $role === 'system-admin'
                ? ['system-admin', 'super-admin']
                : $role,
        );

        return $user;
    }
}
