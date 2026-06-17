<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\KnowledgeDocument;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.9 — admin RESTful CRUD on the `projects` registry.
 *
 * Coverage: index (+ counts), store (auto-slug + per-tenant unique),
 * update (name/description, reject key change), destroy (blocked while
 * in use), tenant isolation, the unified kb/projects picker, RBAC.
 */
final class ProjectControllerTest extends TestCase
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
    }

    public function test_index_lists_active_tenant_projects_with_counts(): void
    {
        $admin = $this->makeAdmin();

        $p = Project::create(['project_key' => 'surface-kb', 'name' => 'Surface KB']);
        Project::create(['project_key' => 'ffw-kb', 'name' => 'FFW']);

        // Two docs + one membership reference surface-kb in the same tenant.
        $this->seedDoc('surface-kb');
        $this->seedDoc('surface-kb');
        ProjectMembership::create(['user_id' => $admin->id, 'project_key' => 'surface-kb', 'role' => 'admin']);

        $resp = $this->actingAs($admin)->getJson('/api/admin/projects')->assertOk();

        $resp->assertJsonCount(2, 'data');
        $row = collect($resp->json('data'))->firstWhere('project_key', 'surface-kb');
        $this->assertSame('Surface KB', $row['name']);
        $this->assertSame(2, $row['document_count']);
        $this->assertSame(1, $row['member_count']);
        $this->assertSame((string) $p->id, (string) $row['id']);
    }

    public function test_store_auto_slugs_the_key_from_the_name(): void
    {
        $admin = $this->makeAdmin();

        $resp = $this->actingAs($admin)->postJson('/api/admin/projects', [
            'name' => 'Surface KB',
        ])->assertStatus(201);

        $resp->assertJsonPath('data.project_key', 'surface-kb')
            ->assertJsonPath('data.name', 'Surface KB');
        $this->assertDatabaseHas('projects', [
            'tenant_id' => 'default',
            'project_key' => 'surface-kb',
            'name' => 'Surface KB',
        ]);
    }

    public function test_store_accepts_an_explicit_key_and_normalises_it(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson('/api/admin/projects', [
            'name' => 'Whatever',
            'project_key' => 'My Custom Key',
        ])->assertStatus(201)
            ->assertJsonPath('data.project_key', 'my-custom-key');
    }

    public function test_store_rejects_duplicate_key_within_tenant(): void
    {
        $admin = $this->makeAdmin();
        Project::create(['project_key' => 'surface-kb', 'name' => 'Surface KB']);

        $this->actingAs($admin)->postJson('/api/admin/projects', [
            'name' => 'Surface KB',
        ])->assertStatus(422)->assertJsonValidationErrors('project_key');
    }

    public function test_two_tenants_may_share_the_same_key(): void
    {
        $admin = $this->makeAdmin();

        // Pre-seed a project in tenant 'acme' directly.
        $ctx = app(TenantContext::class);
        $ctx->set('acme');
        Project::create(['project_key' => 'surface-kb', 'name' => 'Acme Surface']);
        $ctx->reset();

        // Same key in the active (default) tenant must be allowed.
        $this->actingAs($admin)->postJson('/api/admin/projects', [
            'name' => 'Surface KB',
        ])->assertStatus(201)->assertJsonPath('data.project_key', 'surface-kb');
    }

    public function test_update_changes_name_and_description(): void
    {
        $admin = $this->makeAdmin();
        $p = Project::create(['project_key' => 'surface-kb', 'name' => 'Old']);

        $this->actingAs($admin)->patchJson("/api/admin/projects/{$p->id}", [
            'name' => 'New name',
            'description' => 'Hello',
        ])->assertOk()
            ->assertJsonPath('data.name', 'New name')
            ->assertJsonPath('data.description', 'Hello');
    }

    public function test_update_rejects_blank_name(): void
    {
        // store() requires a non-empty name; update() must not let a PATCH blank it.
        $admin = $this->makeAdmin();
        $p = Project::create(['project_key' => 'surface-kb', 'name' => 'Surface']);

        $this->actingAs($admin)->patchJson("/api/admin/projects/{$p->id}", [
            'name' => '',
        ])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_update_rejects_key_change(): void
    {
        $admin = $this->makeAdmin();
        $p = Project::create(['project_key' => 'surface-kb', 'name' => 'Surface']);

        $this->actingAs($admin)->patchJson("/api/admin/projects/{$p->id}", [
            'project_key' => 'something-else',
        ])->assertStatus(422)->assertJsonValidationErrors('project_key');

        $this->assertDatabaseHas('projects', ['id' => $p->id, 'project_key' => 'surface-kb']);
    }

    public function test_destroy_is_blocked_while_documents_reference_the_key(): void
    {
        $admin = $this->makeAdmin();
        $p = Project::create(['project_key' => 'surface-kb', 'name' => 'Surface']);
        $this->seedDoc('surface-kb');

        $this->actingAs($admin)->deleteJson("/api/admin/projects/{$p->id}")
            ->assertStatus(422)->assertJsonValidationErrors('project_key');

        $this->assertDatabaseHas('projects', ['id' => $p->id]);
    }

    public function test_destroy_is_blocked_while_memberships_reference_the_key(): void
    {
        $admin = $this->makeAdmin();
        $p = Project::create(['project_key' => 'surface-kb', 'name' => 'Surface']);
        ProjectMembership::create(['user_id' => $admin->id, 'project_key' => 'surface-kb', 'role' => 'admin']);

        $this->actingAs($admin)->deleteJson("/api/admin/projects/{$p->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('projects', ['id' => $p->id]);
    }

    public function test_destroy_succeeds_for_an_unused_project(): void
    {
        $admin = $this->makeAdmin();
        $p = Project::create(['project_key' => 'empty-kb', 'name' => 'Empty']);

        $this->actingAs($admin)->deleteJson("/api/admin/projects/{$p->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('projects', ['id' => $p->id]);
    }

    public function test_index_and_mutations_are_tenant_isolated(): void
    {
        $admin = $this->makeAdmin();

        $ctx = app(TenantContext::class);
        $ctx->set('acme');
        $foreign = Project::create(['project_key' => 'acme-only', 'name' => 'Acme Only']);
        $ctx->reset();

        // Active tenant is default: the acme project must not appear...
        $this->actingAs($admin)->getJson('/api/admin/projects')
            ->assertOk()->assertJsonCount(0, 'data');

        // ...and its id is a 404 from the default tenant (IDOR guard).
        $this->actingAs($admin)->patchJson("/api/admin/projects/{$foreign->id}", ['name' => 'x'])
            ->assertStatus(404);
        $this->actingAs($admin)->deleteJson("/api/admin/projects/{$foreign->id}")
            ->assertStatus(404);
    }

    public function test_picker_unions_registry_documents_and_memberships(): void
    {
        $admin = $this->makeAdmin();

        Project::create(['project_key' => 'registry-only', 'name' => 'Registry Only']);
        $this->seedDoc('doc-only');
        ProjectMembership::create(['user_id' => $admin->id, 'project_key' => 'membership-only', 'role' => 'admin']);

        $resp = $this->actingAs($admin)->getJson('/api/admin/kb/projects')->assertOk();

        $this->assertSame(
            ['doc-only', 'membership-only', 'registry-only'],
            $resp->json('projects'),
        );
    }

    public function test_non_admin_gets_403(): void
    {
        $viewer = User::create([
            'name' => 'Viewer',
            'email' => 'viewer-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $viewer->assignRole('viewer');

        $this->actingAs($viewer)->getJson('/api/admin/projects')->assertStatus(403);
        $this->actingAs($viewer)->postJson('/api/admin/projects', ['name' => 'X'])->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/admin/projects')->assertStatus(401);
    }

    private function seedDoc(string $projectKey): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => 'Doc '.uniqid(),
            'source_path' => 'seed/'.uniqid().'.md',
            'document_hash' => hash('sha256', uniqid('', true)),
            'version_hash' => hash('sha256', uniqid('', true)),
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
}
