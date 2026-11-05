<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\ConversationActivityController;
use App\Http\Middleware\ResolveTenant;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\ConversationActivityService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ConversationActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware([ResolveTenant::class, 'web', 'auth'])->group(function () use ($router): void {
            $router->get('/conversations/activity', [ConversationActivityController::class, 'index']);
            $router->post('/conversations/{conversation}/read', [ConversationActivityController::class, 'read']);
        });
    }

    private function user(): User
    {
        return User::create(['name' => 'Owner', 'email' => Str::uuid().'@example.test', 'password' => 'test-password']);
    }

    private function conversation(User $user, string $tenant = 'acme'): Conversation
    {
        app(TenantContext::class)->set($tenant);
        return Conversation::create(['tenant_id' => $tenant, 'user_id' => $user->id, 'title' => 'Research']);
    }

    private function agentRun(Conversation $conversation, array $attributes = []): AgentRun
    {
        return AgentRun::create(array_merge([
            'run_id' => (string) Str::uuid(), 'tenant_id' => $conversation->tenant_id,
            'user_id' => $conversation->user_id, 'conversation_id' => $conversation->id,
            'channel' => 'web', 'actor_type' => 'user', 'actor_id' => (string) $conversation->user_id,
            'locale' => 'it-IT', 'timezone' => 'Europe/Rome', 'status' => AgentRun::STATUS_RUNNING,
            'input_json' => ['question' => 'Private question'],
        ], $attributes));
    }

    public function test_activity_survives_a_new_request_and_does_not_mark_any_reply_read(): void
    {
        $user = $this->user();
        $conversation = $this->conversation($user);
        $run = $this->agentRun($conversation, ['status' => AgentRun::STATUS_COMPLETED, 'completed_at' => '2026-10-21 12:00:00']);
        $reply = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Private answer', 'agent_run_id' => $run->id]);

        $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme');
        foreach (range(1, 2) as $request) {
            $response = $this->getJson('/conversations/activity')->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private');
            $response->assertJsonPath('0.conversation_id', $conversation->id)
                ->assertJsonPath('0.last_read_message_id', 0)
                ->assertJsonPath('0.latest_reply.message_id', $reply->id)
                ->assertJsonPath('0.latest_reply.created_at', '2026-10-21T12:00:00+00:00')
                ->assertJsonPath('0.run.status', 'completed');
            $this->assertStringNotContainsString('Private', $response->getContent());
        }
        $this->assertSame(0, $conversation->fresh()->last_read_message_id);
    }

    public function test_only_explicit_read_receipts_clear_the_badge_and_cannot_acknowledge_a_future_reply(): void
    {
        $user = $this->user();
        $conversation = $this->conversation($user);
        $conversation->forceFill(['updated_at' => '2026-10-20 12:00:00'])->save();
        $updatedAt = $conversation->fresh()->updated_at->toIso8601String();
        $first = $conversation->messages()->create(['role' => 'assistant', 'content' => 'First']);
        $second = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Second']);
        $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme');

        $this->postJson('/conversations/'.$conversation->id.'/read', ['message_id' => $first->id])
            ->assertOk()->assertJsonPath('last_read_message_id', $first->id);
        $this->getJson('/conversations/activity')->assertOk()
            ->assertJsonPath('0.last_read_message_id', $first->id)
            ->assertJsonPath('0.latest_reply.message_id', $second->id);
        $this->postJson('/conversations/'.$conversation->id.'/read', ['message_id' => $second->id])->assertOk();
        // A delayed receipt from an older tab must not move the watermark back.
        $this->postJson('/conversations/'.$conversation->id.'/read', ['message_id' => $first->id])
            ->assertOk()->assertJsonPath('last_read_message_id', $second->id);
        $this->assertSame($updatedAt, $conversation->fresh()->updated_at->toIso8601String());
    }

    public function test_recovery_returns_root_runs_and_confirmation_without_restarting_them(): void
    {
        $user = $this->user();
        $conversation = $this->conversation($user);
        $root = $this->agentRun($conversation, ['status' => AgentRun::STATUS_AWAITING_CONFIRMATION,
            'budget_json' => ['requested_extension' => ['physical' => 30, 'logical' => 2]]]);
        $this->agentRun($conversation, ['input_json' => ['research_parent_id' => $root->id]]);
        $conversation->forceFill(['archived_at' => now()])->save();

        $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme')
            ->getJson('/conversations/activity')->assertOk()
            ->assertJsonCount(1)->assertJsonPath('0.run.run_id', $root->run_id)
            ->assertJsonPath('0.run.confirmation.physicalExtension', 30)
            ->assertJsonPath('0.run.confirmation.logicalExtension', 2);
        $this->assertDatabaseCount('agent_runs', 2);
    }

    public function test_activity_and_read_receipts_are_scoped_to_owner_and_tenant(): void
    {
        $user = $this->user();
        $own = $this->conversation($user);
        $other = $this->conversation($this->user());
        $foreign = $this->conversation($user, 'globex');
        $reply = $own->messages()->create(['tenant_id' => 'acme', 'role' => 'assistant', 'content' => 'Answer']);
        $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme');
        $this->getJson('/conversations/activity')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.conversation_id', $own->id);
        $this->postJson('/conversations/'.$other->id.'/read', ['message_id' => $reply->id])->assertForbidden();
        $this->postJson('/conversations/'.$foreign->id.'/read', ['message_id' => $reply->id])->assertNotFound();
    }

    public function test_a_receipt_cannot_target_another_chat_or_a_user_message(): void
    {
        $user = $this->user();
        $first = $this->conversation($user);
        $second = $this->conversation($user);
        $wrongReply = $second->messages()->create(['role' => 'assistant', 'content' => 'Other answer']);
        $question = $first->messages()->create(['role' => 'user', 'content' => 'Question']);
        $this->actingAs($user)->withHeader('X-Tenant-Id', 'acme');
        foreach ([$wrongReply->id, $question->id, 999999] as $id) {
            $this->postJson('/conversations/'.$first->id.'/read', ['message_id' => $id])
                ->assertUnprocessable()->assertJsonValidationErrors('message_id');
        }
        $this->assertSame(0, $first->fresh()->last_read_message_id);
    }

    public function test_discovery_does_not_query_once_per_conversation(): void
    {
        $user = $this->user();
        foreach (range(1, 8) as $index) {
            $conversation = $this->conversation($user);
            $run = $this->agentRun($conversation);
            $conversation->messages()->create(['role' => 'assistant', 'content' => 'Answer', 'agent_run_id' => $run->id]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $rows = app(ConversationActivityService::class)->list($user->id, 'acme');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(8, $rows);
        $this->assertLessThanOrEqual(4, count($queries));
    }

    public function test_migration_marks_legacy_history_read_without_touching_activity_dates(): void
    {
        $migration = require __DIR__.'/../../../database/migrations/2026_10_21_150000_add_read_watermark_to_conversations_table.php';
        $user = $this->user();
        $conversation = $this->conversation($user);
        $reply = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Old answer']);
        $updatedAt = $conversation->updated_at->toIso8601String();
        $migration->down();
        $migration->up();
        $this->assertSame($reply->id, $conversation->fresh()->last_read_message_id);
        $this->assertSame($updatedAt, $conversation->fresh()->updated_at->toIso8601String());
    }
}
