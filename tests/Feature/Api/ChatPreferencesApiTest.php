<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.0.1 / deep-review F5 — server-side chat preferences endpoint.
 *
 * Covers:
 *   - default-shape response when no preferences are stored;
 *   - PATCH persists a boolean toggle;
 *   - merged shape returns both stored value AND default contract;
 *   - `null` value deletes a key (resets to default);
 *   - cross-session persistence: a re-login returns the stored value;
 *   - 401 on anonymous access;
 *   - 422 on malformed payload (non-array, non-scalar value).
 */
final class ChatPreferencesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_show_returns_defaults_when_user_has_no_preferences(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->getJson('/api/me/chat-preferences')
            ->assertOk()
            ->assertJson([
                'preferences' => ['counterfactual_enabled' => true],
                'defaults' => ['counterfactual_enabled' => true],
            ]);
    }

    public function test_update_accepts_native_json_booleans(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->patchJson('/api/me/chat-preferences', [
            'preferences' => ['counterfactual_enabled' => false],
        ])->assertOk()->assertJson([
            'preferences' => ['counterfactual_enabled' => false],
        ]);

        $user->refresh();
        $this->assertSame(false, $user->chat_preferences['counterfactual_enabled']);
    }

    public function test_update_persists_boolean_toggle_across_calls(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->patchJson('/api/me/chat-preferences', [
            'preferences' => ['counterfactual_enabled' => '0'],
        ])->assertOk()->assertJson([
            'preferences' => ['counterfactual_enabled' => false],
        ]);

        // A second GET returns the persisted value (no localStorage, no
        // session — the BE remembers it).
        $this->actingAs($user)->getJson('/api/me/chat-preferences')
            ->assertOk()
            ->assertJson([
                'preferences' => ['counterfactual_enabled' => false],
            ]);

        $user->refresh();
        $this->assertSame(
            ['counterfactual_enabled' => false],
            $user->chat_preferences,
        );
    }

    public function test_null_value_deletes_key_and_default_fills_it_back(): void
    {
        $user = $this->makeUser();
        $user->chat_preferences = ['counterfactual_enabled' => false];
        $user->save();

        $this->actingAs($user)->patchJson('/api/me/chat-preferences', [
            'preferences' => ['counterfactual_enabled' => null],
        ])->assertOk()->assertJson([
            'preferences' => ['counterfactual_enabled' => true],
        ]);

        $user->refresh();
        $this->assertSame([], $user->chat_preferences);
    }

    public function test_unknown_keys_are_accepted_so_future_FE_toggles_land_without_a_BE_deploy(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->patchJson('/api/me/chat-preferences', [
            'preferences' => [
                'counterfactual_enabled' => '0',
                'future_toggle_xyz' => '1',
            ],
        ])->assertOk()->assertJson([
            'preferences' => [
                'counterfactual_enabled' => false,
                'future_toggle_xyz' => true,
            ],
        ]);
    }

    public function test_anonymous_request_is_rejected(): void
    {
        $this->getJson('/api/me/chat-preferences')->assertUnauthorized();
        $this->patchJson('/api/me/chat-preferences', [
            'preferences' => ['counterfactual_enabled' => '1'],
        ])->assertUnauthorized();
    }

    public function test_malformed_body_returns_422(): void
    {
        $user = $this->makeUser();

        // Missing preferences key.
        $this->actingAs($user)->patchJson('/api/me/chat-preferences', [])
            ->assertStatus(422);

        // Nested object as a value — rejected by the controller's
        // custom validation closure (accepts only bool, the strings
        // '0'/'1'/'true'/'false', or null; an array is none of those).
        $this->actingAs($user)->patchJson('/api/me/chat-preferences', [
            'preferences' => ['counterfactual_enabled' => ['nested' => true]],
        ])->assertStatus(422);
    }

    private function makeUser(): User
    {
        $u = User::create([
            'name' => 'user',
            'email' => 'user-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $u->assignRole('viewer');

        return $u;
    }
}
