<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use App\Services\Admin\SystemAdminAccessService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class SystemAdminCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_grant_assigns_platform_and_companion_roles_with_audit(): void
    {
        $user = $this->user('operator@example.test');

        $this->artisan('system-admin:grant', ['email' => 'OPERATOR@example.test', '--yes' => true])
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue($user->hasRole('system-admin'));
        $this->assertTrue($user->hasRole('super-admin'));
        $this->assertTrue($user->can('platform.admin'));
        $this->assertDatabaseHas('admin_command_audit', [
            'user_id' => $user->id,
            'command' => 'system-admin:grant',
            'status' => 'completed',
        ]);
    }

    public function test_revoke_keeps_tenant_role_and_refuses_to_remove_the_last_system_admin(): void
    {
        $first = $this->user('first@example.test');
        $second = $this->user('second@example.test');
        $this->artisan('system-admin:grant', ['email' => $first->email, '--yes' => true])->assertSuccessful();

        $this->artisan('system-admin:revoke', ['email' => $first->email, '--yes' => true])
            ->expectsOutputToContain('Cannot revoke the last system administrator.')
            ->assertFailed();

        $this->artisan('system-admin:grant', ['email' => $second->email, '--yes' => true])->assertSuccessful();
        $this->artisan('system-admin:revoke', ['email' => $first->email, '--yes' => true])->assertSuccessful();

        $first->refresh();
        $this->assertFalse($first->hasRole('system-admin'));
        $this->assertTrue($first->hasRole('super-admin'));
        $this->assertDatabaseHas('admin_command_audit', [
            'user_id' => $first->id,
            'command' => 'system-admin:revoke',
            'status' => 'rejected',
        ]);
        $this->assertDatabaseHas('admin_command_audit', [
            'user_id' => $first->id,
            'command' => 'system-admin:revoke',
            'status' => 'completed',
        ]);
    }

    public function test_generic_role_command_cannot_grant_system_admin(): void
    {
        $user = $this->user('generic@example.test');

        $this->artisan('auth:grant', [
            'email' => $user->email,
            'role' => 'system-admin',
        ])->expectsOutputToContain('Use system-admin:grant')
            ->assertFailed();

        $this->assertFalse($user->fresh()->hasRole('system-admin'));
    }

    public function test_grant_rejects_inactive_and_deleted_accounts(): void
    {
        $inactive = $this->user('inactive@example.test');
        $inactive->forceFill(['is_active' => false])->save();
        $deleted = $this->user('deleted@example.test');
        $deleted->delete();

        $this->artisan('system-admin:grant', ['email' => $inactive->email, '--yes' => true])
            ->expectsOutputToContain('inactive')
            ->assertFailed();
        $this->artisan('system-admin:grant', ['email' => $deleted->email, '--yes' => true])
            ->expectsOutputToContain('deleted')
            ->assertFailed();

        $this->assertFalse($inactive->fresh()->hasRole('system-admin'));
        $this->assertFalse($deleted->fresh()->hasRole('system-admin'));
    }

    public function test_role_change_rolls_back_when_success_audit_cannot_be_written(): void
    {
        $user = $this->user('audit-failure@example.test');
        DB::statement(<<<'SQL'
            CREATE TRIGGER reject_system_admin_audit_completion
            BEFORE UPDATE ON admin_command_audit
            WHEN OLD.command = 'system-admin:grant'
            BEGIN
                SELECT RAISE(ABORT, 'audit completion unavailable');
            END
            SQL);

        try {
            app(SystemAdminAccessService::class)->grant($user->email);
            $this->fail('Expected the audit update to fail.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('audit completion unavailable', $e->getMessage());
        }

        $this->assertFalse($user->fresh()->hasRole('system-admin'));
        $this->assertFalse($user->fresh()->hasRole('super-admin'));
    }

    public function test_privilege_change_fails_closed_when_confirmation_is_declined(): void
    {
        $user = $this->user('declined@example.test');

        $this->artisan('system-admin:grant', ['email' => $user->email])
            ->expectsConfirmation('Grant platform-wide system administrator access?', 'no')
            ->assertFailed();

        $this->assertFalse($user->fresh()->hasRole('system-admin'));
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => 'Operator',
            'email' => $email,
            'password' => Hash::make('secret-password'),
        ]);
    }
}
