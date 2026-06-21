<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\ConversationController;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * R30 — the chat sidebar must list ONLY the conversations of the active
 * team. The list endpoint was scoped by `user_id` alone, so the same
 * threads showed under every team the user belonged to; switching team
 * in the topbar appeared to do nothing in the chat panel. This pins the
 * tenant scope on GET /conversations.
 */
final class ConversationControllerTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        // routes/web.php is not auto-loaded under Testbench; register the
        // single endpoint under test. `tenant.resolve` makes the
        // X-Tenant-Id header drive TenantContext exactly like production.
        $router->middleware(['web', \App\Http\Middleware\ResolveTenant::class])
            ->group(function () use ($router): void {
                $router->get('/conversations', [ConversationController::class, 'index']);
            });
    }

    public function test_list_returns_only_active_tenant_conversations(): void
    {
        $user = User::create([
            'name' => 'Chat User',
            'email' => 'chat-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);

        $ctx = app(TenantContext::class);

        // Two conversations in `default`, one in `acme` — all the SAME user.
        $ctx->set('default');
        $user->conversations()->create(['title' => 'Default A', 'project_key' => 'hr-portal']);
        $user->conversations()->create(['title' => 'Default B', 'project_key' => 'hr-portal']);

        $ctx->set('acme');
        $user->conversations()->create(['title' => 'Acme Only', 'project_key' => 'acme-kb']);

        $ctx->reset();

        // No header → default tenant: sees exactly the two default threads.
        $titles = collect(
            $this->actingAs($user)->getJson('/conversations')->assertOk()->json()
        )->pluck('title')->sort()->values()->all();
        $this->assertSame(['Default A', 'Default B'], $titles);

        // X-Tenant-Id: acme → membership-less header is fine here (the route
        // group under test omits AuthorizeTenantHeader; we are pinning the
        // QUERY scope, not the header policy). Sees only the acme thread.
        $acmeTitles = collect(
            $this->actingAs($user)
                ->withHeader('X-Tenant-Id', 'acme')
                ->getJson('/conversations')
                ->assertOk()
                ->json()
        )->pluck('title')->all();
        $this->assertSame(['Acme Only'], $acmeTitles);
    }

    public function test_list_is_empty_in_a_team_with_no_conversations(): void
    {
        $user = User::create([
            'name' => 'Empty Team',
            'email' => 'empty-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);

        app(TenantContext::class)->set('default');
        $user->conversations()->create(['title' => 'Only in default']);
        app(TenantContext::class)->reset();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'surface')
            ->getJson('/conversations')
            ->assertOk()
            ->assertJsonCount(0);
    }
}
