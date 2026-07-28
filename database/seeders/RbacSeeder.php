<?php

namespace Database\Seeders;

use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\PlatformAccess;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent RBAC seeder.
 *
 *  - 6 roles: system-admin, super-admin, admin, dpo, editor, viewer
 *    (guard: web). `system-admin` is the global platform operator;
 *    `super-admin` is the highest tenant role and still requires tenant
 *    membership.
 *    `dpo` is the Data Protection Officer role added in v4.2/W4 sub-PR 5
 *    so the PII Redactor Admin Gates have a non-super-admin role they
 *    can grant detokenise + admin-view access to without escalating to
 *    full system admin. DPOs see PII tooling but NOT command runner
 *    or destructive admin commands.
 *  - 17 permissions (kb.* for content incl. kb.read.all_projects for the
 *    per-project isolation "see all projects" capability, users/roles/
 *    permissions for admin, commands/logs/insights/admin.access for ops
 *    panel, pii.detokenize for PII reverse lookup, plus platform.admin and
 *    tenant.cross-access for the system control plane).
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
        PlatformAccess::SYSTEM_ADMIN_ROLE,
        PlatformAccess::TENANT_SUPER_ADMIN_ROLE,
        'admin',
        'dpo',
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
        // Per-project isolation "see all projects" capability. When
        // config('kb.project_isolation.enabled') is ON, this — NOT the
        // blanket kb.read.any — is what grants tenant-wide cross-project
        // read. Granted to admin + super-admin only; every other user is
        // then scoped to their project_memberships. When isolation is OFF
        // this permission is inert (kb.read.any remains the lever).
        'kb.read.all_projects',
        'kb.edit.any',
        'kb.delete.any',
        'kb.promote.any',
        'commands.run',
        // Phase H2 — destructive admin commands (kb:ingest-folder,
        // kb:delete, kb:prune-*). Split from `commands.run` so a
        // regular admin can run read-path maintenance (validate,
        // rebuild-graph, queue:retry) but not `rm -rf`-equivalent
        // operations. Only super-admin gets this by default.
        'commands.destructive',
        'logs.view',
        'insights.view',
        'admin.access',
        // v4.2/W4 sub-PR 5 — pii-redactor-admin reverse-lookup capability.
        // Distinct from the Spatie role check the Gate uses (Gate-level
        // wiring lives in AppServiceProvider::registerPiiRedactorAdminGates);
        // this permission lets non-Gate consumers (e.g. integration
        // tests, future API clients) reason about who can detokenise
        // through the standard `$user->can('pii.detokenize')` channel.
        'pii.detokenize',
        // v8.23 (Ciclo 4) — GDPR Art.17 right-to-erasure capability:
        // crypto-shred a subject's reversible token-vault entries so the
        // surrogates left in the KB / chat become permanently inert. More
        // destructive than detokenise, so granted to dpo + super-admin only
        // (the data-protection owners). Checked via `$user->can('pii.erase')`.
        'pii.erase',
        // Global control-plane capability. This is deliberately separate
        // from the tenant `super-admin` role.
        PlatformAccess::PLATFORM_ADMIN_PERMISSION,
        // C1 (R30) — explicit cross-tenant override capability. Only a
        // system administrator may bypass membership checks.
        PlatformAccess::CROSS_TENANT_PERMISSION,
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
        $systemAdmin = Role::findByName(PlatformAccess::SYSTEM_ADMIN_ROLE, self::GUARD);
        $superAdmin = Role::findByName(PlatformAccess::TENANT_SUPER_ADMIN_ROLE, self::GUARD);
        $admin = Role::findByName('admin', self::GUARD);
        $dpo = Role::findByName('dpo', self::GUARD);
        $editor = Role::findByName('editor', self::GUARD);
        $viewer = Role::findByName('viewer', self::GUARD);

        // A system administrator is the only global operator. The dedicated
        // grant command also gives this account the companion `super-admin`
        // role because many tenant routes intentionally use role middleware.
        $systemAdmin->syncPermissions([
            PlatformAccess::PLATFORM_ADMIN_PERMISSION,
            PlatformAccess::CROSS_TENANT_PERMISSION,
        ]);

        // Highest tenant role: every tenant-level capability, but no platform
        // control-plane permission and no cross-tenant membership bypass.
        $superAdmin->syncPermissions([
            'users.manage',
            'roles.manage',
            'permissions.view',
            'kb.read.any',
            'kb.read.all_projects',
            'kb.edit.any',
            'kb.delete.any',
            'kb.promote.any',
            'commands.run',
            'commands.destructive',
            'logs.view',
            'insights.view',
            'admin.access',
            'pii.detokenize',
            'pii.erase',
        ]);

        $admin->syncPermissions([
            'users.manage',
            'kb.read.any',
            // admin sees all projects even when per-project isolation is ON
            // (super-admin receives it in its explicit tenant grant set).
            'kb.read.all_projects',
            'kb.edit.any',
            'kb.delete.any',
            'kb.promote.any',
            // admin gets commands.run (kb:validate-canonical,
            // kb:rebuild-graph, queue:retry) but intentionally NOT
            // commands.destructive — destructive maintenance is
            // super-admin-only. See config/admin.php for the full
            // per-command permission matrix.
            'commands.run',
            'logs.view',
            'insights.view',
            'admin.access',
        ]);

        // v4.2/W4 sub-PR 5 — DPO has admin.access (so the AskMyDocs admin
        // shell renders + the PII Redactor sidebar entry shows up),
        // logs.view (so they can correlate detokenise events with
        // upstream activity), and pii.detokenize. Intentionally NO
        // kb.* / users.manage / commands.run — DPO is a privacy
        // role, not a system administrator. The Gate
        // `viewPiiRedactorAdmin` checks `hasAnyRole(['super-admin',
        // 'dpo', 'admin'])` independently of these permissions; this
        // permission grant gives the same coverage through the
        // permission system for downstream tooling that prefers
        // `$user->can()` over `Gate::allows()`.
        $dpo->syncPermissions([
            'admin.access',
            'logs.view',
            'pii.detokenize',
            'pii.erase',
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
