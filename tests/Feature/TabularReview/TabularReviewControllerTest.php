<?php

declare(strict_types=1);

namespace Tests\Feature\TabularReview;

use App\Models\KnowledgeDocument;
use App\Models\TabularReview;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v4.7/W1 — Tabular review controller integration.
 *
 * Coverage: index (paged + project filter), store (validation + 201 +
 * required_if json_path), show (with cells), update (project_key
 * immutable), destroy (cascade), clear-cells, viewer ACL (403 on
 * mutation, 200 on read), guest 401, unrole'd-user 403. End-to-end
 * tests for `generate` / `regenerate-cell` / `suggestPrompt` live in
 * {@see TabularReviewExtractorTest} and {@see ColumnPromptSuggesterTest}
 * — driving them through the controller would require running real LLM
 * stubs from inside a HTTP test, which is exactly what those dedicated
 * suites already cover at the service layer.
 */
final class TabularReviewControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_index_lists_tabular_reviews(): void
    {
        $admin = $this->makeAdmin();
        TabularReview::create([
            'project_key' => 'hr',
            'user_id' => $admin->id,
            'title' => 'NDA review',
            'columns_config' => [
                ['name' => 'Title', 'prompt' => 'Doc title', 'format' => 'text'],
            ],
        ]);

        $resp = $this->actingAs($admin)->getJson('/api/admin/tabular-reviews');

        $resp->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('hr', $resp->json('data.0.project_key'));
        $this->assertSame(1, $resp->json('meta.total'));
    }

    public function test_index_filters_by_project_key(): void
    {
        $admin = $this->makeAdmin();
        TabularReview::create([
            'project_key' => 'hr',
            'user_id' => $admin->id,
            'title' => 'A',
            'columns_config' => [['name' => 'X', 'format' => 'text']],
        ]);
        TabularReview::create([
            'project_key' => 'eng',
            'user_id' => $admin->id,
            'title' => 'B',
            'columns_config' => [['name' => 'Y', 'format' => 'text']],
        ]);

        $resp = $this->actingAs($admin)->getJson('/api/admin/tabular-reviews?project_key=eng');

        $resp->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('eng', $resp->json('data.0.project_key'));
    }

    public function test_store_creates_review_with_columns_config(): void
    {
        $admin = $this->makeAdmin();

        $resp = $this->actingAs($admin)->postJson('/api/admin/tabular-reviews', [
            'project_key' => 'hr',
            'title' => 'My review',
            'columns_config' => [
                ['name' => 'Status', 'prompt' => 'What status?', 'format' => 'enum_status', 'enum_values' => ['todo', 'done']],
                ['name' => 'Owner', 'prompt' => 'Who owns?', 'format' => 'person'],
            ],
        ]);

        $resp->assertStatus(201)
            ->assertJsonPath('data.title', 'My review')
            ->assertJsonPath('data.project_key', 'hr');

        $this->assertDatabaseHas('tabular_reviews', [
            'project_key' => 'hr',
            'title' => 'My review',
        ]);
    }

    public function test_store_rejects_empty_columns(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson('/api/admin/tabular-reviews', [
            'project_key' => 'hr',
            'title' => 'X',
            'columns_config' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['columns_config']);
    }

    public function test_store_rejects_invalid_format(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson('/api/admin/tabular-reviews', [
            'project_key' => 'hr',
            'title' => 'X',
            'columns_config' => [
                ['name' => 'Y', 'format' => 'banana'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['columns_config.0.format']);
    }

    public function test_show_returns_review_with_cells(): void
    {
        $admin = $this->makeAdmin();
        $review = TabularReview::create([
            'project_key' => 'hr',
            'user_id' => $admin->id,
            'title' => 'R',
            'columns_config' => [['name' => 'X', 'format' => 'text']],
        ]);

        $resp = $this->actingAs($admin)->getJson("/api/admin/tabular-reviews/{$review->id}");

        $resp->assertOk()
            ->assertJsonPath('data.id', $review->id)
            ->assertJsonStructure(['data', 'cells']);
    }

    public function test_show_returns_404_for_missing_review(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->getJson('/api/admin/tabular-reviews/999999')
            ->assertStatus(404);
    }

    public function test_update_modifies_title(): void
    {
        $admin = $this->makeAdmin();
        $review = TabularReview::create([
            'project_key' => 'hr',
            'user_id' => $admin->id,
            'title' => 'Old',
            'columns_config' => [['name' => 'X', 'format' => 'text']],
        ]);

        $this->actingAs($admin)->patchJson("/api/admin/tabular-reviews/{$review->id}", [
            'title' => 'New title',
        ])->assertOk()->assertJsonPath('data.title', 'New title');
    }

    public function test_update_rejects_project_key_change(): void
    {
        $admin = $this->makeAdmin();
        $review = TabularReview::create([
            'project_key' => 'hr',
            'user_id' => $admin->id,
            'title' => 'X',
            'columns_config' => [['name' => 'Y', 'format' => 'text']],
        ]);

        $this->actingAs($admin)->patchJson("/api/admin/tabular-reviews/{$review->id}", [
            'project_key' => 'eng',
        ])->assertStatus(422)->assertJsonValidationErrors(['project_key']);
    }

    public function test_destroy_cascades_to_cells(): void
    {
        $admin = $this->makeAdmin();
        $review = TabularReview::create([
            'project_key' => 'hr',
            'user_id' => $admin->id,
            'title' => 'X',
            'columns_config' => [['name' => 'Y', 'format' => 'text']],
        ]);

        $doc = $this->makeDoc('hr');
        \App\Models\TabularCell::create([
            'review_id' => $review->id,
            'document_id' => $doc->id,
            'column_index' => 0,
            'content' => ['summary' => 'x', 'flag' => 'green', 'reasoning' => '', 'citations' => []],
            'status' => 'ready',
            'flag' => 'green',
        ]);

        $this->actingAs($admin)->deleteJson("/api/admin/tabular-reviews/{$review->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('tabular_reviews', ['id' => $review->id]);
        $this->assertDatabaseMissing('tabular_cells', ['review_id' => $review->id]);
    }

    public function test_clear_cells_wipes_grid(): void
    {
        $admin = $this->makeAdmin();
        $review = TabularReview::create([
            'project_key' => 'hr',
            'user_id' => $admin->id,
            'title' => 'X',
            'columns_config' => [['name' => 'Y', 'format' => 'text']],
        ]);
        $doc = $this->makeDoc('hr');
        \App\Models\TabularCell::create([
            'review_id' => $review->id,
            'document_id' => $doc->id,
            'column_index' => 0,
            'content' => ['summary' => 'foo', 'flag' => 'green', 'reasoning' => '', 'citations' => []],
            'status' => 'ready',
            'flag' => 'green',
        ]);

        $resp = $this->actingAs($admin)->postJson("/api/admin/tabular-reviews/{$review->id}/clear-cells");

        $resp->assertOk()->assertJsonPath('data.cells_deleted', 1);
        $this->assertDatabaseMissing('tabular_cells', ['review_id' => $review->id]);
    }

    public function test_viewer_can_read_but_not_mutate(): void
    {
        $viewer = User::create([
            'name' => 'V',
            'email' => 'v-'.uniqid().'@demo.local',
            'password' => Hash::make('secret'),
        ]);
        $viewer->assignRole('viewer');

        // index allowed
        $this->actingAs($viewer)->getJson('/api/admin/tabular-reviews')->assertOk();

        // store denied
        $this->actingAs($viewer)->postJson('/api/admin/tabular-reviews', [
            'project_key' => 'hr',
            'title' => 'X',
            'columns_config' => [['name' => 'Y', 'format' => 'text']],
        ])->assertStatus(403);
    }

    public function test_store_requires_json_path_when_format_is_json_path(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson('/api/admin/tabular-reviews', [
            'project_key' => 'hr',
            'title' => 'X',
            'columns_config' => [
                ['name' => 'Status', 'format' => 'json_path'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['columns_config.0.json_path']);
    }

    public function test_store_accepts_json_path_column_with_path(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson('/api/admin/tabular-reviews', [
            'project_key' => 'hr',
            'title' => 'X',
            'columns_config' => [
                ['name' => 'Status', 'format' => 'json_path', 'json_path' => '$.status'],
            ],
        ])->assertStatus(201);
    }

    public function test_viewer_cannot_call_suggest_prompt(): void
    {
        $viewer = User::create([
            'name' => 'V',
            'email' => 'v-'.uniqid().'@demo.local',
            'password' => Hash::make('secret'),
        ]);
        $viewer->assignRole('viewer');

        $this->actingAs($viewer)->postJson('/api/admin/tabular-reviews/prompt', [
            'column_name' => 'Title',
            'format' => 'text',
        ])->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/admin/tabular-reviews')->assertStatus(401);
    }

    public function test_user_without_role_gets_403(): void
    {
        $user = User::create([
            'name' => 'U',
            'email' => 'u-'.uniqid().'@demo.local',
            'password' => Hash::make('secret'),
        ]);

        $this->actingAs($user)->getJson('/api/admin/tabular-reviews')->assertStatus(403);
    }

    private function makeAdmin(): User
    {
        $user = User::create([
            'name' => 'A',
            'email' => 'a-'.uniqid().'@demo.local',
            'password' => Hash::make('secret'),
        ]);
        $user->assignRole('admin');
        return $user;
    }

    private function makeDoc(string $project): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'project_key' => $project,
            'source_type' => 'markdown',
            'title' => 'Sample',
            'source_path' => 'sample-'.uniqid().'.md',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'metadata' => [],
            'status' => 'indexed',
        ]);
    }
}
