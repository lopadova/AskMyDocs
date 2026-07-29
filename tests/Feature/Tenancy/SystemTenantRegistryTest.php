<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Admin\SystemAdminTenantService;
use App\Services\Admin\TeamRegistryService;
use App\Services\Auth\UserTeamsResolver;
use App\Support\SystemTenantRegistry;
use Database\Seeders\SystemTenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SystemTenantRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_materialises_the_registration_namespace(): void
    {
        $this->assertDatabaseHas('tenants', [
            'slug' => SystemTenantRegistry::REGISTRATION,
            'name' => 'System Registration',
            'status' => 'active',
            'is_system' => true,
        ]);
    }

    public function test_seeder_is_idempotent_and_restores_controlled_fields(): void
    {
        DB::table('tenants')
            ->where('slug', SystemTenantRegistry::REGISTRATION)
            ->update([
                'name' => 'Tampered',
                'status' => 'suspended',
                'is_system' => false,
            ]);

        (new SystemTenantSeeder)->run();
        (new SystemTenantSeeder)->run();

        $this->assertSame(
            1,
            DB::table('tenants')->where('slug', SystemTenantRegistry::REGISTRATION)->count(),
        );
        $this->assertDatabaseHas('tenants', [
            'slug' => SystemTenantRegistry::REGISTRATION,
            'name' => 'System Registration',
            'status' => 'active',
            'is_system' => true,
        ]);
    }

    public function test_system_namespace_is_never_exposed_as_an_operational_team(): void
    {
        $user = User::create([
            'name' => 'Accidental Member',
            'email' => 'accidental@example.com',
            'password' => 'secret123',
        ]);
        Project::create([
            'tenant_id' => SystemTenantRegistry::REGISTRATION,
            'project_key' => 'system-registration',
            'name' => 'Must stay hidden',
        ]);
        ProjectMembership::create([
            'tenant_id' => SystemTenantRegistry::REGISTRATION,
            'user_id' => $user->id,
            'project_key' => 'system-registration',
            'role' => 'owner',
        ]);

        $this->assertSame([], app(UserTeamsResolver::class)->resolve($user));
    }

    public function test_legacy_default_is_exposed_only_with_an_explicit_membership(): void
    {
        $member = User::create([
            'name' => 'Legacy Member',
            'email' => 'legacy-member@example.com',
            'password' => 'secret123',
        ]);
        $outsider = User::create([
            'name' => 'Legacy Outsider',
            'email' => 'legacy-outsider@example.com',
            'password' => 'secret123',
        ]);
        ProjectMembership::create([
            'tenant_id' => SystemTenantRegistry::LEGACY_DEFAULT,
            'user_id' => $member->id,
            'project_key' => 'legacy-project',
            'role' => 'owner',
        ]);

        $this->assertSame(
            [SystemTenantRegistry::LEGACY_DEFAULT],
            array_column(app(UserTeamsResolver::class)->resolve($member), 'tenant_id'),
        );
        $this->assertSame([], app(UserTeamsResolver::class)->resolve($outsider));
    }

    public function test_system_namespace_cannot_be_claimed_as_a_team(): void
    {
        try {
            app(TeamRegistryService::class)->newTeamAvailability(
                SystemTenantRegistry::REGISTRATION,
                'Fake Company',
            );
            $this->fail('The reserved system namespace must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slug', $e->errors());
        }
    }

    public function test_system_namespace_is_hidden_from_the_global_tenant_control_plane(): void
    {
        $page = app(SystemAdminTenantService::class)->paginate();

        $this->assertSame([], $page['data']);
        $this->assertSame(0, $page['meta']['total']);
    }
}
