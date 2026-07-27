<?php

declare(strict_types=1);

namespace Tests\Feature\Api\SuperAdmin;

use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Auth\UserTeamsResolver;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    public function test_only_super_admin_can_open_the_global_registry(): void
    {
        $super = $this->userWithRole('super-admin', 'super@example.com');
        $admin = $this->userWithRole('admin', 'admin@example.com');

        $this->getJson('/api/super-admin/tenants')->assertUnauthorized();
        $this->actingAs($super)->getJson('/api/super-admin/tenants')->assertOk();
        $this->actingAs($admin)->getJson('/api/super-admin/tenants')->assertForbidden();
    }

    public function test_provisions_tenant_project_new_user_role_and_membership_atomically(): void
    {
        $super = $this->userWithRole('super-admin', 'super@example.com');

        $response = $this->actingAs($super)->postJson('/api/super-admin/tenants', [
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
    }

    public function test_preflight_detects_case_insensitive_existing_user_and_store_can_attach_it_without_changing_password(): void
    {
        $super = $this->userWithRole('super-admin', 'super@example.com');
        $existing = $this->userWithRole('viewer', 'person@example.com');
        $passwordBefore = $existing->password;

        $this->actingAs($super)->getJson('/api/super-admin/tenants/availability?'.http_build_query([
            'tenant_name' => 'Globex',
            'tenant_slug' => 'globex',
            'user_email' => 'PERSON@EXAMPLE.COM',
        ]))->assertOk()
            ->assertJsonPath('data.tenant.available', true)
            ->assertJsonPath('data.user.status', 'existing')
            ->assertJsonPath('data.user.id', $existing->id);

        $this->actingAs($super)->postJson('/api/super-admin/tenants', [
            'tenant_name' => 'Globex',
            'tenant_slug' => 'globex',
            'user_email' => 'PERSON@EXAMPLE.COM',
            'role' => 'admin',
            'attach_existing' => true,
        ])->assertCreated()
            ->assertJsonPath('data.attached_existing', true)
            ->assertJsonPath('data.user.id', $existing->id);

        $existing->refresh();
        $this->assertSame($passwordBefore, $existing->password);
        $this->assertTrue($existing->hasRole('viewer'));
        $this->assertTrue($existing->hasRole('admin'));
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => 'globex',
            'user_id' => $existing->id,
        ]);
    }

    public function test_deleted_account_and_duplicate_tenant_are_blocked_without_partial_writes(): void
    {
        $super = $this->userWithRole('super-admin', 'super@example.com');
        $deleted = $this->userWithRole('viewer', 'deleted@example.com');
        $deleted->delete();
        Tenant::create(['slug' => 'taken', 'name' => 'Taken', 'status' => 'active']);

        $this->actingAs($super)->postJson('/api/super-admin/tenants', [
            'tenant_name' => 'Taken Again',
            'tenant_slug' => 'taken',
            'user_email' => 'new@example.com',
            'user_name' => 'New User',
            'password' => 'Temporary-Secret-123',
            'role' => 'admin',
            'attach_existing' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('tenant_slug');

        $this->actingAs($super)->postJson('/api/super-admin/tenants', [
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
        $super = $this->userWithRole('super-admin', 'super@example.com');
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

        $response = $this->actingAs($super)->getJson('/api/super-admin/tenants/acme')
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
        $super = $this->userWithRole('super-admin', 'super@example.com');
        $member = $this->userWithRole('viewer', 'member@example.com');
        Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'status' => 'active']);
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $member->id,
            'project_key' => 'acme',
            'role' => 'viewer',
        ]);

        $this->assertContains('acme', array_column(app(UserTeamsResolver::class)->resolve($member), 'tenant_id'));

        $this->actingAs($super)->patchJson('/api/super-admin/tenants/acme', ['status' => 'suspended'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');

        $this->assertNotContains('acme', array_column(app(UserTeamsResolver::class)->resolve($member), 'tenant_id'));
    }

    private function userWithRole(string $role, string $email): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => Hash::make('secret-password'),
        ]);
        $user->assignRole($role);

        return $user;
    }
}
