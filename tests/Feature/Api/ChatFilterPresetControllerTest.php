<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\ChatFilterPresetController;
use App\Models\ChatFilterPreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T2.9 backend slice — exercises the RESTful CRUD on chat_filter_presets
 * with the per-user isolation invariant (user A cannot see, modify, or
 * delete user B's presets).
 *
 * The FE consumer (FilterBar dropdown) is deferred to a follow-up FE
 * session per CLAUDE.md "test in browser before claiming success" rule.
 * The contract here defines what the FE will eventually consume.
 */
final class ChatFilterPresetControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum isn't loaded under Testbench — register the apiResource
        // routes raw so the controller logic runs without auth middleware.
        // We still call Sanctum::actingAs() in each test to attach the
        // authenticated user the controller reads via `$request->user()`.
        Route::apiResource('/api/chat-filter-presets', ChatFilterPresetController::class)
            ->parameters(['chat-filter-presets' => 'id'])
            ->names('api.chat-filter-presets');
    }

    public function test_index_returns_only_authenticated_users_presets(): void
    {
        $alice = $this->makeUser('alice');
        $bob = $this->makeUser('bob');

        $alice->refresh();
        $bob->refresh();

        ChatFilterPreset::create(['user_id' => $alice->id, 'name' => 'A1', 'filters' => ['project_keys' => ['hr']]]);
        ChatFilterPreset::create(['user_id' => $alice->id, 'name' => 'A2', 'filters' => []]);
        ChatFilterPreset::create(['user_id' => $bob->id, 'name' => 'B1', 'filters' => []]);

        Sanctum::actingAs($alice);

        $resp = $this->getJson('/api/chat-filter-presets');

        $resp->assertOk()->assertJsonCount(2, 'data');
        $names = collect($resp->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['A1', 'A2'], $names);
    }

    public function test_store_creates_preset_owned_by_authenticated_user(): void
    {
        $alice = $this->makeUser('alice');
        Sanctum::actingAs($alice);

        $resp = $this->postJson('/api/chat-filter-presets', [
            'name' => 'My HR + PDF combo',
            'filters' => [
                'project_keys' => ['hr-portal'],
                'source_types' => ['pdf'],
            ],
        ]);

        $resp->assertStatus(201)
            ->assertJsonPath('data.name', 'My HR + PDF combo')
            ->assertJsonPath('data.filters.project_keys.0', 'hr-portal')
            ->assertJsonPath('data.filters.source_types.0', 'pdf');

        $this->assertDatabaseHas('chat_filter_presets', [
            'user_id' => $alice->id,
            'name' => 'My HR + PDF combo',
        ]);
    }

    public function test_store_rejects_duplicate_name_for_same_user_with_422(): void
    {
        $alice = $this->makeUser('alice');
        Sanctum::actingAs($alice);

        ChatFilterPreset::create(['user_id' => $alice->id, 'name' => 'Same', 'filters' => []]);

        $this->postJson('/api/chat-filter-presets', [
            'name' => 'Same',
            'filters' => ['source_types' => ['pdf']],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_allows_same_name_for_different_users(): void
    {
        // Per-user uniqueness — the same display name across different
        // users is intentional (user A's "My presets" vs user B's
        // "My presets" are independent records).
        $alice = $this->makeUser('alice');
        $bob = $this->makeUser('bob');

        ChatFilterPreset::create(['user_id' => $alice->id, 'name' => 'Default', 'filters' => []]);

        Sanctum::actingAs($bob);

        $this->postJson('/api/chat-filter-presets', [
            'name' => 'Default',
            'filters' => ['source_types' => ['markdown']],
        ])->assertStatus(201);
    }

    public function test_show_returns_preset_for_owner(): void
    {
        $alice = $this->makeUser('alice');
        $preset = ChatFilterPreset::create([
            'user_id' => $alice->id,
            'name' => 'Mine',
            'filters' => ['source_types' => ['pdf']],
        ]);

        Sanctum::actingAs($alice);

        $this->getJson("/api/chat-filter-presets/{$preset->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $preset->id)
            ->assertJsonPath('data.name', 'Mine');
    }

    public function test_show_returns_404_when_preset_belongs_to_other_user(): void
    {
        // R21-adjacent: 404 (not 403) — the API doesn't leak the
        // existence of another user's presets. From the caller's
        // perspective the preset simply doesn't exist.
        $alice = $this->makeUser('alice');
        $bob = $this->makeUser('bob');
        $bobPreset = ChatFilterPreset::create([
            'user_id' => $bob->id,
            'name' => 'Bob secret',
            'filters' => [],
        ]);

        Sanctum::actingAs($alice);

        $this->getJson("/api/chat-filter-presets/{$bobPreset->id}")
            ->assertStatus(404);
    }

    public function test_update_modifies_preset_for_owner(): void
    {
        $alice = $this->makeUser('alice');
        $preset = ChatFilterPreset::create([
            'user_id' => $alice->id,
            'name' => 'Old',
            'filters' => ['source_types' => ['pdf']],
        ]);

        Sanctum::actingAs($alice);

        $resp = $this->putJson("/api/chat-filter-presets/{$preset->id}", [
            'name' => 'New',
            'filters' => ['source_types' => ['markdown', 'pdf'], 'project_keys' => ['hr']],
        ]);

        $resp->assertOk()
            ->assertJsonPath('data.name', 'New')
            ->assertJsonPath('data.filters.project_keys.0', 'hr');

        $preset->refresh();
        $this->assertSame('New', $preset->name);
        $this->assertSame(['markdown', 'pdf'], $preset->filters['source_types']);
    }

    public function test_update_cannot_modify_another_users_preset(): void
    {
        $alice = $this->makeUser('alice');
        $bob = $this->makeUser('bob');
        $bobPreset = ChatFilterPreset::create([
            'user_id' => $bob->id,
            'name' => 'Bob preset',
            'filters' => [],
        ]);

        Sanctum::actingAs($alice);

        $this->putJson("/api/chat-filter-presets/{$bobPreset->id}", [
            'name' => 'Hijacked',
            'filters' => ['source_types' => ['pdf']],
        ])->assertStatus(404);

        // Verify Bob's preset was NOT mutated.
        $bobPreset->refresh();
        $this->assertSame('Bob preset', $bobPreset->name);
    }

    public function test_destroy_deletes_preset_for_owner(): void
    {
        $alice = $this->makeUser('alice');
        $preset = ChatFilterPreset::create([
            'user_id' => $alice->id,
            'name' => 'Delete me',
            'filters' => [],
        ]);

        Sanctum::actingAs($alice);

        $this->deleteJson("/api/chat-filter-presets/{$preset->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('chat_filter_presets', ['id' => $preset->id]);
    }

    public function test_destroy_cannot_delete_another_users_preset(): void
    {
        $alice = $this->makeUser('alice');
        $bob = $this->makeUser('bob');
        $bobPreset = ChatFilterPreset::create([
            'user_id' => $bob->id,
            'name' => 'Bob preset',
            'filters' => [],
        ]);

        Sanctum::actingAs($alice);

        $this->deleteJson("/api/chat-filter-presets/{$bobPreset->id}")
            ->assertStatus(404);

        // Verify Bob's preset still exists.
        $this->assertDatabaseHas('chat_filter_presets', ['id' => $bobPreset->id]);
    }

    public function test_filters_round_trip_losslessly(): void
    {
        // The whole point of presets: load → POST to chat → identical
        // retrieval scope. Verify the JSON column preserves nested
        // arrays (the RetrievalFilters payload shape).
        $alice = $this->makeUser('alice');
        Sanctum::actingAs($alice);

        $payload = [
            'project_keys' => ['hr', 'engineering'],
            'tag_slugs' => ['policy', 'security'],
            'source_types' => ['pdf', 'docx'],
            'doc_ids' => [42, 99],
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
            'languages' => ['it', 'en'],
        ];

        $createResp = $this->postJson('/api/chat-filter-presets', [
            'name' => 'Comprehensive',
            'filters' => $payload,
        ])->assertStatus(201);

        $id = $createResp->json('data.id');

        $showResp = $this->getJson("/api/chat-filter-presets/{$id}")->assertOk();

        $this->assertSame($payload, $showResp->json('data.filters'));
    }

    public function test_cascade_does_NOT_remove_presets_when_user_soft_deleted(): void
    {
        // Counter-test to the force-delete cascade below: soft-deleting
        // the owner must leave the preset row intact so reactivating the
        // account restores the user's saved filters. This is the whole
        // point of using SoftDeletes on User — soft delete is reversible,
        // hard delete is GDPR-final. Without this assertion we'd never
        // notice a regression that turned a soft delete into a destructive
        // operation (e.g. someone accidentally adding a model observer
        // that hard-cascades on the soft-delete event).
        $alice = $this->makeUser('alice');
        $preset = ChatFilterPreset::create([
            'user_id' => $alice->id,
            'name' => 'Should survive soft delete',
            'filters' => ['source_types' => ['pdf']],
        ]);

        $alice->delete();

        $this->assertDatabaseHas('chat_filter_presets', [
            'id' => $preset->id,
            'name' => 'Should survive soft delete',
        ]);
    }

    public function test_cascade_delete_removes_presets_when_user_force_deleted(): void
    {
        // The User model uses SoftDeletes (CLAUDE.md §6: "soft delete
        // is the default"), so `$alice->delete()` is a soft delete that
        // leaves the row + FKs intact. Only `forceDelete()` triggers
        // the SQL-level cascade. Verify the cascade fires correctly on
        // the hard delete path so the GDPR/data-removal flow doesn't
        // leak presets attached to a removed user.
        $alice = $this->makeUser('alice');
        $preset = ChatFilterPreset::create([
            'user_id' => $alice->id,
            'name' => 'Should cascade',
            'filters' => [],
        ]);

        $alice->forceDelete();

        $this->assertDatabaseMissing('chat_filter_presets', ['id' => $preset->id]);
    }

    private function makeUser(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => $name . '-' . uniqid() . '@demo.local',
            'password' => Hash::make('secret123'),
        ]);
    }
}
