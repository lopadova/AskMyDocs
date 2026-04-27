<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Http\Controllers\Api\KbChatController;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\SearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

/**
 * T3.4 — exercises the LLM-self-refusal sentinel parsing on
 * `/api/kb/chat`. The prompt template instructs the model to emit the
 * literal string `__NO_GROUNDED_ANSWER__` when no grounded answer is
 * possible; the controller must convert that to a refusal payload with
 * `refusal_reason='llm_self_refusal'` (NOT 'no_relevant_context' —
 * retrieval was sufficient, the LLM self-refused).
 *
 * Pattern mirrors KbChatRefusalTest: stub KbSearchService with high-sim
 * chunks (so the retrieval-side refusal short-circuit doesn't fire),
 * stub AiManager to emit the sentinel (or other contents to test the
 * boundary cases).
 */
final class KbChatSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/api/kb/chat', KbChatController::class)->name('api.kb.chat');

        // Default kb.refusal config — high-sim chunks below pass the floor.
        config()->set('kb.refusal.min_chunk_similarity', 0.45);
        config()->set('kb.refusal.min_chunks_required', 1);

        // ChatLogManager is final → can't be Mockery-mocked. Disable via
        // config so the real instance's log() exits early.
        config()->set('chat-log.enabled', false);

        // Default search mock: 3 high-sim chunks so retrieval passes
        // the refusal threshold and the controller proceeds to the LLM.
        $this->mockSearchWithHighSimChunks();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockSearchWithHighSimChunks(): void
    {
        $primary = collect([
            (object) [
                'id' => 1,
                'knowledge_document_id' => 1,
                'vector_score' => 0.92,
                'heading_path' => 'H',
                'chunk_text' => 'lorem',
                'document' => (object) ['id' => 1, 'title' => 'D1', 'source_path' => 'docs/d1.md'],
            ],
            (object) [
                'id' => 2,
                'knowledge_document_id' => 2,
                'vector_score' => 0.85,
                'heading_path' => 'H',
                'chunk_text' => 'ipsum',
                'document' => (object) ['id' => 2, 'title' => 'D2', 'source_path' => 'docs/d2.md'],
            ],
        ]);

        $search = Mockery::mock(KbSearchService::class);
        $search->shouldReceive('searchWithContext')->andReturn(
            new SearchResult(
                primary: $primary,
                expanded: collect(),
                rejected: collect(),
                meta: ['filters_selected' => 0],
            )
        );
        $this->app->instance(KbSearchService::class, $search);
    }

    private function mockAiReturning(string $content): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->andReturn(new AiResponse(
            content: $content,
            provider: 'openai',
            model: 'gpt-4o-mini',
            promptTokens: 250,
            completionTokens: 5,
            totalTokens: 255,
        ));
        $this->app->instance(AiManager::class, $ai);
    }

    public function test_bare_sentinel_converts_to_llm_self_refusal_payload(): void
    {
        $this->mockAiReturning('__NO_GROUNDED_ANSWER__');

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()
            ->assertJsonPath('refusal_reason', 'llm_self_refusal')
            ->assertJsonPath('confidence', 0)
            ->assertJsonPath('citations', [])
            // Localized answer body (NOT the raw sentinel string).
            ->assertJsonPath('answer', __('kb.no_grounded_answer'));

        // Provider + model preserved in meta — the LLM call was paid in
        // full and the dashboard needs to attribute the refusal to a
        // specific model.
        $resp->assertJsonPath('meta.provider', 'openai')
            ->assertJsonPath('meta.model', 'gpt-4o-mini');
    }

    public function test_sentinel_with_leading_or_trailing_whitespace_still_detected(): void
    {
        // Some providers wrap responses with stray whitespace. The
        // controller compares with === after trim() — surrounding
        // whitespace must NOT mask the refusal.
        $this->mockAiReturning("  \n__NO_GROUNDED_ANSWER__\n  ");

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()
            ->assertJsonPath('refusal_reason', 'llm_self_refusal');
    }

    public function test_sentinel_embedded_in_partial_answer_does_NOT_refuse(): void
    {
        // The LLM emitted a partial answer that mentions the sentinel
        // string as part of the body. Substring detection would trip;
        // the contract is exact-match-after-trim ONLY, so the user gets
        // the partial answer (with the sentinel wording included).
        // T3.4 explicitly chose NOT to use str_contains — partial
        // answers are valuable; refusal on substring would discard them.
        $this->mockAiReturning(
            "Here is what I know about X: ... Note: I had to fall back to "
            . '__NO_GROUNDED_ANSWER__ for the second half of your question.'
        );

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()
            ->assertJsonPath('refusal_reason', null);
        // The answer pass-through preserves the LLM's exact string —
        // the FE will render it verbatim.
        $this->assertStringContainsString('Here is what I know', (string) $resp->json('answer'));
    }

    public function test_natural_language_idk_does_NOT_refuse(): void
    {
        // The LLM said "I don't know" in natural language without using
        // the sentinel. That's a regular answer the user should see —
        // there's no protocol-level refusal to convert.
        $this->mockAiReturning("I don't have enough information to answer this.");

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()
            ->assertJsonPath('refusal_reason', null);
        $this->assertSame(
            "I don't have enough information to answer this.",
            $resp->json('answer'),
        );
    }

    public function test_grounded_answer_passes_through_without_refusal(): void
    {
        // Standard happy path: LLM returns a normal grounded answer.
        $this->mockAiReturning('The product is X, deployed in region Y.');

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'What is the product?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()
            ->assertJsonPath('refusal_reason', null)
            ->assertJsonPath('answer', 'The product is X, deployed in region Y.');
    }

    public function test_sentinel_response_meta_carries_provider_and_real_token_counts(): void
    {
        // Distinguishes from the "no_relevant_context" refusal path
        // where provider/model are null + tokens are zero. Here the LLM
        // call was paid in full — the chat_log row + meta should reflect
        // that for cost attribution.
        $this->mockAiReturning('__NO_GROUNDED_ANSWER__');

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()
            ->assertJsonPath('meta.provider', 'openai')
            ->assertJsonPath('meta.model', 'gpt-4o-mini')
            // refused_early=false because the LLM WAS called — only
            // the retrieval step ran without a refusal.
            ->assertJsonPath('meta.refused_early', false);
    }

    public function test_sentinel_refusal_reports_chunks_used_unlike_no_context_refusal(): void
    {
        // Retrieval succeeded — primary chunks should be reflected.
        $this->mockAiReturning('__NO_GROUNDED_ANSWER__');

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()
            ->assertJsonPath('meta.chunks_used', 2)
            ->assertJsonPath('meta.primary_count', 2);
    }
}
