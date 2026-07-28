<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SystemAdminMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_super_admins_keep_global_access_while_future_super_admins_do_not(): void
    {
        $this->seed(RbacSeeder::class);

        $legacy = $this->user('legacy-system-admin@example.test');
        $legacy->assignRole('super-admin');

        // Recreate the pre-split state, then re-run the idempotent production
        // migration against real data.
        Role::findByName('super-admin', 'web')->givePermissionTo('tenant.cross-access');
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_07_28_000001_split_system_admin_role.php';
        $migration->up();

        $legacy->refresh();
        $this->assertTrue($legacy->hasRole('system-admin'));
        $this->assertTrue($legacy->hasRole('super-admin'));
        $this->assertTrue($legacy->can('platform.admin'));
        $this->assertTrue($legacy->can('tenant.cross-access'));
        $this->assertDatabaseHas('admin_command_audit', [
            'user_id' => $legacy->id,
            'command' => 'system-admin:migrate-legacy',
            'status' => 'completed',
        ]);

        $future = $this->user('future-super-admin@example.test');
        $future->assignRole('super-admin');

        $this->assertFalse($future->hasRole('system-admin'));
        $this->assertFalse($future->can('platform.admin'));
        $this->assertFalse($future->can('tenant.cross-access'));
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => 'RBAC migration test user',
            'email' => $email,
            'password' => Hash::make('secret-password'),
        ]);
    }
}
