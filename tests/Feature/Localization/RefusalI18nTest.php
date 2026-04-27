<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Http\Controllers\Api\KbChatController;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\SearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

/**
 * T3.8-BE — exercises the per-reason i18n hierarchy + locale switching
 * on the refusal payload.
 *
 * Hierarchy under test (KbChatController::localizedRefusalMessage):
 *   1. `kb.refusal.{reason}` — per-reason copy (preferred)
 *   2. `kb.no_grounded_answer` — generic fallback when key missing
 *
 * Locale switching: `App::setLocale('it')` before the request triggers
 * the Italian copy from `lang/it/kb.php`. Default ('en') uses
 * `lang/en/kb.php`. The Accept-Language header doesn't auto-switch
 * locale in Laravel without a middleware — this test exercises the
 * underlying mechanism (setLocale) directly so the lang files
 * themselves are validated. The actual middleware wiring is
 * orthogonal and would be its own test.
 */
final class RefusalI18nTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/api/kb/chat', KbChatController::class)->name('api.kb.chat');

        config()->set('kb.refusal.min_chunk_similarity', 0.45);
        config()->set('kb.refusal.min_chunks_required', 1);
        config()->set('chat-log.enabled', false);
    }

    protected function tearDown(): void
    {
        // Reset to default to avoid test ordering side-effects.
        App::setLocale(config('app.locale', 'en'));
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Trigger the no_relevant_context refusal path: zero primary chunks
     * → controller short-circuits to refusal BEFORE the LLM.
     */
    private function setupNoRelevantContextRefusal(): void
    {
        $search = Mockery::mock(KbSearchService::class);
        $search->shouldReceive('searchWithContext')->andReturn(
            new SearchResult(
                primary: collect(),
                expanded: collect(),
                rejected: collect(),
                meta: ['filters_selected' => 0],
            )
        );
        $this->app->instance(KbSearchService::class, $search);

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('chat');
        $this->app->instance(AiManager::class, $ai);
    }

    /**
     * Trigger the llm_self_refusal path: high-sim chunks pass retrieval,
     * LLM emits the sentinel.
     */
    private function setupLlmSelfRefusal(): void
    {
        $primary = collect([
            (object) [
                'id' => 1,
                'knowledge_document_id' => 1,
                'vector_score' => 0.92,
                'heading_path' => 'H',
                'chunk_text' => 'lorem',
                'document' => (object) ['id' => 1, 'title' => 'D', 'source_path' => 'docs/d.md'],
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

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->andReturn(new AiResponse(
            content: '__NO_GROUNDED_ANSWER__',
            provider: 'openai',
            model: 'gpt-4o-mini',
            promptTokens: 10,
            completionTokens: 5,
            totalTokens: 15,
        ));
        $this->app->instance(AiManager::class, $ai);
    }

    public function test_no_relevant_context_uses_per_reason_english_copy(): void
    {
        $this->setupNoRelevantContextRefusal();

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()
            ->assertJsonPath('refusal_reason', 'no_relevant_context')
            ->assertJsonPath(
                'answer',
                'No documents in the knowledge base match this question.',
            );
    }

    public function test_llm_self_refusal_uses_per_reason_english_copy(): void
    {
        $this->setupLlmSelfRefusal();

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()
            ->assertJsonPath('refusal_reason', 'llm_self_refusal')
            ->assertJsonPath(
                'answer',
                'The AI cannot answer this question based on the provided documents.',
            );
    }

    public function test_no_relevant_context_returns_italian_copy_under_it_locale(): void
    {
        App::setLocale('it');
        $this->setupNoRelevantContextRefusal();

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()->assertJsonPath(
            'answer',
            'Nessun documento nella knowledge base corrisponde a questa domanda.',
        );
    }

    public function test_llm_self_refusal_returns_italian_copy_under_it_locale(): void
    {
        App::setLocale('it');
        $this->setupLlmSelfRefusal();

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()->assertJsonPath(
            'answer',
            "L'AI non può rispondere a questa domanda basandosi sui documenti forniti.",
        );
    }

    public function test_localized_refusal_message_helper_falls_back_to_generic(): void
    {
        // Direct unit-style assertion on the fallback hierarchy via
        // reflection — the controller's localizedRefusalMessage()
        // helper is private, but its CONTRACT (returns the generic copy
        // when the per-reason key is missing) is the load-bearing
        // invariant of T3.8-BE's forward-compat hatch. Reflection
        // is acceptable here because the alternative — exposing the
        // helper publicly — would be a worse design (it's not part
        // of the controller's external API).
        $controller = $this->app->make(KbChatController::class);
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('localizedRefusalMessage');
        $method->setAccessible(true);

        // Known reason → per-reason copy.
        $knownEn = $method->invoke($controller, 'no_relevant_context');
        $this->assertSame(
            'No documents in the knowledge base match this question.',
            $knownEn,
        );

        // Unknown reason → fallback to generic.
        $unknownEn = $method->invoke($controller, 'totally_made_up_reason');
        $this->assertSame(
            'I cannot find information in the provided documents to answer this question.',
            $unknownEn,
        );

        // Italian: same fallback hierarchy.
        App::setLocale('it');
        $unknownIt = $method->invoke($controller, 'totally_made_up_reason');
        $this->assertSame(
            'Non riesco a trovare informazioni nei documenti per rispondere a questa domanda.',
            $unknownIt,
        );
    }

    public function test_refusal_meta_shape_unaffected_by_locale_switch(): void
    {
        // Locale only affects the user-visible answer body. All other
        // response fields (refusal_reason tag, confidence, citations,
        // meta.*) must stay exactly the same — the dashboard rolls
        // them up across locales.
        App::setLocale('it');
        $this->setupNoRelevantContextRefusal();

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()
            ->assertJsonPath('refusal_reason', 'no_relevant_context')  // tag in English regardless
            ->assertJsonPath('confidence', 0)
            ->assertJsonPath('citations', [])
            ->assertJsonPath('meta.refused_early', true);
    }
}
