<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ChatFolder;
use App\Models\Conversation;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * HTTP surface for the chat-session folders.
 *
 * Folders have no policy and no global scope by design, so the
 * per-(tenant, user) scoping in the service IS the authorization — these
 * tests exercise it through the real route stack, and assert that
 * someone else's folder is invisible (404) rather than forbidden (403),
 * which would confirm the id exists.
 */
final class ChatFolderControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        // routes/api.php is not auto-loaded under Testbench.
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->onTenant('acme');
    }

    /**
     * Point the request AND the container at one tenant.
     *
     * Testbench does not apply the host bootstrap/app.php middleware
     * prepend, so ResolveTenant never runs and the X-Tenant-Id header
     * alone does not move TenantContext. Same approach as
     * AdminAuthorizationMatrixTest::setUp().
     */
    private function onTenant(string $tenantId): void
    {
        $this->withHeader('X-Tenant-Id', $tenantId);
        app(TenantContext::class)->set($tenantId);
    }

    private function user(string $name = 'Folder Owner'): User
    {
        return User::create([
            'name' => $name,
            'email' => 'folders-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
    }

    private function folder(User $user, string $name, string $tenantId = 'acme', int $position = 0): ChatFolder
    {
        return ChatFolder::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'name' => $name,
            'position' => $position,
        ]);
    }

    public function test_it_lists_the_users_folders_in_a_data_envelope(): void
    {
        $user = $this->user();
        $this->folder($user, 'Alpha', 'acme', 5);
        $this->folder($user, 'Zeta', 'acme', 1);

        $response = $this->actingAs($user)
            ->getJson('/api/chat-folders')
            ->assertOk();

        // Ordered by position, then name — 'Zeta' has the lower position,
        // so a name-only sort would fail this.
        $this->assertSame(['Zeta', 'Alpha'], array_column($response->json('data'), 'name'));
        $this->assertSame(
            ['id', 'name', 'position', 'created_at', 'updated_at'],
            array_keys($response->json('data.0')),
        );
    }

    public function test_the_list_hides_other_users_and_other_tenants_folders(): void
    {
        $user = $this->user();
        $other = $this->user('Other');
        $mine = $this->folder($user, 'Mine');
        $this->folder($other, 'Theirs');
        $this->folder($user, 'Elsewhere', 'globex');

        $data = $this->actingAs($user)
            ->getJson('/api/chat-folders')
            ->assertOk()
            ->json('data');

        $this->assertSame([$mine->id], array_column($data, 'id'));
    }

    public function test_it_creates_a_folder_in_the_active_tenant(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/api/chat-folders', ['name' => 'Issue 42'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Issue 42')
            ->assertJsonPath('data.position', 0);

        $this->assertDatabaseHas('chat_folders', [
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'name' => 'Issue 42',
        ]);
    }

    public function test_a_duplicate_name_for_the_same_user_is_a_422_not_a_500(): void
    {
        // The table's composite unique would otherwise surface as a driver
        // error; validating it turns the constraint into a field error the
        // UI can render (R14).
        $user = $this->user();
        $this->folder($user, 'Issue 42');

        $this->actingAs($user)
            ->postJson('/api/chat-folders', ['name' => 'Issue 42'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_the_same_name_is_allowed_for_another_user_and_another_tenant(): void
    {
        // R30/R31: uniqueness is per (tenant, user). Global uniqueness
        // would let one user squat the obvious folder name for everyone.
        $owner = $this->user();
        $other = $this->user('Other');
        $this->folder($owner, 'Issue 42');

        $this->actingAs($other)
            ->postJson('/api/chat-folders', ['name' => 'Issue 42'])
            ->assertCreated();

        $this->onTenant('globex');
        $this->actingAs($owner)
            ->postJson('/api/chat-folders', ['name' => 'Issue 42'])
            ->assertCreated();

        $this->assertSame(3, ChatFolder::query()->where('name', 'Issue 42')->count());
    }

    public function test_it_renames_a_folder(): void
    {
        $user = $this->user();
        $folder = $this->folder($user, 'Old');

        $this->actingAs($user)
            ->patchJson("/api/chat-folders/{$folder->id}", ['name' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New');

        $this->assertDatabaseHas('chat_folders', ['id' => $folder->id, 'name' => 'New']);
    }

    public function test_renaming_a_folder_to_its_own_name_is_not_a_conflict(): void
    {
        $user = $this->user();
        $folder = $this->folder($user, 'Issue 42');

        $this->actingAs($user)
            ->patchJson("/api/chat-folders/{$folder->id}", ['name' => 'Issue 42'])
            ->assertOk();
    }

    public function test_renaming_onto_an_existing_name_is_rejected(): void
    {
        $user = $this->user();
        $this->folder($user, 'Taken');
        $folder = $this->folder($user, 'Mine');

        $this->actingAs($user)
            ->patchJson("/api/chat-folders/{$folder->id}", ['name' => 'Taken'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_another_users_folder_is_not_found_rather_than_forbidden(): void
    {
        // 404, not 403: a 403 would confirm the id exists
        // (SEC-IDOR-001 — no existence oracle).
        $user = $this->user();
        $other = $this->user('Other');
        $theirs = $this->folder($other, 'Private');

        $this->actingAs($user)->patchJson("/api/chat-folders/{$theirs->id}", ['name' => 'Hijacked'])
            ->assertStatus(404);

        $this->actingAs($user)->deleteJson("/api/chat-folders/{$theirs->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('chat_folders', ['id' => $theirs->id, 'name' => 'Private']);
    }

    public function test_a_folder_in_another_tenant_is_not_found(): void
    {
        $user = $this->user();
        $elsewhere = $this->folder($user, 'Other tenant', 'globex');

        $this->actingAs($user)->deleteJson("/api/chat-folders/{$elsewhere->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('chat_folders', ['id' => $elsewhere->id]);
    }

    public function test_deleting_a_folder_unfiles_its_sessions_and_deletes_none_of_them(): void
    {
        $user = $this->user();
        $folder = $this->folder($user, 'Issue 42');

        app(TenantContext::class)->set('acme');
        $conversation = Conversation::query()->create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'title' => 'Keep me',
            'project_key' => 'engineering',
            'chat_folder_id' => $folder->id,
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/chat-folders/{$folder->id}")
            ->assertNoContent();

        // Cascading would silently destroy chat history: the FK is
        // nullOnDelete, so the thread survives, merely unfiled.
        $this->assertDatabaseMissing('chat_folders', ['id' => $folder->id]);
        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'chat_folder_id' => null,
        ]);
        $this->assertSame(1, Conversation::query()->withoutGlobalScopes()->count());
    }

    public function test_a_blank_name_is_rejected(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/api/chat-folders', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_a_non_numeric_folder_id_is_a_404_not_a_500(): void
    {
        // The controller signatures are `int $id`; without whereNumber on
        // the route, attacker-chosen input reached them as a TypeError.
        $user = $this->user();

        $this->actingAs($user)->deleteJson('/api/chat-folders/abc')->assertStatus(404);
        $this->actingAs($user)
            ->patchJson('/api/chat-folders/abc', ['name' => 'Whatever'])
            ->assertStatus(404);
    }

    public function test_a_guest_is_unauthenticated(): void
    {
        $this->getJson('/api/chat-folders')->assertStatus(401);
    }
}
