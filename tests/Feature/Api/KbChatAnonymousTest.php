<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Http\Controllers\Api\KbChatController;
use App\Models\ChatLog;
use App\Models\Conversation;
use App\Models\KbSearchFailure;
use App\Models\Message;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\SearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

/**
 * v8.8.3 — anonymous (authenticated, non-persisted) chat on /api/kb/chat.
 *
 * The contract (5 decisions, locked with Lorenzo):
 *  1. "Anonymous" = an authenticated user whose turn is NOT persisted as a
 *     conversation/message.
 *  2. chat_logs are written only minimally ("by-norm"), or not at all,
 *     governed by `chat-log.anonymous_level`.
 *  3. The content-gap rollup records the NORMALISED, REDACTED query.
 *  4. PII is force-redacted with a NON-PERSISTENT strategy (mask — no
 *     reversible token map) BEFORE retrieval / LLM / log / content-gap.
 *  5. The flag is rejected (422) when `kb.anonymous_chat.enabled` is off
 *     (R43 — toggling a flag can never silently change behaviour).
 *
 * Mirrors KbChatRefusalTest: mock KbSearchService for controlled chunks +
 * Mockery on AiManager. Chunks are ARRAY-shaped (R13/R16) — production never
 * emits `(object)` casts.
 */
