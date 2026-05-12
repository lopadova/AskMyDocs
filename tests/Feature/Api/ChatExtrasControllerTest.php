<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Http\Controllers\Api\ChatExtrasController;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * v4.5/W7 — ChatExtrasController surface:
 *  - GET /api/chat/cost-rates    — public cost-rate table
 *  - POST /test/conversations/{id}/branch-from-message/{m} — conversation fork
 *  - POST /test/conversations/{id}/suggested-followups     — LLM-generated pills
 *
 * R26: AiManager is mocked — every test that touches the LLM path uses
 * Mockery so the suite stays offline.
 * R30/R31: cross-tenant ownership is asserted explicitly for the
 * mutation endpoints.
 */
final class ChatExtrasControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Register dedicated test-only routes (NOT inside the auth
        // middleware group) so route-model binding resolves cleanly
        // without the auth + CSRF + session middleware stack. The
        // production routes in routes/web.php are auth-protected and
        // already covered by the integration tests in Feature/Api.
        // Mirrors the ChatFilterPresetControllerTest pattern.
        Route::middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)->group(function () {
            Route::get('/test/api/chat/cost-rates', [ChatExtrasController::class, 'costRates']);
            Route::post(
                '/test/conversations/{conversation}/branch-from-message/{message}',
                [ChatExtrasController::class, 'branchFromMessage'],
            );
            Route::post(
                '/test/conversations/{conversation}/suggested-followups',
                [ChatExtrasController::class, 'suggestedFollowups'],
            );
            Route::delete(
                '/test/conversations/{conversation}/messages-from/{message}',
                [ChatExtrasController::class, 'truncateMessagesFrom'],
            );
        });
    }

    public function test_cost_rates_returns_the_config_table(): void
    {
        config()->set('ai.cost_rates', [
            'openai' => [
                'default' => ['input' => 2.5, 'output' => 10.0],
                'gpt-4o' => ['input' => 2.5, 'output' => 10.0],
            ],
        ]);

        $resp = $this->getJson('/test/api/chat/cost-rates')->assertOk();

        // PHP-side JSON encoding turns 10.0 into 10 (no trailing zero).
        // Use loose equality on the parsed JSON to bridge that gap.
        $rates = $resp->json('rates');
        $this->assertEqualsWithDelta(2.5, $rates['openai']['gpt-4o']['input'], 0.001);
        $this->assertEqualsWithDelta(10.0, $rates['openai']['gpt-4o']['output'], 0.001);
    }

    public function test_cost_rates_falls_back_to_empty_on_missing_config(): void
    {
        config()->set('ai.cost_rates', null);

        $this->getJson('/test/api/chat/cost-rates')
            ->assertOk()
            ->assertJsonPath('rates', []);
    }

    public function test_cost_rates_sets_cache_header(): void
    {
        config()->set('ai.cost_rates', []);

        $resp = $this->getJson('/test/api/chat/cost-rates')->assertOk();
        // Laravel's ResponseHeaderBag may reorder Cache-Control directives
        // (it normalises into a set). Assert the salient bits instead of
        // pinning the literal string order.
        $cacheControl = $resp->headers->get('Cache-Control', '');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=3600', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
    }

    public function test_branch_from_message_creates_a_fork_with_messages_up_to_and_including_anchor(): void
    {
        $alice = $this->makeUser('alice');
        Sanctum::actingAs($alice);

        $conversation = $alice->conversations()->create([
            'title' => 'Original thread',
            'project_key' => 'hr-portal',
        ]);

        $m1 = $conversation->messages()->create(['role' => 'user', 'content' => 'How does PTO work?']);
        $m2 = $conversation->messages()->create(['role' => 'assistant', 'content' => 'PTO accrues monthly.']);
        $m3 = $conversation->messages()->create(['role' => 'user', 'content' => 'For new hires?']);
        $m4 = $conversation->messages()->create(['role' => 'assistant', 'content' => 'After 90 days.']);

        $resp = $this->postJson("/test/conversations/{$conversation->id}/branch-from-message/{$m2->id}");

        $resp->assertStatus(201)
            ->assertJsonPath('conversation.project_key', 'hr-portal')
            ->assertJsonPath('conversation.title', 'Original thread (branch)');

        $branchId = $resp->json('conversation.id');
        $this->assertNotSame($conversation->id, $branchId);

        // Branch should contain m1 + m2 only — NOT m3, NOT m4.
        $branch = Conversation::findOrFail($branchId);
        $branchMessages = $branch->messages()->orderBy('id')->get();
        $this->assertCount(2, $branchMessages);
        $this->assertSame('How does PTO work?', $branchMessages[0]->content);
        $this->assertSame('PTO accrues monthly.', $branchMessages[1]->content);

        // Source thread is untouched.
        $this->assertSame(4, $conversation->messages()->count());
    }

    public function test_branch_from_message_returns_403_for_cross_user_conversation(): void
    {
        $alice = $this->makeUser('alice');
        $bob = $this->makeUser('bob');

        $aliceConv = $alice->conversations()->create(['title' => 'Alice thread']);
        $aliceMsg = $aliceConv->messages()->create(['role' => 'assistant', 'content' => 'hi']);

        Sanctum::actingAs($bob);

        $this->postJson("/test/conversations/{$aliceConv->id}/branch-from-message/{$aliceMsg->id}")
            ->assertStatus(403);

        // Cross-tenant fork attempt must have created NOTHING for bob.
        $this->assertSame(0, Conversation::where('user_id', $bob->id)->count());
    }

    public function test_branch_from_message_returns_404_when_message_is_not_in_conversation(): void
    {
        $alice = $this->makeUser('alice');
        Sanctum::actingAs($alice);

        $convA = $alice->conversations()->create(['title' => 'A']);
        $convB = $alice->conversations()->create(['title' => 'B']);
        $msgInB = $convB->messages()->create(['role' => 'user', 'content' => 'wrong thread']);

        $this->postJson("/test/conversations/{$convA->id}/branch-from-message/{$msgInB->id}")
            ->assertStatus(404);
    }

    public function test_suggested_followups_returns_parsed_json_array(): void
    {
        $alice = $this->makeUser('alice');
        Sanctum::actingAs($alice);

        $conv = $alice->conversations()->create(['title' => 'PTO']);
        $conv->messages()->create(['role' => 'user', 'content' => 'How does PTO work?']);
        $conv->messages()->create([
            'role' => 'assistant',
            'content' => 'PTO accrues at 1.5 days per month.',
        ]);

        $this->mock(AiManager::class, function (MockInterface $m) {
            $m->shouldReceive('chat')->once()->andReturn(new AiResponse(
                content: '["Does the rate change for managers?", "What about part-timers?", "Compare with v2 of the policy"]',
                provider: 'openai',
                model: 'gpt-4o-mini',
                promptTokens: 50,
                completionTokens: 50,
                totalTokens: 100,
            ));
        });

        $resp = $this->postJson("/test/conversations/{$conv->id}/suggested-followups");

        $resp->assertOk()
            ->assertJsonCount(3, 'suggestions')
            ->assertJsonPath('suggestions.0', 'Does the rate change for managers?')
            ->assertJsonPath('suggestions.1', 'What about part-timers?');
    }

    public function test_suggested_followups_returns_empty_array_when_provider_throws(): void
    {
        $alice = $this->makeUser('alice');
        Sanctum::actingAs($alice);

        $conv = $alice->conversations()->create(['title' => 'PTO']);
        $conv->messages()->create(['role' => 'user', 'content' => 'How does PTO work?']);
        $conv->messages()->create(['role' => 'assistant', 'content' => 'a reply.']);

        $this->mock(AiManager::class, function (MockInterface $m) {
            $m->shouldReceive('chat')->andThrow(new \RuntimeException('upstream provider 503'));
        });

        $resp = $this->postJson("/test/conversations/{$conv->id}/suggested-followups");

        $resp->assertOk()->assertJsonPath('suggestions', []);
    }

    public function test_suggested_followups_returns_empty_array_when_no_assistant_turn_exists(): void
    {
        $alice = $this->makeUser('alice');
        Sanctum::actingAs($alice);

        $conv = $alice->conversations()->create(['title' => 'empty']);
        // Only a user message — no assistant turn to suggest follow-ups for.
        $conv->messages()->create(['role' => 'user', 'content' => 'hello']);

        // R26 — the BE MUST NOT call the LLM when there's no input pair.
        $this->mock(AiManager::class, function (MockInterface $m) {
            $m->shouldNotReceive('chat');
        });

        $this->postJson("/test/conversations/{$conv->id}/suggested-followups")
            ->assertOk()
            ->assertJsonPath('suggestions', []);
    }

    public function test_suggested_followups_returns_403_for_cross_user_conversation(): void
    {
        $alice = $this->makeUser('alice');
        $bob = $this->makeUser('bob');
        $aliceConv = $alice->conversations()->create(['title' => 'Alice']);
        $aliceConv->messages()->create(['role' => 'user', 'content' => 'hi']);
        $aliceConv->messages()->create(['role' => 'assistant', 'content' => 'reply']);

        Sanctum::actingAs($bob);

        $this->mock(AiManager::class, function (MockInterface $m) {
            $m->shouldNotReceive('chat');
        });

        $this->postJson("/test/conversations/{$aliceConv->id}/suggested-followups")
            ->assertStatus(403);
    }

    public function test_truncate_messages_from_deletes_message_and_subsequent_rows(): void
    {
        $alice = $this->makeUser('alice');
        Sanctum::actingAs($alice);

        $conv = $alice->conversations()->create(['title' => 'Edit target']);
        $m1 = $conv->messages()->create(['role' => 'user', 'content' => 'First question']);
        $m2 = $conv->messages()->create(['role' => 'assistant', 'content' => 'First answer']);
        $m3 = $conv->messages()->create(['role' => 'user', 'content' => 'Second question']);
        $m4 = $conv->messages()->create(['role' => 'assistant', 'content' => 'Second answer']);

        // Truncate from m3 onwards (the user message being edited).
        $resp = $this->deleteJson("/test/conversations/{$conv->id}/messages-from/{$m3->id}");

        $resp->assertOk()
            ->assertJsonPath('deleted_count', 2); // m3 + m4 deleted

        // m1 + m2 must survive.
        $this->assertDatabaseHas('messages', ['id' => $m1->id]);
        $this->assertDatabaseHas('messages', ['id' => $m2->id]);
        $this->assertDatabaseMissing('messages', ['id' => $m3->id]);
        $this->assertDatabaseMissing('messages', ['id' => $m4->id]);
    }

    public function test_truncate_messages_from_returns_403_for_cross_user_conversation(): void
    {
        $alice = $this->makeUser('alice');
        $bob = $this->makeUser('bob');

        $aliceConv = $alice->conversations()->create(['title' => 'Alice thread']);
        $aliceMsg = $aliceConv->messages()->create(['role' => 'user', 'content' => 'hi']);

        Sanctum::actingAs($bob);

        $this->deleteJson("/test/conversations/{$aliceConv->id}/messages-from/{$aliceMsg->id}")
            ->assertStatus(403);

        // Alice's message must be untouched.
        $this->assertDatabaseHas('messages', ['id' => $aliceMsg->id]);
    }

    public function test_truncate_messages_from_returns_404_when_message_is_not_in_conversation(): void
    {
        $alice = $this->makeUser('alice');
        Sanctum::actingAs($alice);

        $convA = $alice->conversations()->create(['title' => 'A']);
        $convB = $alice->conversations()->create(['title' => 'B']);
        $msgInB = $convB->messages()->create(['role' => 'user', 'content' => 'wrong thread']);

        $this->deleteJson("/test/conversations/{$convA->id}/messages-from/{$msgInB->id}")
            ->assertStatus(404);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeUser(string $name): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $name.'-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        // Refresh to clear `wasRecentlyCreated` and re-hydrate the model
        // from DB (mirrors ChatFilterPresetControllerTest pattern). The
        // re-fetched model returns through the BelongsToTenant boot
        // hook so tenant_id is populated for the auth user.
        $user->refresh();
        return $user;
    }
}
