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
 * T3.3 — deterministic refusal short-circuit on /api/kb/chat.
 *
 * Pattern mirrors KbChatControllerFiltersTest (T2.2): mock KbSearchService
 * to feed a controlled `primary` collection (with explicit `vector_score`
 * values), and use Mockery's `shouldNotReceive('chat')` on AiManager to
 * prove the LLM is NEVER called on the refusal path.
 *
 * The search service is mocked instead of the AiManager so the controller
 * runs through the real refusal-detection logic against real chunk shapes.
 */
final class KbChatRefusalTest extends TestCase
{
    use RefreshDatabase;

    /** @var \App\Services\Kb\Retrieval\RetrievalFilters|null */
    private $capturedFilters = null;

    /** @var ?Mockery\MockInterface */
    private ?Mockery\MockInterface $aiMock = null;

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/api/kb/chat', KbChatController::class)->name('api.kb.chat');

        // Default kb.refusal config so we don't depend on env state.
        config()->set('kb.refusal.min_chunk_similarity', 0.45);
        config()->set('kb.refusal.min_chunks_required', 1);

        // ChatLogManager is final → can't be Mockery-mocked. Disable via
        // config so the real instance's log() exits early.
        config()->set('chat-log.enabled', false);
    }

    protected function tearDown(): void
    {
        $this->capturedFilters = null;
        $this->aiMock = null;
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Wire a stubbed KbSearchService that returns a SearchResult with the
     * supplied primary chunks. Each chunk must expose `vector_score` and
     * `document` for citation building.
     *
     * @param  array<int, array{score: float, doc_id?: int}>  $primarySpecs
     */
    private function mockSearchWithChunks(array $primarySpecs): void
    {
        $primary = collect($primarySpecs)->map(function (array $spec, int $i) {
            return (object) [
                'id' => $i + 1,
                'knowledge_document_id' => $spec['doc_id'] ?? ($i + 1),
                'vector_score' => $spec['score'],
                'heading_path' => 'Heading',
                'chunk_text' => 'lorem ipsum',
                'document' => (object) [
                    'id' => $spec['doc_id'] ?? ($i + 1),
                    'title' => 'Doc ' . ($i + 1),
                    'source_path' => 'docs/test-' . ($i + 1) . '.md',
                ],
            ];
        });

        $search = Mockery::mock(KbSearchService::class);
        $search->shouldReceive('searchWithContext')->andReturnUsing(
            function (...$args) use ($primary) {
                $this->capturedFilters = $args[4] ?? null;

                return new SearchResult(
                    primary: $primary,
                    expanded: collect(),
                    rejected: collect(),
                    meta: ['filters_selected' => $this->capturedFilters?->isEmpty() ? 0 : 1],
                );
            }
        );
        $this->app->instance(KbSearchService::class, $search);
    }

    /**
     * Wire AiManager that MUST NOT receive `chat()`. Test fails if it does.
     */
    private function mockAiThatMustNotBeCalled(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('chat');
        $this->aiMock = $ai;
        $this->app->instance(AiManager::class, $ai);
    }

    /**
     * Wire AiManager that returns a fake AiResponse — for tests that
     * exercise the happy path and confirm the LLM IS reached.
     */
    private function mockAiThatReturnsAnswer(string $content = 'A grounded answer'): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->andReturn(new AiResponse(
            content: $content,
            provider: 'fake',
            model: 'fake-model',
            promptTokens: 10,
            completionTokens: 20,
            totalTokens: 30,
        ));
        $this->aiMock = $ai;
        $this->app->instance(AiManager::class, $ai);
    }

    public function test_zero_primary_chunks_refuses_without_calling_llm(): void
    {
        $this->mockSearchWithChunks([]);
        $this->mockAiThatMustNotBeCalled();

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Anything?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()
            ->assertJsonPath('refusal_reason', 'no_relevant_context')
            ->assertJsonPath('confidence', 0)
            ->assertJsonPath('citations', [])
            ->assertJsonPath('meta.refused_early', true);
    }

    public function test_chunks_below_threshold_refuse_without_calling_llm(): void
    {
        // Threshold is 0.45 (default) — these are all below.
        $this->mockSearchWithChunks([
            ['score' => 0.40],
            ['score' => 0.35],
            ['score' => 0.30],
        ]);
        $this->mockAiThatMustNotBeCalled();

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()
            ->assertJsonPath('refusal_reason', 'no_relevant_context')
            ->assertJsonPath('confidence', 0);
    }

    public function test_at_least_one_chunk_at_threshold_does_not_refuse(): void
    {
        // Exactly at the threshold (0.45) — counts as grounded.
        $this->mockSearchWithChunks([
            ['score' => 0.45],
            ['score' => 0.30],  // below — but the >=1 grounded chunk is enough
        ]);
        $this->mockAiThatReturnsAnswer('Grounded answer');

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()
            ->assertJsonPath('refusal_reason', null)
            ->assertJsonPath('answer', 'Grounded answer');
    }

    public function test_high_quality_retrieval_does_not_refuse(): void
    {
        $this->mockSearchWithChunks([
            ['score' => 0.92, 'doc_id' => 1],
            ['score' => 0.88, 'doc_id' => 2],
            ['score' => 0.85, 'doc_id' => 3],
        ]);
        $this->mockAiThatReturnsAnswer('Real grounded answer with citations');

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'What is X?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()
            ->assertJsonPath('refusal_reason', null)
            ->assertJsonPath('answer', 'Real grounded answer with citations');
    }

    public function test_min_chunks_required_two_refuses_with_one_grounded(): void
    {
        // Bump the count requirement — even ONE strong chunk should refuse
        // when the policy says you need TWO.
        config()->set('kb.refusal.min_chunks_required', 2);

        $this->mockSearchWithChunks([
            ['score' => 0.95],
            ['score' => 0.30],
        ]);
        $this->mockAiThatMustNotBeCalled();

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()
            ->assertJsonPath('refusal_reason', 'no_relevant_context');
    }

    public function test_refusal_response_includes_localized_answer(): void
    {
        // The refusal answer body comes from lang/en/kb.php (no i18n
        // override here). It must be a non-empty human-readable string,
        // NOT the raw key.
        $this->mockSearchWithChunks([]);
        $this->mockAiThatMustNotBeCalled();

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $answer = $resp->json('answer');
        $this->assertIsString($answer);
        $this->assertNotSame('kb.no_grounded_answer', $answer);  // not the raw key
        $this->assertNotEmpty($answer);
    }

    public function test_refusal_meta_carries_refused_early_flag_and_zero_chunks_used(): void
    {
        $this->mockSearchWithChunks([]);
        $this->mockAiThatMustNotBeCalled();

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()
            ->assertJsonPath('meta.refused_early', true)
            ->assertJsonPath('meta.chunks_used', 0)
            ->assertJsonPath('meta.primary_count', 0)
            ->assertJsonPath('meta.provider', null)
            ->assertJsonPath('meta.model', null);
    }

    public function test_threshold_is_configurable_via_kb_refusal_config(): void
    {
        // Tighten threshold to 0.80 — chunks at 0.70 now refuse.
        config()->set('kb.refusal.min_chunk_similarity', 0.80);

        $this->mockSearchWithChunks([
            ['score' => 0.70],
            ['score' => 0.60],
        ]);
        $this->mockAiThatMustNotBeCalled();

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()
            ->assertJsonPath('refusal_reason', 'no_relevant_context');
    }

    public function test_relaxed_threshold_lets_low_score_chunks_ground_the_answer(): void
    {
        // Loosen threshold to 0.20 — chunks at 0.30 now qualify.
        config()->set('kb.refusal.min_chunk_similarity', 0.20);

        $this->mockSearchWithChunks([
            ['score' => 0.30],
            ['score' => 0.25],
        ]);
        $this->mockAiThatReturnsAnswer('Loose-grounded answer');

        $resp = $this->postJson('/api/kb/chat', [
            'question' => 'Q?',
            'project_key' => 'test',
        ]);

        $resp->assertOk()
            ->assertJsonPath('refusal_reason', null)
            ->assertJsonPath('answer', 'Loose-grounded answer');
    }
}
