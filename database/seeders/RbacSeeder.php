<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent RBAC seeder.
 *
 *  - 4 roles: super-admin, admin, editor, viewer (guard: web).
 *  - 11 permissions (kb.* for content, users/roles/permissions for admin,
 *    commands/logs/insights/admin.access for ops panel).
 *  - Backfill: assign `viewer` to every existing user so they still live in
 *    the RBAC graph after deploy. **No project memberships are created** —
 *    in a multi-tenant install auto-granting memberships across all
 *    project_keys would open every tenant to every user (Copilot review on
 *    PR #18). Ops / admins must call `php artisan auth:grant <email>
 *    <role> --project=<key>` (or use the UI in Phase F2) to grant access.
 *
 *  - `viewer` role carries `logs.view` only — NO `kb.read.any`. Users with
 *    just the `viewer` role see no KB content until they get a project
 *    membership. This is the intended multi-tenant default.
 *
 * Safe to run multiple times: findOrCreate + syncPermissions for each
 * role, assignRole no-ops when already present.
 */
class RbacSeeder extends Seeder
{
    private const GUARD = 'web';

    /**
     * @var array<int,string>
     */
    private const ROLES = [
        'super-admin',
        'admin',
        'editor',
        'viewer',
    ];

    /**
     * @var array<int,string>
     */
    private const PERMISSIONS = [
        'users.manage',
        'roles.manage',
        'permissions.view',
        'kb.read.any',
        'kb.edit.any',
        'kb.delete.any',
        'kb.promote.any',
        'commands.run',
        'logs.view',
        'insights.view',
        'admin.access',
    ];

    public function run(): void
    {
        $this->ensurePermissions();
        $this->ensureRoles();
        $this->syncRolePermissions();
        $this->backfillExistingUsers();

        // Flush the Spatie permission cache so test runs that invoke the
        // seeder mid-setup see the new roles/permissions immediately.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function ensurePermissions(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, self::GUARD);
        }
    }

    private function ensureRoles(): void
    {
        foreach (self::ROLES as $name) {
            Role::findOrCreate($name, self::GUARD);
        }
    }

    private function syncRolePermissions(): void
    {
        $superAdmin = Role::findByName('super-admin', self::GUARD);
        $admin = Role::findByName('admin', self::GUARD);
        $editor = Role::findByName('editor', self::GUARD);
        $viewer = Role::findByName('viewer', self::GUARD);

        $superAdmin->syncPermissions(Permission::all());

        $admin->syncPermissions([
            'users.manage',
            'kb.read.any',
            'kb.edit.any',
            'kb.delete.any',
            'kb.promote.any',
            'commands.run',
            'logs.view',
            'insights.view',
            'admin.access',
        ]);

        $editor->syncPermissions([
            'kb.read.any',
            'kb.edit.any',
            'kb.promote.any',
            'commands.run',
            'logs.view',
            'insights.view',
        ]);

        // viewer intentionally has NO kb.read.any — KB visibility for a
        // viewer-only user flows exclusively through project memberships.
        // Keeping the role useful so that existing users remain visible in
        // the RBAC graph after PR3 ships; admins then layer memberships.
        $viewer->syncPermissions([
            'logs.view',
        ]);
    }

    /**
     * Assign the `viewer` role to every existing user so they still live
     * in the RBAC graph. Deliberately does NOT create project memberships
     * — per-tenant access must be granted explicitly by an operator:
     *     php artisan auth:grant <email> <role> --project=<key>
     *
     * Uses chunkById to stay memory-safe on larger userbases (R3).
     */
    private function backfillExistingUsers(): void
    {
        User::query()->chunkById(100, function ($users) {
            foreach ($users as $user) {
                $this->backfillUser($user);
            }
        });
    }

    private function backfillUser(User $user): void
    {
        if ($user->hasRole('viewer', self::GUARD)) {
            return;
        }

        $user->assignRole('viewer');
    }
}
