<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use App\Services\Auth\UserTeamsResolver;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature coverage for `company:create` — bootstrap a company (tenant) + its
 * admin user. Asserts the full pipeline (tenant → project → user → role →
 * membership) AND the end-to-end outcome that the admin actually "sees" the new
 * company via UserTeamsResolver. Failure cases prove the create-new semantics
 * leave no partial company behind (R16).
 */
final class CreateCompanyCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
    }

    public function test_creates_tenant_project_admin_role_and_membership(): void
    {
        Role::findOrCreate('admin', 'web');

        $this->artisan('company:create', [
            '--company' => 'Acme Corp',
            '--email' => 'admin@acme.com',
            '--password' => 'secret123',
        ])
            ->expectsOutputToContain("Company 'Acme Corp' created.")
            ->assertExitCode(0);

        // Tenant registry row, slug derived from the company name.
        $this->assertDatabaseHas('tenants', [
            'slug' => 'acme-corp',
            'name' => 'Acme Corp',
            'status' => 'active',
        ]);
        // Project registry row (project_key defaults to the slug).
        $this->assertDatabaseHas('projects', [
            'tenant_id' => 'acme-corp',
            'project_key' => 'acme-corp',
            'name' => 'Acme Corp',
        ]);

        $user = User::where('email', 'admin@acme.com')->firstOrFail();
        $this->assertSame('admin', $user->name, 'Admin display name defaults to the email local-part.');
        $this->assertTrue(Hash::check('secret123', $user->password), 'Password is stored hashed.');
        $this->assertTrue($user->hasRole('admin'));

        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => 'acme-corp',
            'user_id' => $user->id,
            'project_key' => 'acme-corp',
            'role' => 'member',
        ]);

        // End-to-end: the admin can actually act in the new company (the team
        // switcher reads project_memberships through UserTeamsResolver).
        $teamIds = array_column(app(UserTeamsResolver::class)->resolve($user), 'tenant_id');
        $this->assertContains('acme-corp', $teamIds);
    }

    public function test_respects_explicit_slug_name_project_and_role(): void
    {
        Role::findOrCreate('super-admin', 'web');

        $this->artisan('company:create', [
            '--company' => 'Acme Corp',
            '--email' => 'boss@acme.com',
            '--password' => 'secret123',
            '--slug' => 'acme',
            '--name' => 'Big Boss',
            '--project' => 'acme-kb',
            '--role' => 'super-admin',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('tenants', ['slug' => 'acme', 'name' => 'Acme Corp']);
        $this->assertDatabaseHas('projects', ['tenant_id' => 'acme', 'project_key' => 'acme-kb']);

        $user = User::where('email', 'boss@acme.com')->firstOrFail();
        $this->assertSame('Big Boss', $user->name);
        $this->assertTrue($user->hasRole('super-admin'));
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'acme-kb',
        ]);
    }

    public function test_fails_when_email_already_in_use_without_creating_a_company(): void
    {
        Role::findOrCreate('admin', 'web');
        User::create([
            'name' => 'Existing',
            'email' => 'taken@acme.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->artisan('company:create', [
            '--company' => 'Acme Corp',
            '--email' => 'taken@acme.com',
            '--password' => 'secret123',
        ])
            ->expectsOutputToContain('Email already in use: taken@acme.com')
            ->assertExitCode(1);

        // No company was created (create-new semantics + nothing half-written).
        $this->assertDatabaseMissing('tenants', ['slug' => 'acme-corp']);
        $this->assertDatabaseMissing('projects', ['project_key' => 'acme-corp']);
        $this->assertSame(1, User::where('email', 'taken@acme.com')->count());
    }

    public function test_fails_when_company_slug_already_exists(): void
    {
        Role::findOrCreate('admin', 'web');
        \Padosoft\AiActCompliance\MultiTenancy\Models\Tenant::create(['slug' => 'acme', 'name' => 'Existing Acme']);

        $this->artisan('company:create', [
            '--company' => 'Acme Corp',
            '--email' => 'admin@acme.com',
            '--password' => 'secret123',
            '--slug' => 'acme',
        ])
            ->expectsOutputToContain("Company 'acme' already exists.")
            ->assertExitCode(1);

        // The admin user was NOT created for the rejected company.
        $this->assertDatabaseMissing('users', ['email' => 'admin@acme.com']);
    }

    public function test_fails_on_an_invalid_tenant_slug(): void
    {
        Role::findOrCreate('admin', 'web');

        // An explicit slug with a space + uppercase can't match the
        // ^[a-z0-9_-]{1,50}$ shape TenantContext enforces — rejected before any
        // write (the same guard a company name that slugs to empty would hit).
        $this->artisan('company:create', [
            '--company' => 'Acme Corp',
            '--email' => 'admin@acme.com',
            '--password' => 'secret123',
            '--slug' => 'Bad Slug!',
        ])
            ->expectsOutputToContain('Invalid tenant slug')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'admin@acme.com']);
    }

    public function test_fails_when_role_does_not_exist_on_the_web_guard(): void
    {
        // 'imaginary' is intentionally NOT seeded.
        $this->artisan('company:create', [
            '--company' => 'Acme Corp',
            '--email' => 'admin@acme.com',
            '--password' => 'secret123',
            '--role' => 'imaginary',
        ])
            ->expectsOutputToContain("Role 'imaginary' not found on guard 'web'")
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'admin@acme.com']);
        $this->assertDatabaseMissing('tenants', ['slug' => 'acme-corp']);
    }

    public function test_fails_when_password_is_too_short(): void
    {
        Role::findOrCreate('admin', 'web');

        $this->artisan('company:create', [
            '--company' => 'Acme Corp',
            '--email' => 'admin@acme.com',
            '--password' => 'short',
        ])
            ->expectsOutputToContain('Password must be at least 8 characters.')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'admin@acme.com']);
    }

    public function test_fails_when_required_options_are_missing(): void
    {
        $this->artisan('company:create', [
            '--company' => 'Acme Corp',
        ])
            ->expectsOutputToContain('--company, --email and --password are all required.')
            ->assertExitCode(1);
    }
}
