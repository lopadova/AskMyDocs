<?php

namespace Tests\Feature\Rbac;

use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthGrantCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_grants_role_to_existing_user(): void
    {
        Role::findOrCreate('admin', 'web');

        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->artisan('auth:grant', [
            'email' => 'alice@example.com',
            'role' => 'admin',
        ])
            ->expectsOutputToContain('Granted role "admin" to user "alice@example.com"')
            ->assertExitCode(0);

        $this->assertTrue($user->fresh()->hasRole('admin'));
    }

    public function test_grants_role_and_project_membership_when_project_option_set(): void
    {
        Role::findOrCreate('editor', 'web');

        $user = User::create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->artisan('auth:grant', [
            'email' => 'bob@example.com',
            'role' => 'editor',
            '--project' => 'hr-portal',
        ])
            ->expectsOutputToContain('+ membership in project "hr-portal"')
            ->assertExitCode(0);

        $this->assertTrue($user->fresh()->hasRole('editor'));
        $this->assertDatabaseHas('project_memberships', [
            'user_id' => $user->id,
            'project_key' => 'hr-portal',
            'role' => 'member',
        ]);
    }

    public function test_repeated_grant_is_idempotent(): void
    {
        Role::findOrCreate('editor', 'web');

        $user = User::create([
            'name' => 'Carol',
            'email' => 'carol@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->artisan('auth:grant', [
            'email' => 'carol@example.com',
            'role' => 'editor',
            '--project' => 'hr-portal',
        ])->assertExitCode(0);

        $this->artisan('auth:grant', [
            'email' => 'carol@example.com',
            'role' => 'editor',
            '--project' => 'hr-portal',
        ])->assertExitCode(0);

        $this->assertSame(1, ProjectMembership::where('user_id', $user->id)->count());
    }

    public function test_unknown_user_exits_with_error(): void
    {
        Role::findOrCreate('admin', 'web');

        $this->artisan('auth:grant', [
            'email' => 'ghost@example.com',
            'role' => 'admin',
        ])
            ->expectsOutputToContain('User with email ghost@example.com not found.')
            ->assertExitCode(1);
    }

    public function test_unknown_role_exits_with_error(): void
    {
        User::create([
            'name' => 'Dan',
            'email' => 'dan@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->artisan('auth:grant', [
            'email' => 'dan@example.com',
            'role' => 'imaginary-role',
        ])
            ->expectsOutputToContain('Role imaginary-role not found.')
            ->assertExitCode(1);
    }
}
