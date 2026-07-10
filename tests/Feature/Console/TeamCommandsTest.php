<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Tests\TestCase;

/**
 * v8.28 — PHP surface (R44) for team management: `team:create` / `team:rename`,
 * thin over TeamRegistryService. Asserts the create chain end to end and the
 * failure paths (missing name, duplicate, unmanageable team, no actor) leave
 * nothing half-written (R16).
 */
final class TeamCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('default');
        Cache::flush();
    }

    public function test_team_create_makes_tenant_project_and_membership_for_the_first_super_admin(): void
    {
        $super = $this->userWithRole('super-admin');

        $this->artisan('team:create', ['--name' => 'Acme Corp'])
            ->expectsOutputToContain("Team 'Acme Corp' created.")
            ->assertExitCode(0)
            ->run();

        $this->assertDatabaseHas('tenants', ['slug' => 'acme-corp', 'name' => 'Acme Corp', 'status' => 'active']);
        $this->assertDatabaseHas('projects', ['tenant_id' => 'acme-corp', 'project_key' => 'acme-corp']);
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => 'acme-corp',
            'user_id' => $super->id,
            'project_key' => 'acme-corp',
        ]);
    }

    public function test_team_create_attaches_an_explicit_actor(): void
    {
        $this->userWithRole('super-admin');
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@acme.com', 'password' => Hash::make('secret-password')]);
        $bob->assignRole('admin');

        $this->artisan('team:create', ['--name' => 'Acme Corp', '--slug' => 'acme', '--actor' => 'bob@acme.com'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('project_memberships', ['tenant_id' => 'acme', 'user_id' => $bob->id]);
    }

    public function test_team_create_fails_without_a_name(): void
    {
        $this->userWithRole('super-admin');

        $this->artisan('team:create', [])
            ->expectsOutputToContain('--name is required.')
            ->assertExitCode(1);
    }

    public function test_team_create_fails_on_a_duplicate_slug_without_partial_writes(): void
    {
        $this->userWithRole('super-admin');
        Tenant::create(['slug' => 'acme', 'name' => 'Existing']);

        $this->artisan('team:create', ['--name' => 'Acme Corp', '--slug' => 'acme'])
            ->expectsOutputToContain("A team with the slug 'acme' already exists.")
            ->assertExitCode(1);

        $this->assertDatabaseMissing('projects', ['tenant_id' => 'acme', 'project_key' => 'acme']);
    }

    public function test_team_create_fails_when_no_super_admin_and_no_actor(): void
    {
        // No super-admin seeded and no --actor → the actor cannot resolve.
        $this->artisan('team:create', ['--name' => 'Acme Corp'])
            ->expectsOutputToContain('No super-admin found')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('tenants', ['slug' => 'acme-corp']);
    }

    public function test_team_rename_updates_the_display_name(): void
    {
        $this->userWithRole('super-admin');
        Tenant::create(['slug' => 'acme', 'name' => 'Acme Corp', 'status' => 'active']);

        $this->artisan('team:rename', ['slug' => 'acme', 'name' => 'Acme Corporation'])
            ->expectsOutputToContain("renamed to 'Acme Corporation'")
            ->assertExitCode(0)
            ->run();

        $this->assertDatabaseHas('tenants', ['slug' => 'acme', 'name' => 'Acme Corporation']);
    }

    public function test_team_rename_fails_for_an_unmanageable_team(): void
    {
        // A plain editor (no cross-access) who is not a member of 'foreign'.
        $editor = $this->userWithRole('editor');
        Tenant::create(['slug' => 'foreign', 'name' => 'Foreign Co']);

        $this->artisan('team:rename', ['slug' => 'foreign', 'name' => 'Hacked', '--actor' => $editor->email])
            ->expectsOutputToContain('not found or not manageable')
            ->assertExitCode(1);

        $this->assertDatabaseHas('tenants', ['slug' => 'foreign', 'name' => 'Foreign Co']);
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@demo.local',
            'password' => Hash::make('secret-password'),
        ]);
        $user->assignRole($role);

        return $user;
    }
}
