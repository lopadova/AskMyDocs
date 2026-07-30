<?php

namespace Tests\Feature\Api\Admin;

use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Tests\TestCase;

/**
 * PR7 / Phase F2 — project memberships CRUD.
 *
 * project_key is validated against the distinct set on knowledge_documents,
 * so each test seeds at least one doc with the keys it intends to use.
 */
class ProjectMembershipControllerTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 'membership-test';

    private const ANCHOR_PROJECT = 'tenant-anchor';

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        Tenant::query()->updateOrCreate(
            ['slug' => self::TENANT],
            ['name' => 'Membership Test', 'status' => 'active', 'is_system' => false],
        );
        app(TenantContext::class)->set(self::TENANT);
        $this->withHeader('X-Tenant-Id', self::TENANT);
        $this->seedProjectKey(self::ANCHOR_PROJECT);
        $this->seedProjectKey('hr-portal');
        $this->seedProjectKey('engineering');
    }

    public function test_index_lists_memberships_for_user(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('mem');

        ProjectMembership::create([
            'user_id' => $user->id,
            'project_key' => 'hr-portal',
            'role' => 'member',
            'scope_allowlist' => null,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/admin/users/{$user->id}/memberships")
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'user_id', 'project_key', 'role', 'scope_allowlist']]]);

        $keys = array_column($response->json('data'), 'project_key');
        $this->assertContains('hr-portal', $keys);
    }

    public function test_store_creates_membership(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('new');

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$user->id}/memberships", [
                'project_key' => 'hr-portal',
                'role' => 'member',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.project_key', 'hr-portal')
            ->assertJsonPath('data.role', 'member');

        $this->assertDatabaseHas('project_memberships', [
            'user_id' => $user->id,
            'project_key' => 'hr-portal',
        ]);
    }

    public function test_store_upserts_existing_membership(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('up');

        ProjectMembership::create([
            'user_id' => $user->id,
            'project_key' => 'hr-portal',
            'role' => 'member',
            'scope_allowlist' => null,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$user->id}/memberships", [
                'project_key' => 'hr-portal',
                'role' => 'owner',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.role', 'owner');

        $this->assertSame(
            1,
            ProjectMembership::query()
                ->forTenant(self::TENANT)
                ->where('user_id', $user->id)
                ->where('project_key', 'hr-portal')
                ->count(),
        );
    }

    public function test_store_rejects_unknown_project_key_with_422(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('bad');

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$user->id}/memberships", [
                'project_key' => 'nope-does-not-exist',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_key']);
    }

    public function test_store_accepts_valid_scope_allowlist(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('scope-ok');

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$user->id}/memberships", [
                'project_key' => 'engineering',
                'role' => 'member',
                'scope_allowlist' => [
                    'folder_globs' => ['docs/**', 'runbooks/*'],
                    'tags' => ['public'],
                ],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.scope_allowlist.folder_globs', ['docs/**', 'runbooks/*'])
            ->assertJsonPath('data.scope_allowlist.tags', ['public']);
    }

    public function test_store_rejects_invalid_scope_allowlist_shape(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('scope-bad');

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$user->id}/memberships", [
                'project_key' => 'engineering',
                'scope_allowlist' => [
                    'folder_globs' => 'not-an-array',
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scope_allowlist.folder_globs']);
    }

    public function test_update_changes_role(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('upd');
        $m = ProjectMembership::create([
            'user_id' => $user->id,
            'project_key' => 'hr-portal',
            'role' => 'member',
            'scope_allowlist' => null,
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/memberships/{$m->id}", ['role' => 'owner'])
            ->assertOk()
            ->assertJsonPath('data.role', 'owner');
    }

    public function test_update_rejects_invalid_scope_allowlist(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('upd-bad');
        $m = ProjectMembership::create([
            'user_id' => $user->id,
            'project_key' => 'hr-portal',
            'role' => 'member',
            'scope_allowlist' => null,
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/memberships/{$m->id}", [
                'scope_allowlist' => ['folder_globs' => 'nope'],
            ])
            ->assertStatus(422);
    }

    public function test_destroy_deletes_membership(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('del');
        $m = ProjectMembership::create([
            'user_id' => $user->id,
            'project_key' => 'hr-portal',
            'role' => 'member',
            'scope_allowlist' => null,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/memberships/{$m->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('project_memberships', ['id' => $m->id]);
    }

    public function test_destroy_blocks_the_last_membership_of_the_last_tenant_super_admin(): void
    {
        $admin = $this->makeAdmin();
        $superAdmin = $this->makeViewer('last-super');
        $superAdmin->syncRoles(['super-admin']);
        $membership = $superAdmin->projectMemberships()
            ->forTenant(self::TENANT)
            ->firstOrFail();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/memberships/{$membership->id}")
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'Cannot remove the last super-admin membership for this tenant.',
            );

        $this->assertDatabaseHas('project_memberships', ['id' => $membership->id]);
    }

    public function test_destroy_allows_one_of_multiple_memberships_for_the_same_super_admin(): void
    {
        $admin = $this->makeAdmin();
        $superAdmin = $this->makeViewer('multi-project-super');
        $superAdmin->syncRoles(['super-admin']);
        $membership = ProjectMembership::create([
            'tenant_id' => self::TENANT,
            'user_id' => $superAdmin->id,
            'project_key' => 'hr-portal',
            'role' => 'owner',
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/memberships/{$membership->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('project_memberships', ['id' => $membership->id]);
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => self::TENANT,
            'user_id' => $superAdmin->id,
            'project_key' => self::ANCHOR_PROJECT,
        ]);
    }

    public function test_destroy_allows_membership_removal_when_another_super_admin_remains(): void
    {
        $admin = $this->makeAdmin();
        $first = $this->makeViewer('first-super');
        $first->syncRoles(['super-admin']);
        $second = $this->makeViewer('second-super');
        $second->syncRoles(['super-admin']);
        $membership = $first->projectMemberships()
            ->forTenant(self::TENANT)
            ->firstOrFail();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/memberships/{$membership->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('project_memberships', ['id' => $membership->id]);
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => self::TENANT,
            'user_id' => $second->id,
        ]);
    }

    public function test_index_hides_memberships_of_other_tenants(): void
    {
        // R30 — the Users screen lists memberships of the ACTIVE team only.
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('xtenant');

        ProjectMembership::create([
            'user_id' => $user->id,
            'project_key' => 'hr-portal',
            'role' => 'member',
        ]);
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'acme-kb',
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/admin/users/{$user->id}/memberships")
            ->assertOk();

        $keys = array_column($response->json('data'), 'project_key');
        $this->assertSame(['hr-portal', self::ANCHOR_PROJECT], $keys);
    }

    public function test_store_writes_membership_into_the_active_tenant(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('write');

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$user->id}/memberships", [
                'project_key' => 'hr-portal',
                'role' => 'member',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('project_memberships', [
            'user_id' => $user->id,
            'project_key' => 'hr-portal',
            'tenant_id' => self::TENANT,
        ]);
    }

    public function test_index_and_store_hide_user_from_another_tenant(): void
    {
        $admin = $this->makeAdmin();
        $foreign = User::create([
            'name' => 'Foreign',
            'email' => 'foreign-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $foreign->assignRole('viewer');
        ProjectMembership::create([
            'tenant_id' => 'other-tenant',
            'user_id' => $foreign->id,
            'project_key' => 'other-project',
            'role' => 'member',
        ]);

        $this->actingAs($admin)
            ->getJson("/api/admin/users/{$foreign->id}/memberships")
            ->assertNotFound();
        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$foreign->id}/memberships", [
                'project_key' => 'hr-portal',
                'role' => 'member',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('project_memberships', [
            'tenant_id' => self::TENANT,
            'user_id' => $foreign->id,
            'project_key' => 'hr-portal',
        ]);
    }

    public function test_update_and_destroy_404_on_foreign_tenant_membership(): void
    {
        // R30 — implicit binding resolves by id with no tenant scope; the
        // controller must 404 (existence-hiding) on rows of other tenants.
        $admin = $this->makeAdmin();
        $user = $this->makeViewer('idor');
        $foreign = ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'acme-kb',
            'role' => 'member',
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/memberships/{$foreign->id}", ['role' => 'admin'])
            ->assertStatus(404);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/memberships/{$foreign->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('project_memberships', [
            'id' => $foreign->id,
            'role' => 'member',
        ]);
    }

    public function test_non_admin_gets_403(): void
    {
        $viewer = $this->makeViewer('rbac');

        $this->actingAs($viewer)
            ->getJson("/api/admin/users/{$viewer->id}/memberships")
            ->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $user = $this->makeViewer('g');

        $this->getJson("/api/admin/users/{$user->id}/memberships")
            ->assertStatus(401);
    }

    private function seedProjectKey(string $key): void
    {
        KnowledgeDocument::create([
            'project_key' => $key,
            'source_type' => 'markdown',
            'title' => 'Seed '.$key,
            'source_path' => "seed/{$key}.md",
            'document_hash' => hash('sha256', $key),
            'version_hash' => hash('sha256', $key.':v1'),
            'status' => 'indexed',
        ]);
    }

    private function makeAdmin(): User
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $admin->assignRole('admin');
        $this->grantTenantMembership($admin);

        return $admin;
    }

    private function makeViewer(string $slug): User
    {
        $user = User::create([
            'name' => $slug,
            'email' => $slug.'-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('viewer');
        $this->grantTenantMembership($user);

        return $user;
    }

    private function grantTenantMembership(User $user): void
    {
        ProjectMembership::firstOrCreate([
            'tenant_id' => self::TENANT,
            'user_id' => $user->id,
            'project_key' => self::ANCHOR_PROJECT,
        ], [
            'role' => 'member',
        ]);
    }
}