final class KbChatAnonymousTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{system: string, user: string}|null */
    private ?array $capturedPrompt = null;

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/api/kb/chat', KbChatController::class)->name('api.kb.chat');

        config()->set('kb.refusal.min_chunk_similarity', 0.45);
        config()->set('kb.refusal.min_chunks_required', 1);

        // Anonymous chat ON by default for this suite; individual tests flip
        // it OFF to assert the R43 rejection.
        config()->set('kb.anonymous_chat.enabled', true);

        // PII redaction must be honoured by the forced mask.
        config()->set('pii-redactor.enabled', true);
    }

    protected function tearDown(): void
    {
        $this->capturedPrompt = null;
        parent::tearDown();
        Mockery::close();
    }

    /**
     * @param  array<int, array{score: float, doc_id?: int}>  $primarySpecs
     */
    private function mockSearchWithChunks(array $primarySpecs): void
    {
        $primary = collect($primarySpecs)->map(fn (array $spec, int $i) => [
            'chunk_id' => $i + 1,
            'knowledge_document_id' => $spec['doc_id'] ?? ($i + 1),
            'project_key' => 'test',
            'vector_score' => $spec['score'],
            'rerank_score' => $spec['score'] * 0.95,
            'heading_path' => 'Heading',
            'chunk_text' => 'lorem ipsum',
            'document' => [
                'id' => $spec['doc_id'] ?? ($i + 1),
                'title' => 'Doc ' . ($i + 1),
                'source_path' => 'docs/test-' . ($i + 1) . '.md',
            ],
        ]);

        $search = Mockery::mock(KbSearchService::class);
        $search->shouldReceive('searchWithContext')->andReturn(new SearchResult(
            primary: $primary,
            expanded: collect(),
            rejected: collect(),
            meta: [],
        ));
        $this->app->instance(KbSearchService::class, $search);
    }

    private function mockAiCapturingPrompt(string $content = 'Grounded answer'): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->andReturnUsing(function (string $system, string $user) use ($content) {
            $this->capturedPrompt = ['system' => $system, 'user' => $user];

            return new AiResponse(
                content: $content,
                provider: 'fake',
                model: 'fake-model',
                promptTokens: 11,
                completionTokens: 22,
                totalTokens: 33,
            );
        });
        $this->app->instance(AiManager::class, $ai);
    }

    private function mockAiThatMustNotBeCalled(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('chat');
        $this->app->instance(AiManager::class, $ai);
    }

    public function test_anonymous_flag_is_rejected_when_feature_disabled(): void
    {
        // R43 — OFF state must REJECT, not silently fall back to a persisted
        // turn. A flipped flag can never surprise an operator.
        config()->set('kb.anonymous_chat.enabled', false);
        $this->mockSearchWithChunks([['score' => 0.92]]);
        $this->mockAiThatMustNotBeCalled();

        $this->postJson('/api/kb/chat', [
            'question' => 'What is X?',
            'project_key' => 'test',
            'anonymous' => true,
        ])->assertStatus(422);
    }

    public function test_anonymous_turn_persists_no_conversation_or_message(): void
    {
        $this->mockSearchWithChunks([['score' => 0.92]]);
        $this->mockAiCapturingPrompt();

        $this->postJson('/api/kb/chat', [
            'question' => 'What is X?',
            'project_key' => 'test',
            'anonymous' => true,
        ])->assertOk()->assertJsonPath('answer', 'Grounded answer');

        $this->assertSame(0, Conversation::query()->forTenant('default')->count());
        $this->assertSame(0, Message::query()->forTenant('default')->count());
    }

    public function test_anonymous_turn_masks_pii_before_it_reaches_the_provider(): void
    {
        // Decision 4 — the email must be force-masked BEFORE the LLM sees it,
        // with the non-persistent mask strategy (default token [REDACTED]).
        $this->mockSearchWithChunks([['score' => 0.92]]);
        $this->mockAiCapturingPrompt();

        $this->postJson('/api/kb/chat', [
            'question' => 'Please email the report to john.doe@example.com today',
            'project_key' => 'test',
            'anonymous' => true,
        ])->assertOk();

        $this->assertNotNull($this->capturedPrompt);
        $haystack = $this->capturedPrompt['system'] . "\n" . $this->capturedPrompt['user'];
        $this->assertStringNotContainsString('john.doe@example.com', $haystack);
        $this->assertStringContainsString('[REDACTED]', $haystack);
    }

    public function test_anonymous_minimal_log_strips_pii_and_attribution(): void
    {
        // Decision 2 — "minimal": keep by-norm operational fields, drop
        // question/answer/sources/user_id/client_ip/user_agent.
        config()->set('chat-log.enabled', true);
        config()->set('chat-log.driver', 'database');
        config()->set('chat-log.anonymous_level', 'minimal');

        $this->mockSearchWithChunks([['score' => 0.92]]);
        $this->mockAiCapturingPrompt('A real answer body');

        $this->postJson('/api/kb/chat', [
            'question' => 'Reach me at jane@corp.example',
            'project_key' => 'test',
            'anonymous' => true,
        ], ['X-Session-Id' => 'client-stable-session-123'])->assertOk();

        $row = ChatLog::query()->forTenant('default')->sole();
        $this->assertSame('', $row->question);
        $this->assertSame('', $row->answer);
        $this->assertNull($row->user_id);
        $this->assertNull($row->client_ip);
        $this->assertNull($row->user_agent);
        $this->assertSame([], $row->sources);
        $this->assertSame('test', $row->project_key);
        $this->assertSame('fake', $row->ai_provider);
        $this->assertSame(33, $row->total_tokens);
        $this->assertTrue((bool) ($row->extra['anonymous'] ?? false));
        // Decision: a fresh per-request UUID, NEVER the client-supplied,
        // user-linkable X-Session-Id.
        $this->assertNotSame('client-stable-session-123', $row->session_id);
    }

    public function test_anonymous_level_none_writes_no_log_row(): void
    {
        config()->set('chat-log.enabled', true);
        config()->set('chat-log.driver', 'database');
        config()->set('chat-log.anonymous_level', 'none');

        $this->mockSearchWithChunks([['score' => 0.92]]);
        $this->mockAiCapturingPrompt();

        $this->postJson('/api/kb/chat', [
            'question' => 'What is X?',
            'project_key' => 'test',
            'anonymous' => true,
        ])->assertOk();

        $this->assertSame(0, ChatLog::query()->forTenant('default')->count());
    }

    public function test_anonymous_refusal_records_redacted_content_gap_without_llm(): void
    {
        // Decisions 3 + 4 + guard preservation: a refused anonymous turn
        // still records a content gap, and the recorded query is the
        // REDACTED form — never the raw PII. LLM is never called (R26).
        config()->set('kb.content_gaps.enabled', true);
        $this->mockSearchWithChunks([]);
        $this->mockAiThatMustNotBeCalled();

        $this->postJson('/api/kb/chat', [
            'question' => 'Why can I not reach secret@vault.example?',
            'project_key' => 'test',
            'anonymous' => true,
        ])->assertOk()->assertJsonPath('refusal_reason', 'no_relevant_context');

        $gap = KbSearchFailure::query()->forTenant('default')->sole();
        $this->assertStringNotContainsString('secret@vault.example', $gap->query_text);
        $this->assertStringContainsString('[REDACTED]', $gap->query_text);
    }

    public function test_anonymous_minimal_log_retains_nonpii_operational_extra(): void
    {
        // The minimal anonymous row keeps an ALLOWLISTED slice of `extra`
        // (refusal_reason + chunk counts) so refusal/retrieval dashboards still
        // work, while never persisting the question/answer/attribution.
        config()->set('chat-log.enabled', true);
        config()->set('chat-log.driver', 'database');
        config()->set('chat-log.anonymous_level', 'minimal');
        $this->mockSearchWithChunks([]);
        $this->mockAiThatMustNotBeCalled();

        $this->postJson('/api/kb/chat', [
            'question' => 'unknown thing',
            'project_key' => 'test',
            'anonymous' => true,
        ])->assertOk()->assertJsonPath('refusal_reason', 'no_relevant_context');

        $row = ChatLog::query()->forTenant('default')->sole();
        $this->assertSame('no_relevant_context', $row->extra['refusal_reason'] ?? null);
        $this->assertSame(0, $row->extra['primary_count'] ?? null);
        $this->assertTrue((bool) ($row->extra['anonymous'] ?? false));
        // Still no PII / attribution on the row.
        $this->assertSame('', $row->question);
        $this->assertNull($row->user_id);
    }

    public function test_non_anonymous_turn_is_unaffected(): void
    {
        // The flag defaults to false → existing callers behave exactly as
        // before: full persistence-less stateless turn, no forced mask, no
        // 422. (KbChatController has always been stateless; this asserts the
        // anonymous branch is inert when the flag is absent.)
        config()->set('chat-log.enabled', true);
        config()->set('chat-log.driver', 'database');
        $this->mockSearchWithChunks([['score' => 0.92]]);
        $this->mockAiCapturingPrompt();

        $this->postJson('/api/kb/chat', [
            'question' => 'Plain question, no flag',
            'project_key' => 'test',
        ])->assertOk()->assertJsonPath('answer', 'Grounded answer');

        $row = ChatLog::query()->forTenant('default')->sole();
        // Full logging — the question is preserved on a normal turn.
        $this->assertSame('Plain question, no flag', $row->question);
        $this->assertFalse((bool) ($row->extra['anonymous'] ?? false));
    }
}
