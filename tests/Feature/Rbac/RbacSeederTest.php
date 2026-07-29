<?php

namespace Tests\Feature\Rbac;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RbacSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_six_roles_with_distinct_platform_and_tenant_admin_permissions(): void
    {
        $this->seed(RbacSeeder::class);

        $roleNames = Role::pluck('name')->sort()->values()->all();
        // v4.2/W4 sub-PR 5 — `dpo` (Data Protection Officer) added to
        // back the PII Redactor admin Gates with a non-super-admin role.
        $this->assertSame(
            ['admin', 'dpo', 'editor', 'super-admin', 'system-admin', 'viewer'],
            $roleNames,
        );

        $expectedPermissions = [
            'admin.access',
            // Phase H2 — `commands.destructive` gates destructive
            // maintenance commands (kb:prune-*, kb:ingest-folder,
            // kb:delete). super-admin gets it; admin does NOT.
            'commands.destructive',
            'commands.run',
            'insights.view',
            'kb.delete.any',
            'kb.edit.any',
            'kb.promote.any',
            'kb.read.all_projects',
            'kb.read.any',
            'logs.view',
            'permissions.view',
            // v4.2/W4 sub-PR 5 — `pii.detokenize` is the permission
            // backing the `detokenisePiiRedactor` Gate (super-admin +
            // dpo). Granted to dpo + super-admin in RbacSeeder.
            'pii.detokenize',
            // v8.23 (Ciclo 4) — GDPR Art.17 erasure capability (dpo + super-admin).
            'pii.erase',
            'platform.admin',
            'roles.manage',
            'users.manage',
        ];

        $this->assertSame(
            $expectedPermissions,
            Permission::pluck('name')->sort()->values()->all(),
        );

        $systemAdmin = Role::findByName('system-admin', 'web');
        $this->assertCount(1, $systemAdmin->permissions);
        $this->assertTrue($systemAdmin->hasPermissionTo('platform.admin'));

        $superAdmin = Role::findByName('super-admin', 'web');
        $this->assertTrue($superAdmin->hasPermissionTo('commands.destructive'));
        $this->assertFalse($superAdmin->hasPermissionTo('platform.admin'));

        $viewer = Role::findByName('viewer', 'web');
        $this->assertTrue($viewer->hasPermissionTo('kb.read.any'));
        $this->assertTrue($viewer->hasPermissionTo('logs.view'));
        $this->assertFalse($viewer->hasPermissionTo('kb.edit.any'));

        // v4.2/W4 sub-PR 5 — DPO sanity: gets pii.detokenize, NOT kb.edit.any.
        $dpo = Role::findByName('dpo', 'web');
        $this->assertTrue($dpo->hasPermissionTo('pii.detokenize'));
        // v8.23 (Ciclo 4) — DPO also gets the Art.17 erasure permission.
        $this->assertTrue($dpo->hasPermissionTo('pii.erase'));
        $this->assertTrue($dpo->hasPermissionTo('admin.access'));
        $this->assertFalse($dpo->hasPermissionTo('kb.edit.any'));
        $this->assertFalse($dpo->hasPermissionTo('commands.destructive'));
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(RbacSeeder::class);

        $this->assertSame(6, Role::count());
        // 11 pre-H2 + `commands.destructive` (H2) + `pii.detokenize` (W4)
        // + `kb.read.all_projects` (per-project isolation) + `pii.erase`
        // (v8.23 Ciclo 4) + `platform.admin` = 16.
        $this->assertSame(16, Permission::count());
    }

    public function test_seeder_backfills_existing_users_with_viewer_role_without_tenant_membership(): void
    {
        $user = User::create([
            'name' => 'Existing',
            'email' => 'existing@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->seed(RbacSeeder::class);

        $user->refresh();
        $this->assertTrue($user->hasRole('viewer'));
        $this->assertDatabaseMissing('project_memberships', ['user_id' => $user->id]);
    }

    public function test_seeder_backfill_is_idempotent_without_creating_memberships(): void
    {
        $user = User::create([
            'name' => 'Repeat',
            'email' => 'repeat@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->seed(RbacSeeder::class);
        $this->seed(RbacSeeder::class);

        $user->refresh();
        $this->assertTrue($user->hasRole('viewer'));
        $this->assertDatabaseMissing('project_memberships', ['user_id' => $user->id]);
    }
}
