<?php

namespace Database\Seeders;

use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
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
 *  - Backfill: assign `viewer` to every existing user and create a
 *    viewer-role membership against every existing project_key so PR3
 *    deploy does not lock out the userbase.
 *
 * Safe to run multiple times: firstOrCreate + syncPermissions for each
 * role, assignRole no-ops when the role is already present, upsert
 * semantics on project_memberships keyed by (user_id, project_key).
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

        $viewer->syncPermissions([
            'kb.read.any',
            'logs.view',
        ]);
    }

    /**
     * Assign `viewer` role to every existing user AND create a viewer
     * project_memberships row for each (user, project_key) pair so the
     * global scope doesn't lock anyone out after the flag flips on.
     *
     * Uses chunkById to stay memory-safe on larger userbases (R3).
     */
    private function backfillExistingUsers(): void
    {
        $projectKeys = KnowledgeDocument::withTrashed()
            ->whereNotNull('project_key')
            ->distinct()
            ->pluck('project_key')
            ->all();

        User::query()->chunkById(100, function ($users) use ($projectKeys) {
            foreach ($users as $user) {
                $this->backfillUser($user, $projectKeys);
            }
        });
    }

    /**
     * @param  array<int,string>  $projectKeys
     */
    private function backfillUser(User $user, array $projectKeys): void
    {
        if (! $user->hasRole('viewer', self::GUARD)) {
            $user->assignRole('viewer');
        }

        foreach ($projectKeys as $projectKey) {
            ProjectMembership::firstOrCreate(
                ['user_id' => $user->id, 'project_key' => $projectKey],
                ['role' => 'member', 'scope_allowlist' => null],
            );
        }
    }
}
