<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\ConversationController;
use App\Models\ChatFolder;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Chat\ConversationImportance;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * HTTP surface for session organisation.
 *
 * These routes live in `routes/web.php`, which
 * {@see \Tests\Feature\Security\AdminAuthorizationMatrixTest} does not
 * mount — its `defineRoutes()` loads `routes/api.php` only. That makes
 * this file the COMPENSATING control for the blind spot: cross-user and
 * cross-tenant denial on the PATCH are asserted here, explicitly.
 */
final class ConversationOrganizationTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        // routes/web.php is not auto-loaded under Testbench; register the
        // endpoints under test.
        //
        // ResolveTenant comes FIRST, before the `web` group, mirroring the
        // production `$middleware->prepend(...)` in bootstrap/app.php. Order
        // is load-bearing here: `web` contains SubstituteBindings, and
        // Conversation::resolveRouteBinding() is tenant-scoped — put
        // ResolveTenant after the group and the binding resolves against the
        // WRONG tenant, so every {conversation} route 404s.
        $router->middleware([\App\Http\Middleware\ResolveTenant::class, 'web'])
            ->group(function () use ($router): void {
                $router->get('/conversations', [ConversationController::class, 'index']);
                $router->patch('/conversations/{conversation}', [ConversationController::class, 'update']);
            });
    }

    private function user(string $name = 'Session Owner'): User
    {
        return User::create([
            'name' => $name,
            'email' => 'org-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function conversation(User $user, array $attributes = [], string $tenantId = 'acme'): Conversation
    {
        $previous = app(TenantContext::class)->current();
        app(TenantContext::class)->set($tenantId);

        $conversation = Conversation::query()->create(array_merge([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'title' => 'Thread',
            'project_key' => 'engineering',
        ], $attributes));

        app(TenantContext::class)->set($previous);

        return $conversation;
    }

    private function folder(User $user, string $name = 'Issue 42', string $tenantId = 'acme'): ChatFolder
    {
        return ChatFolder::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'name' => $name,
        ]);
    }

    public function test_the_list_still_answers_a_bare_array_with_the_organisation_keys_added(): void
    {
        // R27: additive only. The endpoint has always returned a BARE
        // array, so a `{data: …}` envelope here would be a one-way door.
        $user = $this->user();
        $this->conversation($user);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'acme')
            ->getJson('/conversations')
            ->assertOk();

        $body = $response->json();
        $this->assertIsArray($body);
        $this->assertArrayNotHasKey('data', $body);
        $this->assertSame([
            'id', 'title', 'project_key', 'chat_folder_id',
            'pinned_at', 'archived_at', 'importance',
            'created_at', 'updated_at',
        ], array_keys($body[0]));
        // The machine-readable identifier, never localized (R24).
        $this->assertSame('normal', $body[0]['importance']);
        $this->assertNull($body[0]['pinned_at']);
    }

    public function test_the_list_never_echoes_the_tenant_or_owner_id(): void
    {
        $user = $this->user();
        $this->conversation($user);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'acme')
            ->getJson('/conversations')
            ->assertOk()
            ->assertJsonMissing(['tenant_id' => 'acme'])
            ->assertJsonMissingPath('0.tenant_id')
            ->assertJsonMissingPath('0.user_id');
    }

    public function test_archived_sessions_are_hidden_by_default_and_reachable_on_request(): void
    {
        $user = $this->user();
        $active = $this->conversation($user, ['title' => 'Active']);
        $archived = $this->conversation($user, ['title' => 'Gone', 'archived_at' => now()]);

        $default = $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme')
            ->getJson('/conversations')->assertOk()->json();
        $this->assertSame([$active->id], array_column($default, 'id'));

        $drawer = $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme')
            ->getJson('/conversations?archived=1')->assertOk()->json();
        $this->assertSame([$archived->id], array_column($drawer, 'id'));

        $all = $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme')
            ->getJson('/conversations?archived=all')->assertOk()->json();
        $this->assertCount(2, $all);
    }

    public function test_an_unknown_archived_value_fails_closed_to_the_active_listing(): void
    {
        $user = $this->user();
        $this->conversation($user, ['title' => 'Active']);
        $this->conversation($user, ['title' => 'Gone', 'archived_at' => now()]);

        $body = $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme')
            ->getJson('/conversations?archived=yes-please')->assertOk()->json();

        $this->assertSame(['Active'], array_column($body, 'title'));
    }

    public function test_the_list_orders_pinned_then_importance_then_recency(): void
    {
        $user = $this->user();

        // Built to FAIL under plain `updated_at DESC`: the most recently
        // updated thread must sort LAST (R16).
        $newest = $this->conversation($user, ['title' => 'newest-normal']);
        $newest->forceFill(['updated_at' => Carbon::parse('2026-09-16 12:00:00')])->saveQuietly();
        $critical = $this->conversation($user, [
            'title' => 'critical',
            'importance' => ConversationImportance::Critical->value,
        ]);
        $critical->forceFill(['updated_at' => Carbon::parse('2026-09-01 12:00:00')])->saveQuietly();
        $pinned = $this->conversation($user, [
            'title' => 'pinned',
            'pinned_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);
        $pinned->forceFill(['updated_at' => Carbon::parse('2026-01-01 00:00:00')])->saveQuietly();

        $titles = array_column(
            $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme')
                ->getJson('/conversations')->assertOk()->json(),
            'title',
        );

        $this->assertSame(['pinned', 'critical', 'newest-normal'], $titles);
    }

    public function test_it_pins_archives_and_flags_a_session(): void
    {
        $user = $this->user();
        $conversation = $this->conversation($user);

        // The request takes BOOLEANS, the response returns TIMESTAMPS —
        // the client never invents a date.
        $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme')
            ->patchJson("/conversations/{$conversation->id}", ['pinned' => true])
            ->assertOk()
            ->assertJsonPath('id', $conversation->id);

        $this->assertNotNull($conversation->fresh()->pinned_at);

        $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme')
            ->patchJson("/conversations/{$conversation->id}", ['archived' => true])
            ->assertOk();
        $this->assertNotNull($conversation->fresh()->archived_at);

        $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme')
            ->patchJson("/conversations/{$conversation->id}", ['importance' => 'critical'])
            ->assertOk()
            ->assertJsonPath('importance', 'critical');
    }

    public function test_organising_a_session_does_not_move_it_to_the_top_of_the_list(): void
    {
        $user = $this->user();
        $conversation = $this->conversation($user);
        $conversation->forceFill(['updated_at' => Carbon::parse('2026-01-01 00:00:00')])->saveQuietly();

        $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme')
            ->patchJson("/conversations/{$conversation->id}", ['archived' => true])
            ->assertOk();
        $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme')
            ->patchJson("/conversations/{$conversation->id}", ['archived' => false])
            ->assertOk();

        $this->assertSame(
            '2026-01-01 00:00:00',
            $conversation->fresh()->updated_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_renaming_still_works_and_still_records_activity(): void
    {
        $user = $this->user();
        $conversation = $this->conversation($user);
        $conversation->forceFill(['updated_at' => Carbon::parse('2026-01-01 00:00:00')])->saveQuietly();

        Carbon::setTestNow('2026-09-16 15:00:00');
        $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme')
            ->patchJson("/conversations/{$conversation->id}", ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('title', 'Renamed');
        Carbon::setTestNow();

        $this->assertSame(
            '2026-09-16 15:00:00',
            $conversation->fresh()->updated_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_it_files_and_unfiles_a_session(): void
    {
        $user = $this->user();
        $folder = $this->folder($user);
        $conversation = $this->conversation($user);

        $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme')
            ->patchJson("/conversations/{$conversation->id}", ['chat_folder_id' => $folder->id])
            ->assertOk()
            ->assertJsonPath('chat_folder_id', $folder->id);

        $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme')
            ->patchJson("/conversations/{$conversation->id}", ['chat_folder_id' => null])
            ->assertOk()
            ->assertJsonPath('chat_folder_id', null);
    }

    public function test_it_refuses_another_users_folder_with_422_instead_of_cross_filing(): void
    {
        // SEC-IDOR-001: the folder id is attacker-chosen. Without the
        // ownership predicate on the exists rule, user A could file a
        // thread into user B's folder.
        $user = $this->user();
        $other = $this->user('Other');
        $theirFolder = $this->folder($other, 'Private');
        $conversation = $this->conversation($user);

        $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme')
            ->patchJson("/conversations/{$conversation->id}", ['chat_folder_id' => $theirFolder->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('chat_folder_id');

        $this->assertNull($conversation->fresh()->chat_folder_id);
    }

    public function test_it_refuses_a_folder_from_another_tenant_with_422(): void
    {
        $user = $this->user();
        $elsewhere = $this->folder($user, 'Other tenant', 'globex');
        $conversation = $this->conversation($user);

        $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme')
            ->patchJson("/conversations/{$conversation->id}", ['chat_folder_id' => $elsewhere->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('chat_folder_id');
    }

    public function test_an_unknown_importance_is_rejected(): void
    {
        $user = $this->user();
        $conversation = $this->conversation($user);

        $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme')
            ->patchJson("/conversations/{$conversation->id}", ['importance' => 'apocalyptic'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('importance');
    }

    public function test_an_empty_body_is_rejected_rather_than_answered_200(): void
    {
        // R14: a caller must be able to tell "nothing applied" from
        // "applied nothing".
        $user = $this->user();
        $conversation = $this->conversation($user);

        $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme')
            ->patchJson("/conversations/{$conversation->id}", [])
            ->assertStatus(422);
    }

    public function test_another_user_cannot_organise_a_session(): void
    {
        $owner = $this->user();
        $attacker = $this->user('Attacker');
        $conversation = $this->conversation($owner);

        $this->actingAs($attacker)->withHeader('X-Tenant-Id', 'acme')
            ->patchJson("/conversations/{$conversation->id}", ['pinned' => true])
            ->assertStatus(403);

        $this->assertNull($conversation->fresh()->pinned_at);
    }

    public function test_a_session_in_another_tenant_is_not_found(): void
    {
        // R30: the route binding is tenant-scoped, so the id is invisible
        // from the wrong tenant — 404, not 403, so there is no existence
        // oracle.
        $user = $this->user();
        $elsewhere = $this->conversation($user, [], 'globex');

        $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme')
            ->patchJson("/conversations/{$elsewhere->id}", ['pinned' => true])
            ->assertStatus(404);

        $this->assertNull($elsewhere->fresh()->pinned_at);
    }

    public function test_the_list_is_scoped_to_the_active_tenant(): void
    {
        $user = $this->user();
        $here = $this->conversation($user, ['title' => 'Here']);
        $this->conversation($user, ['title' => 'Elsewhere'], 'globex');

        $body = $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme')
            ->getJson('/conversations')->assertOk()->json();

        $this->assertSame([$here->id], array_column($body, 'id'));
    }
}
