<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\ChatLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T3.1 — schema-level invariants for the new grounding columns.
 *
 * What this test guards:
 *  1. Both columns are present on BOTH tables (`messages` and `chat_logs`).
 *  2. The columns are nullable (legacy rows pre-T3 must be untouched).
 *  3. `confidence` accepts the boundary values 0 and 100 plus a few in
 *     between — guards against a future regression that flips it to a
 *     signed type or shrinks it to bit/boolean.
 *  4. `refusal_reason` accepts the canonical tag set (`no_relevant_context`,
 *     `llm_self_refusal`) without truncation.
 *  5. The Message model's $fillable + $casts pick up the new columns
 *     (a fillable miss would silently drop the value on `Message::create()`).
 */
final class AddGroundingColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_messages_table_has_grounding_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('messages', 'confidence'));
        $this->assertTrue(Schema::hasColumn('messages', 'refusal_reason'));
    }

    public function test_chat_logs_table_has_grounding_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('chat_logs', 'confidence'));
        $this->assertTrue(Schema::hasColumn('chat_logs', 'refusal_reason'));
    }

    public function test_messages_grounding_columns_are_nullable(): void
    {
        $conversation = $this->makeConversation();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'A grounded answer.',
            'metadata' => ['provider' => 'openai'],
        ]);

        $this->assertNull($message->confidence);
        $this->assertNull($message->refusal_reason);
    }

    public function test_messages_confidence_round_trips_boundary_values(): void
    {
        $conversation = $this->makeConversation();

        foreach ([0, 1, 50, 99, 100] as $score) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => "score={$score}",
                'confidence' => $score,
            ]);

            $message->refresh();
            $this->assertSame($score, $message->confidence);
        }
    }

    public function test_messages_confidence_is_cast_to_integer(): void
    {
        $conversation = $this->makeConversation();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'A',
            'confidence' => 75,
        ]);

        // Cast must be 'integer' so JSON serialization emits a number,
        // not a string. The FE confidence-badge component depends on
        // numeric comparison (`confidence >= 80`) — if this regresses
        // to string it would silently always evaluate false.
        $message->refresh();
        $this->assertIsInt($message->confidence);
    }

    public function test_messages_refusal_reason_round_trips_canonical_tags(): void
    {
        $conversation = $this->makeConversation();

        foreach (['no_relevant_context', 'llm_self_refusal'] as $reason) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => 'refused',
                'refusal_reason' => $reason,
                'confidence' => 0,
            ]);

            $message->refresh();
            $this->assertSame($reason, $message->refusal_reason);
        }
    }

    public function test_chat_logs_grounding_columns_round_trip(): void
    {
        // chat_logs uses a raw insert pattern via ChatLogManager; verifying
        // the column shape via DB::table is sufficient — Eloquent fillable
        // is not the bottleneck on that table.
        $user = $this->makeUser();

        DB::table('chat_logs')->insert([
            'session_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'question' => 'Q?',
            'answer' => 'A.',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o-mini',
            'chunks_count' => 3,
            'latency_ms' => 1200,
            'confidence' => 87,
            'refusal_reason' => null,
            'created_at' => now(),
        ]);

        $row = DB::table('chat_logs')->first();

        $this->assertSame(87, (int) $row->confidence);
        $this->assertNull($row->refusal_reason);
    }

    private function makeConversation(): Conversation
    {
        return Conversation::create([
            'user_id' => $this->makeUser()->id,
            'title' => 'Test conversation',
        ]);
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'tester',
            'email' => 'tester-' . uniqid() . '@demo.local',
            'password' => Hash::make('secret123'),
        ]);
    }
}
