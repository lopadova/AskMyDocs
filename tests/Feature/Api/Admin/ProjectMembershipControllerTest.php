<?php

namespace Tests\Feature\Api\Admin;

use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
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

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
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

        $this->assertSame(1, ProjectMembership::query()->where('user_id', $user->id)->count());
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

        return $user;
    }
}
