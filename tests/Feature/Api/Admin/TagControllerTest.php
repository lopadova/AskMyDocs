<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\KbTag;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * T2.10 — admin RESTful CRUD on `kb_tags`.
 *
 * Coverage: index (with project_keys filter), store (validation +
 * per-project unique slug), show, update (label/color/slug/reject
 * project_key change), destroy (with cascade verification on the
 * knowledge_document_tags pivot), 403 for non-admin, 401 for guest.
 */
final class TagControllerTest extends TestCase
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

    public function test_index_lists_all_tags_for_admin(): void
    {
        $admin = $this->makeAdmin();
        KbTag::create(['project_key' => 'hr', 'slug' => 'policy', 'label' => 'Policy', 'color' => '#0a0a0a']);
        KbTag::create(['project_key' => 'engineering', 'slug' => 'release', 'label' => 'Release', 'color' => null]);

        $resp = $this->actingAs($admin)->getJson('/api/admin/kb/tags');

        $resp->assertOk()->assertJsonCount(2, 'data');
        // Sorted by project_key + slug → 'engineering' before 'hr'.
        $this->assertSame('engineering', $resp->json('data.0.project_key'));
        $this->assertSame('hr', $resp->json('data.1.project_key'));
    }

    public function test_index_filters_by_project_keys(): void
    {
        $admin = $this->makeAdmin();
        KbTag::create(['project_key' => 'hr', 'slug' => 'policy', 'label' => 'Policy']);
        KbTag::create(['project_key' => 'engineering', 'slug' => 'release', 'label' => 'Release']);
        KbTag::create(['project_key' => 'finance', 'slug' => 'compliance', 'label' => 'Compliance']);

        $resp = $this->actingAs($admin)
            ->getJson('/api/admin/kb/tags?project_keys[]=hr&project_keys[]=finance');

        $resp->assertOk()->assertJsonCount(2, 'data');
        $projectKeys = collect($resp->json('data'))->pluck('project_key')->all();
        $this->assertEqualsCanonicalizing(['hr', 'finance'], $projectKeys);
    }

    public function test_store_creates_tag_with_all_fields(): void
    {
        $admin = $this->makeAdmin();

        $resp = $this->actingAs($admin)->postJson('/api/admin/kb/tags', [
            'project_key' => 'hr',
            'slug' => 'policy',
            'label' => 'Policy',
            'color' => '#1a2b3c',
        ]);

        $resp->assertStatus(201)
            ->assertJsonPath('data.project_key', 'hr')
            ->assertJsonPath('data.slug', 'policy')
            ->assertJsonPath('data.label', 'Policy')
            ->assertJsonPath('data.color', '#1a2b3c');
        $this->assertDatabaseHas('kb_tags', ['project_key' => 'hr', 'slug' => 'policy']);
    }

    public function test_store_rejects_invalid_slug(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson('/api/admin/kb/tags', [
            'project_key' => 'hr',
            'slug' => 'Has Spaces',
            'label' => 'Bad',
        ])->assertStatus(422)->assertJsonValidationErrors(['slug']);
    }

    public function test_store_rejects_invalid_color_format(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson('/api/admin/kb/tags', [
            'project_key' => 'hr',
            'slug' => 'policy',
            'label' => 'Policy',
            'color' => 'red',  // not a hex
        ])->assertStatus(422)->assertJsonValidationErrors(['color']);
    }

    public function test_store_rejects_duplicate_slug_within_project(): void
    {
        $admin = $this->makeAdmin();
        KbTag::create(['project_key' => 'hr', 'slug' => 'policy', 'label' => 'Policy']);

        $this->actingAs($admin)->postJson('/api/admin/kb/tags', [
            'project_key' => 'hr',
            'slug' => 'policy',
            'label' => 'Duplicate',
        ])->assertStatus(422)->assertJsonValidationErrors(['slug']);
    }

    public function test_store_allows_same_slug_across_different_projects(): void
    {
        // Per-project tenant isolation: hr/policy + engineering/policy are
        // independent records.
        $admin = $this->makeAdmin();
        KbTag::create(['project_key' => 'hr', 'slug' => 'policy', 'label' => 'HR Policy']);

        $this->actingAs($admin)->postJson('/api/admin/kb/tags', [
            'project_key' => 'engineering',
            'slug' => 'policy',
            'label' => 'Eng Policy',
        ])->assertStatus(201);
    }

    public function test_show_returns_tag(): void
    {
        $admin = $this->makeAdmin();
        $tag = KbTag::create(['project_key' => 'hr', 'slug' => 'policy', 'label' => 'Policy']);

        $this->actingAs($admin)->getJson("/api/admin/kb/tags/{$tag->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $tag->id);
    }

    public function test_show_returns_404_for_missing_tag(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->getJson('/api/admin/kb/tags/999999')
            ->assertStatus(404);
    }

    public function test_update_modifies_label_and_color(): void
    {
        $admin = $this->makeAdmin();
        $tag = KbTag::create(['project_key' => 'hr', 'slug' => 'policy', 'label' => 'Old', 'color' => '#aaaaaa']);

        $this->actingAs($admin)->putJson("/api/admin/kb/tags/{$tag->id}", [
            'label' => 'New Label',
            'color' => '#bb22cc',
        ])->assertOk()
          ->assertJsonPath('data.label', 'New Label')
          ->assertJsonPath('data.color', '#bb22cc');

        $tag->refresh();
        $this->assertSame('New Label', $tag->label);
    }

    public function test_update_can_clear_color_via_null(): void
    {
        $admin = $this->makeAdmin();
        $tag = KbTag::create(['project_key' => 'hr', 'slug' => 'policy', 'label' => 'P', 'color' => '#abcdef']);

        $this->actingAs($admin)->putJson("/api/admin/kb/tags/{$tag->id}", ['color' => null])
            ->assertOk()
            ->assertJsonPath('data.color', null);
    }

    public function test_update_rejects_project_key_change(): void
    {
        // Per controller docblock — moving a tag between projects would
        // orphan the document-tag pivot rows, so it's rejected with 422.
        $admin = $this->makeAdmin();
        $tag = KbTag::create(['project_key' => 'hr', 'slug' => 'policy', 'label' => 'P']);

        $this->actingAs($admin)->putJson("/api/admin/kb/tags/{$tag->id}", [
            'project_key' => 'engineering',
            'label' => 'Other',
        ])->assertStatus(422)->assertJsonValidationErrors(['project_key']);
    }

    public function test_update_allows_slug_change_when_unique_within_project(): void
    {
        $admin = $this->makeAdmin();
        $tag = KbTag::create(['project_key' => 'hr', 'slug' => 'old-slug', 'label' => 'L']);

        $this->actingAs($admin)->putJson("/api/admin/kb/tags/{$tag->id}", ['slug' => 'new-slug'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'new-slug');
    }

    public function test_update_rejects_slug_change_to_existing_value_in_same_project(): void
    {
        $admin = $this->makeAdmin();
        KbTag::create(['project_key' => 'hr', 'slug' => 'taken', 'label' => 'T']);
        $tag = KbTag::create(['project_key' => 'hr', 'slug' => 'mine', 'label' => 'M']);

        $this->actingAs($admin)->putJson("/api/admin/kb/tags/{$tag->id}", ['slug' => 'taken'])
            ->assertStatus(422)->assertJsonValidationErrors(['slug']);
    }

    public function test_destroy_removes_tag(): void
    {
        $admin = $this->makeAdmin();
        $tag = KbTag::create(['project_key' => 'hr', 'slug' => 'policy', 'label' => 'P']);

        $this->actingAs($admin)->deleteJson("/api/admin/kb/tags/{$tag->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('kb_tags', ['id' => $tag->id]);
    }

    public function test_destroy_cascades_pivot_rows(): void
    {
        // FK cascade verification: deleting a tag removes its
        // knowledge_document_tags rows atomically. This is the
        // load-bearing invariant — without it, deleting a tag would
        // leave orphan associations the FE can't render correctly.
        $admin = $this->makeAdmin();
        $tag = KbTag::create(['project_key' => 'hr', 'slug' => 'policy', 'label' => 'Policy']);

        $doc = KnowledgeDocument::create([
            'project_key' => 'hr',
            'source_type' => 'markdown',
            'title' => 'Sample',
            'source_path' => 'sample.md',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'metadata' => [],
            'status' => 'indexed',
        ]);

        DB::table('knowledge_document_tags')->insert([
            'kb_tag_id' => $tag->id,
            'knowledge_document_id' => $doc->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertDatabaseHas('knowledge_document_tags', ['kb_tag_id' => $tag->id]);

        $this->actingAs($admin)->deleteJson("/api/admin/kb/tags/{$tag->id}")
            ->assertStatus(204);

        // Pivot row gone via FK CASCADE — no manual cleanup, no orphans.
        $this->assertDatabaseMissing('knowledge_document_tags', ['kb_tag_id' => $tag->id]);
    }

    public function test_non_admin_gets_403(): void
    {
        $viewer = User::create([
            'name' => 'Viewer',
            'email' => 'viewer-'.uniqid().'@demo.local',
            'password' => Hash::make('secret'),
        ]);
        $viewer->assignRole('viewer');

        $this->actingAs($viewer)->getJson('/api/admin/kb/tags')->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/admin/kb/tags')->assertStatus(401);
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
