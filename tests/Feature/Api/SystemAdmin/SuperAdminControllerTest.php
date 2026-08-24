<?php

declare(strict_types=1);

namespace Tests\Feature\Api\SystemAdmin;

use App\Models\ProjectMembership;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Tests\TestCase;

final class SuperAdminControllerTest extends TestCase
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

    public function test_only_platform_admin_can_read_global_super_admin_roster(): void
    {
        $system = $this->user('system@example.test', ['system-admin', 'super-admin']);
        $super = $this->user('super@example.test', ['super-admin']);
        $admin = $this->user('admin@example.test', ['admin']);

        $this->getJson('/api/system-admin/super-admins')->assertUnauthorized();
        $this->getJson("/api/system-admin/super-admins/{$super->id}/tenants")
            ->assertUnauthorized();

        // The system operator deliberately has no tenant membership. An
        // untrusted tenant header does not scope the global control plane.
        $this->actingAsWithoutTenant($system)
            ->withHeader('X-Tenant-Id', 'forged-tenant')
            ->getJson('/api/system-admin/super-admins')
            ->assertOk();

        $this->actingAs($super)
            ->getJson('/api/system-admin/super-admins')
            ->assertForbidden();
        $this->actingAs($super)
            ->getJson("/api/system-admin/super-admins/{$super->id}/tenants")
            ->assertForbidden();
        $this->actingAs($admin)
            ->getJson('/api/system-admin/super-admins')
            ->assertForbidden();
        $this->actingAs($admin)
            ->getJson("/api/system-admin/super-admins/{$super->id}/tenants")
            ->assertForbidden();
    }

    public function test_roster_contains_only_super_admins_with_global_flags_counts_filters_and_pagination(): void
    {
        $system = $this->user('system@example.test', ['system-admin', 'super-admin']);
        $tenantSuper = $this->user('tenant-super@example.test', ['super-admin']);
        $this->user('ordinary@example.test', ['admin']);

        $this->grant($system, 'acme', 'one');
        $this->grant($system, 'globex', 'two');
        $this->grant($tenantSuper, 'acme', 'three');

        $response = $this->actingAsWithoutTenant($system)
            ->getJson('/api/system-admin/super-admins?per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2);

        $this->assertCount(1, $response->json('data'));

        $this->actingAsWithoutTenant($system)
            ->getJson('/api/system-admin/super-admins?search=tenant-super')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tenantSuper->id)
            ->assertJsonPath('data.0.is_system_admin', false)
            ->assertJsonPath('data.0.tenant_count', 1);

        $this->actingAsWithoutTenant($system)
            ->getJson('/api/system-admin/super-admins?search=system')
            ->assertOk()
            ->assertJsonPath('data.0.is_system_admin', true)
            ->assertJsonPath('data.0.tenant_count', 2);
    }

    public function test_tenant_associations_are_distinct_and_paginated(): void
    {
        $system = $this->user('system@example.test', ['system-admin', 'super-admin']);
        $target = $this->user('target@example.test', ['super-admin']);
        Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'status' => 'active']);
        Tenant::create(['slug' => 'globex', 'name' => 'Globex', 'status' => 'suspended']);
        $this->grant($target, 'acme', 'one');
        $this->grant($target, 'acme', 'two');
        $this->grant($target, 'globex', 'three');

        $first = $this->actingAsWithoutTenant($system)
            ->getJson("/api/system-admin/super-admins/{$target->id}/tenants?per_page=1&page=1")
            ->assertOk()
            ->assertJsonPath('user.id', $target->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonCount(1, 'data');

        $this->assertSame('acme', $first->json('data.0.slug'));
        $this->assertSame(2, $first->json('data.0.project_count'));
    }

    public function test_tenant_associations_hide_non_super_admin_identity(): void
    {
        $system = $this->user('system@example.test', ['system-admin', 'super-admin']);
        $admin = $this->user('admin@example.test', ['admin']);

        $this->actingAsWithoutTenant($system)
            ->getJson("/api/system-admin/super-admins/{$admin->id}/tenants")
            ->assertNotFound();
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $email, array $roles): User
    {
        $user = User::create([
            'name' => ucfirst(strstr($email, '@', true) ?: 'User'),
            'email' => $email,
            'password' => Hash::make('secret-password'),
        ]);
        $user->assignRole($roles);

        return $user;
    }

    private function grant(User $user, string $tenantId, string $projectKey): void
    {
        ProjectMembership::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'project_key' => $projectKey,
            'role' => 'admin',
        ]);
    }
}
