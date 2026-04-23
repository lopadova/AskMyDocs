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

    public function test_seeder_creates_four_roles_with_expected_permissions(): void
    {
        $this->seed(RbacSeeder::class);

        $roleNames = Role::pluck('name')->sort()->values()->all();
        $this->assertSame(
            ['admin', 'editor', 'super-admin', 'viewer'],
            $roleNames,
        );

        $expectedPermissions = [
            'admin.access',
            'commands.run',
            'insights.view',
            'kb.delete.any',
            'kb.edit.any',
            'kb.promote.any',
            'kb.read.any',
            'logs.view',
            'permissions.view',
            'roles.manage',
            'users.manage',
        ];

        $this->assertSame(
            $expectedPermissions,
            Permission::pluck('name')->sort()->values()->all(),
        );

        $superAdmin = Role::findByName('super-admin', 'web');
        $this->assertCount(count($expectedPermissions), $superAdmin->permissions);

        $viewer = Role::findByName('viewer', 'web');
        // viewer intentionally has NO kb.read.any — KB access flows through
        // project memberships only. See Copilot review on PR #18.
        $this->assertFalse($viewer->hasPermissionTo('kb.read.any'));
        $this->assertTrue($viewer->hasPermissionTo('logs.view'));
        $this->assertFalse($viewer->hasPermissionTo('kb.edit.any'));
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(RbacSeeder::class);

        $this->assertSame(4, Role::count());
        $this->assertSame(11, Permission::count());
    }

    public function test_seeder_backfills_existing_users_with_viewer_role_only(): void
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

        // Project memberships must NOT be auto-created by the seeder. In a
        // multi-tenant deployment that would open every tenant to every
        // pre-existing user. Admins grant memberships explicitly via the
        // auth:grant CLI or the Users admin UI (Phase F2).
        $this->assertSame(
            0,
            ProjectMembership::where('user_id', $user->id)->count(),
        );
    }

    public function test_seeder_backfill_is_idempotent_on_role_assignment(): void
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

        $user->refresh();

        // Idempotent: viewer role assigned exactly once despite two runs.
        $this->assertSame(1, $user->roles()->where('name', 'viewer')->count());

        // And still no spurious project memberships on re-runs.
        $this->assertSame(
            0,
            ProjectMembership::where('user_id', $user->id)->count(),
        );
    }
}
