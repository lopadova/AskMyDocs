<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\ConversationRecapService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * ConversationRecapService: the incremental update logic behind a
 * conversation's rolling "session recap" (kb.session_recap.*).
 *
 * Pins the three correctness properties that matter here:
 *   1. The LLM call only ever sees the PREVIOUS recap + the last
 *      `window_messages` messages — never the full history.
 *   2. Cadence gating (`update_every_n_messages`) skips the LLM call
 *      entirely when it isn't this turn's turn.
 *   3. A malformed LLM reply leaves the previous recap untouched (never
 *      corrupted, never thrown into the caller) — same contract as
 *      KbChangeAnalyzer's decodeLlmJson().
 */
final class ConversationRecapServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        config()->set('kb.session_recap.enabled', true);
        config()->set('kb.session_recap.update_every_n_messages', 1);
        config()->set('kb.session_recap.window_messages', 2);
    }

    private function makeConversationWithMessages(int $count): Conversation
    {
        $user = User::create([
            'name' => 'Recap Tester',
            'email' => 'recap-'.uniqid().'@example.test',
            'password' => Hash::make('secret'),
        ]);

        $conversation = Conversation::create([
            'user_id' => $user->id,
            'project_key' => 'proj-recap',
        ]);

        for ($i = 1; $i <= $count; $i++) {
            $conversation->messages()->create([
                'role' => $i % 2 === 1 ? 'user' : 'assistant',
                'content' => "message #{$i}",
            ]);
        }

        return $conversation->fresh();
    }

    private function serviceWithAi(?AiManager $ai = null): ConversationRecapService
    {
        return new ConversationRecapService($ai ?? Mockery::mock(AiManager::class));
    }

    public function test_updates_recap_from_only_the_last_window_messages(): void
    {
        $conversation = $this->makeConversationWithMessages(6); // messages #1..#6, window=2

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')
            ->once()
            ->with(Mockery::on(function (string $systemPrompt): bool {
                // Only the last 2 messages (#5, #6) should reach the prompt.
                return str_contains($systemPrompt, 'message #5')
                    && str_contains($systemPrompt, 'message #6')
                    && ! str_contains($systemPrompt, 'message #1')
                    && ! str_contains($systemPrompt, 'message #4');
            }), 'Produce the JSON now.')
            ->andReturn(new AiResponse(
                content: json_encode([
                    'summary' => 'User asked about X, assistant answered.',
                    'topics' => ['X', 'X'], // duplicate on purpose
                    'open_questions' => ['still unclear about Y'],
                ]),
                provider: 'test',
                model: 'test-model',
            ));

        $this->serviceWithAi($ai)->updateAfterTurn($conversation);

        $recap = $conversation->fresh()->session_recap;
        $this->assertSame('User asked about X, assistant answered.', $recap['summary']);
        $this->assertSame(['X', 'X'], $recap['topics']); // service doesn't dedupe, just bounds/trims
        $this->assertSame(['still unclear about Y'], $recap['open_questions']);
        $this->assertArrayHasKey('updated_at', $recap);
    }

    public function test_disabled_feature_never_calls_the_llm(): void
    {
        config()->set('kb.session_recap.enabled', false);
        $conversation = $this->makeConversationWithMessages(2);

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('chat');

        $this->serviceWithAi($ai)->updateAfterTurn($conversation);

        $this->assertNull($conversation->fresh()->session_recap);
    }

    public function test_cadence_gate_skips_turns_that_are_not_due(): void
    {
        config()->set('kb.session_recap.update_every_n_messages', 2);
        // 3 messages -> 3 % 2 !== 0 -> not due.
        $conversation = $this->makeConversationWithMessages(3);

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('chat');

        $this->serviceWithAi($ai)->updateAfterTurn($conversation);

        $this->assertNull($conversation->fresh()->session_recap);
    }

    public function test_cadence_gate_runs_on_due_turns(): void
    {
        config()->set('kb.session_recap.update_every_n_messages', 2);
        // 4 messages -> 4 % 2 === 0 -> due.
        $conversation = $this->makeConversationWithMessages(4);

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->once()->andReturn(new AiResponse(
            content: json_encode(['summary' => 'ok', 'topics' => [], 'open_questions' => []]),
            provider: 'test',
            model: 'test-model',
        ));

        $this->serviceWithAi($ai)->updateAfterTurn($conversation);

        $this->assertSame('ok', $conversation->fresh()->session_recap['summary']);
    }

    public function test_malformed_llm_reply_keeps_the_previous_recap_untouched(): void
    {
        $conversation = $this->makeConversationWithMessages(2);
        $conversation->session_recap = ['summary' => 'previous gist', 'topics' => [], 'open_questions' => [], 'updated_at' => 'earlier'];
        $conversation->save();

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->once()->andReturn(new AiResponse(
            content: 'sorry, I cannot comply with that request', // not JSON
            provider: 'test',
            model: 'test-model',
        ));

        // Must not throw.
        $this->serviceWithAi($ai)->updateAfterTurn($conversation);

        $recap = $conversation->fresh()->session_recap;
        $this->assertSame('previous gist', $recap['summary']);
        $this->assertSame('earlier', $recap['updated_at']);
    }

    public function test_strips_markdown_fences_around_the_json_reply(): void
    {
        $conversation = $this->makeConversationWithMessages(2);

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->once()->andReturn(new AiResponse(
            content: "```json\n".json_encode(['summary' => 'fenced', 'topics' => [], 'open_questions' => []])."\n```",
            provider: 'test',
            model: 'test-model',
        ));

        $this->serviceWithAi($ai)->updateAfterTurn($conversation);

        $this->assertSame('fenced', $conversation->fresh()->session_recap['summary']);
    }

    public function test_no_messages_yet_never_calls_the_llm(): void
    {
        $conversation = $this->makeConversationWithMessages(0);

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('chat');

        $this->serviceWithAi($ai)->updateAfterTurn($conversation);

        $this->assertNull($conversation->fresh()->session_recap);
    }
}
