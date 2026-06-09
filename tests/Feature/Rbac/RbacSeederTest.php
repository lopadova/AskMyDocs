<?php

namespace Tests\Feature\Rbac;

use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
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

    public function test_seeder_creates_five_roles_with_expected_permissions(): void
    {
        $this->seed(RbacSeeder::class);

        $roleNames = Role::pluck('name')->sort()->values()->all();
        // v4.2/W4 sub-PR 5 — `dpo` (Data Protection Officer) added to
        // back the PII Redactor admin Gates with a non-super-admin role.
        $this->assertSame(
            ['admin', 'dpo', 'editor', 'super-admin', 'viewer'],
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
            'roles.manage',
            // v8.0.3 security hotfix (C1) — cross-tenant override capability
            // for the AuthorizeTenantHeader middleware. super-admin only.
            'tenant.cross-access',
            'users.manage',
        ];

        $this->assertSame(
            $expectedPermissions,
            Permission::pluck('name')->sort()->values()->all(),
        );

        $superAdmin = Role::findByName('super-admin', 'web');
        $this->assertCount(count($expectedPermissions), $superAdmin->permissions);

        $viewer = Role::findByName('viewer', 'web');
        $this->assertTrue($viewer->hasPermissionTo('kb.read.any'));
        $this->assertTrue($viewer->hasPermissionTo('logs.view'));
        $this->assertFalse($viewer->hasPermissionTo('kb.edit.any'));

        // v4.2/W4 sub-PR 5 — DPO sanity: gets pii.detokenize, NOT kb.edit.any.
        $dpo = Role::findByName('dpo', 'web');
        $this->assertTrue($dpo->hasPermissionTo('pii.detokenize'));
        $this->assertTrue($dpo->hasPermissionTo('admin.access'));
        $this->assertFalse($dpo->hasPermissionTo('kb.edit.any'));
        $this->assertFalse($dpo->hasPermissionTo('commands.destructive'));
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(RbacSeeder::class);

        // v4.2/W4 sub-PR 5 — 4 pre-W4 roles + `dpo` = 5.
        $this->assertSame(5, Role::count());
        // 11 pre-H2 + `commands.destructive` (H2) + `pii.detokenize` (W4)
        // + `tenant.cross-access` (v8.0.3 C1) + `kb.read.all_projects`
        // (per-project isolation) = 15.
        $this->assertSame(15, Permission::count());
    }

    public function test_seeder_backfills_existing_users_with_viewer_role_and_project_membership(): void
    {
        $user = User::create([
            'name' => 'Existing',
            'email' => 'existing@example.com',
            'password' => Hash::make('secret123'),
        ]);

        KnowledgeDocument::create([
            'project_key' => 'hr-portal',
            'source_type' => 'markdown',
            'title' => 'Policy',
            'source_path' => 'hr/policy.md',
            'document_hash' => hash('sha256', 'x'),
            'version_hash' => hash('sha256', 'x:v1'),
            'status' => 'indexed',
        ]);

        $this->seed(RbacSeeder::class);

        $user->refresh();
        $this->assertTrue($user->hasRole('viewer'));
        $this->assertDatabaseHas('project_memberships', [
            'user_id' => $user->id,
            'project_key' => 'hr-portal',
        ]);
    }

    public function test_seeder_backfill_does_not_duplicate_memberships_on_rerun(): void
    {
        $user = User::create([
            'name' => 'Repeat',
            'email' => 'repeat@example.com',
            'password' => Hash::make('secret123'),
        ]);

        KnowledgeDocument::create([
            'project_key' => 'hr-portal',
            'source_type' => 'markdown',
            'title' => 'Policy',
            'source_path' => 'hr/policy.md',
            'document_hash' => hash('sha256', 'x'),
            'version_hash' => hash('sha256', 'x:v1'),
            'status' => 'indexed',
        ]);

        $this->seed(RbacSeeder::class);
        $this->seed(RbacSeeder::class);

        $this->assertSame(
            1,
            ProjectMembership::where('user_id', $user->id)->count(),
        );
    }
}
