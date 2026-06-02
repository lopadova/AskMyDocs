<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\KbSynonym;
use App\Models\User;
use App\Services\Kb\Retrieval\SynonymExpander;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.7/W1 — admin RESTful CRUD on `kb_synonyms`.
 *
 * Coverage: index (+ project_keys filter), store (validation, lowercasing,
 * per-(tenant, project) unique term, ≥1 distinct synonym), show, update
 * (term/synonyms/enabled, reject project_key change, unique re-check),
 * destroy, 403 non-admin, 401 guest.
 */
final class SynonymControllerTest extends TestCase
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

    public function test_index_lists_synonyms_sorted(): void
    {
        $admin = $this->makeAdmin();
        KbSynonym::create(['project_key' => 'hr', 'term' => 'pto', 'synonyms' => ['paid time off']]);
        KbSynonym::create(['project_key' => 'eng', 'term' => 'k8s', 'synonyms' => ['kubernetes']]);

        $resp = $this->actingAs($admin)->getJson('/api/admin/kb/synonyms');

        $resp->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame('eng', $resp->json('data.0.project_key'));
    }

    public function test_index_filters_by_project_keys(): void
    {
        $admin = $this->makeAdmin();
        KbSynonym::create(['project_key' => 'hr', 'term' => 'pto', 'synonyms' => ['paid time off']]);
        KbSynonym::create(['project_key' => 'eng', 'term' => 'k8s', 'synonyms' => ['kubernetes']]);

        $resp = $this->actingAs($admin)->getJson('/api/admin/kb/synonyms?project_keys[]=eng');

        $resp->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('eng', $resp->json('data.0.project_key'));
    }

    public function test_store_lowercases_and_dedupes(): void
    {
        $admin = $this->makeAdmin();

        $resp = $this->actingAs($admin)->postJson('/api/admin/kb/synonyms', [
            'project_key' => 'eng',
            'term' => 'K8S',
            'synonyms' => ['Kubernetes', 'KUBERNETES', 'container orchestration'],
        ]);

        $resp->assertStatus(201)
            ->assertJsonPath('data.term', 'k8s');
        $synonyms = $resp->json('data.synonyms');
        $this->assertSame(['kubernetes', 'container orchestration'], $synonyms);
        $this->assertDatabaseHas('kb_synonyms', ['project_key' => 'eng', 'term' => 'k8s']);
    }

    public function test_store_rejects_synonyms_equal_to_term_only(): void
    {
        $admin = $this->makeAdmin();

        // The single synonym collapses to the term → no distinct member.
        $this->actingAs($admin)->postJson('/api/admin/kb/synonyms', [
            'project_key' => 'eng',
            'term' => 'k8s',
            'synonyms' => ['K8S'],
        ])->assertStatus(422)->assertJsonValidationErrors(['synonyms']);
    }

    public function test_store_requires_synonyms(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson('/api/admin/kb/synonyms', [
            'project_key' => 'eng',
            'term' => 'k8s',
            'synonyms' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['synonyms']);
    }

    public function test_store_rejects_duplicate_term_within_project(): void
    {
        $admin = $this->makeAdmin();
        KbSynonym::create(['project_key' => 'eng', 'term' => 'k8s', 'synonyms' => ['kubernetes']]);

        $this->actingAs($admin)->postJson('/api/admin/kb/synonyms', [
            'project_key' => 'eng',
            'term' => 'k8s',
            'synonyms' => ['container platform'],
        ])->assertStatus(422)->assertJsonValidationErrors(['term']);
    }

    public function test_store_rejects_case_insensitive_duplicate_term(): void
    {
        // `K8S` must collide with the existing `k8s` as a 422 on `term`,
        // NOT slip past Rule::unique and 500 on the DB unique constraint.
        $admin = $this->makeAdmin();
        KbSynonym::create(['project_key' => 'eng', 'term' => 'k8s', 'synonyms' => ['kubernetes']]);

        $this->actingAs($admin)->postJson('/api/admin/kb/synonyms', [
            'project_key' => 'eng',
            'term' => 'K8S',
            'synonyms' => ['container platform'],
        ])->assertStatus(422)->assertJsonValidationErrors(['term']);
    }

    public function test_store_rejects_whitespace_only_term(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson('/api/admin/kb/synonyms', [
            'project_key' => 'eng',
            'term' => '   ',
            'synonyms' => ['kubernetes'],
        ])->assertStatus(422)->assertJsonValidationErrors(['term']);
    }

    public function test_delete_invalidates_the_expander_cache(): void
    {
        $admin = $this->makeAdmin();
        config()->set('kb.synonyms.cache_ttl_seconds', 300);
        config()->set('kb.synonyms.enabled', true);
        $row = KbSynonym::create(['project_key' => 'eng', 'term' => 'k8s', 'synonyms' => ['kubernetes']]);

        $expander = new SynonymExpander(app(TenantContext::class));
        // Prime the (tenant, project) cache.
        $this->assertContains('kubernetes', $expander->expansionPhrases('deploy k8s', 'eng'));

        $this->actingAs($admin)->deleteJson("/api/admin/kb/synonyms/{$row->id}")->assertStatus(204);

        // Cache was busted by the controller → no stale expansion.
        $this->assertSame([], $expander->expansionPhrases('deploy k8s', 'eng'));
    }

    public function test_store_allows_same_term_across_projects(): void
    {
        $admin = $this->makeAdmin();
        KbSynonym::create(['project_key' => 'eng', 'term' => 'k8s', 'synonyms' => ['kubernetes']]);

        $this->actingAs($admin)->postJson('/api/admin/kb/synonyms', [
            'project_key' => 'ops',
            'term' => 'k8s',
            'synonyms' => ['kubernetes'],
        ])->assertStatus(201);
    }

    public function test_show_returns_404_for_missing(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->getJson('/api/admin/kb/synonyms/999999')->assertStatus(404);
    }

    public function test_update_modifies_synonyms_and_enabled(): void
    {
        $admin = $this->makeAdmin();
        $row = KbSynonym::create(['project_key' => 'eng', 'term' => 'k8s', 'synonyms' => ['kubernetes']]);

        $this->actingAs($admin)->putJson("/api/admin/kb/synonyms/{$row->id}", [
            'synonyms' => ['kubernetes', 'k8s cluster'],
            'enabled' => false,
        ])->assertOk()
          ->assertJsonPath('data.enabled', false);

        $row->refresh();
        $this->assertSame(['kubernetes', 'k8s cluster'], $row->synonyms);
        $this->assertFalse($row->enabled);
    }

    public function test_update_changing_term_to_collide_with_only_synonym_rejects(): void
    {
        // Group is {ci, continuous integration}. Renaming the term to
        // 'continuous integration' (without sending synonyms) would leave
        // the single existing synonym equal to the new term → 0 distinct
        // members. Must 422, not silently persist a meaningless group.
        $admin = $this->makeAdmin();
        $row = KbSynonym::create(['project_key' => 'eng', 'term' => 'ci', 'synonyms' => ['continuous integration']]);

        $this->actingAs($admin)->putJson("/api/admin/kb/synonyms/{$row->id}", [
            'term' => 'continuous integration',
        ])->assertStatus(422)->assertJsonValidationErrors(['synonyms']);
    }

    public function test_update_changing_term_keeps_remaining_distinct_synonyms(): void
    {
        $admin = $this->makeAdmin();
        $row = KbSynonym::create(['project_key' => 'eng', 'term' => 'ci', 'synonyms' => ['continuous integration', 'build pipeline']]);

        $this->actingAs($admin)->putJson("/api/admin/kb/synonyms/{$row->id}", [
            'term' => 'continuous integration',
        ])->assertOk()->assertJsonPath('data.term', 'continuous integration');

        $row->refresh();
        // The colliding synonym is dropped; the other survives.
        $this->assertSame(['build pipeline'], $row->synonyms);
    }

    public function test_update_rejects_project_key_change(): void
    {
        $admin = $this->makeAdmin();
        $row = KbSynonym::create(['project_key' => 'eng', 'term' => 'k8s', 'synonyms' => ['kubernetes']]);

        $this->actingAs($admin)->putJson("/api/admin/kb/synonyms/{$row->id}", [
            'project_key' => 'ops',
            'synonyms' => ['kubernetes'],
        ])->assertStatus(422)->assertJsonValidationErrors(['project_key']);
    }

    public function test_destroy_removes_row(): void
    {
        $admin = $this->makeAdmin();
        $row = KbSynonym::create(['project_key' => 'eng', 'term' => 'k8s', 'synonyms' => ['kubernetes']]);

        $this->actingAs($admin)->deleteJson("/api/admin/kb/synonyms/{$row->id}")->assertStatus(204);
        $this->assertDatabaseMissing('kb_synonyms', ['id' => $row->id]);
    }

    public function test_non_admin_gets_403(): void
    {
        $viewer = User::create([
            'name' => 'Viewer',
            'email' => 'viewer-'.uniqid().'@demo.local',
            'password' => Hash::make('secret'),
        ]);
        $viewer->assignRole('viewer');

        $this->actingAs($viewer)->getJson('/api/admin/kb/synonyms')->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/admin/kb/synonyms')->assertStatus(401);
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
