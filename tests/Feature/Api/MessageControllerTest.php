<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\MessageController;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\SearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

/**
 * v8.1 P0.2 — the sync conversation endpoint (`POST /conversations/{id}/
 * messages`) now runs the SAME unified `searchWithContext()` retrieval as
 * /api/kb/chat (via ChatRetrievalService), so it produces the typed-block
 * prompt AND origin-aware citations (primary | related | rejected). These
 * tests pin that behaviour — the path had no end-to-end coverage before,
 * which is how the production-broken refusal gate (P0.1) slipped through.
 *
 * The chat call goes through McpToolCallingService (final, not mockable);
 * with no MCP tools configured for the user it falls back to
 * AiManager::chatWithHistory → Anthropic over Http, so we fake the
 * transport (same approach as MessageStreamControllerTest).
 */
final class MessageControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        // Register the route inline (mirrors MessageStreamControllerTest):
        // Testbench does NOT auto-load routes/web.php, so the production
        // route + its middleware stack (redact-chat-pii, ai.* gates, auth)
        // aren't present here. This is deliberate and sufficient — these
        // tests exercise the controller's unified-retrieval + refusal logic,
        // NOT the middleware stack (those are covered by the dedicated
        // middleware-scope tests). SubstituteBindings is added explicitly so
        // the `Conversation` route-model binding (+ its tenant scope) runs.
        Route::post(
            '/conversations/{conversation}/messages',
            [MessageController::class, 'store'],
        )->middleware(SubstituteBindings::class);

        config()->set('ai.default', 'anthropic');
        // SDK config shape (driver/key/url/models) — anthropic moved to the
        // laravel/ai SDK in v8.16/W2; the SDK reads `driver` + `key` + `url`.
        config()->set('ai.providers.anthropic', [
            'driver' => 'anthropic',
            'name' => 'anthropic',
            'key' => 'sk-ant-test',
            'url' => 'https://api.anthropic.com/v1',
            'api_version' => '2023-06-01',
            'temperature' => 0.2,
            'max_tokens' => 2048,
            'timeout' => 30,
            'models' => ['text' => ['default' => 'claude-sonnet-4-20250514']],
        ]);
        config()->set('kb.refusal.min_chunk_similarity', 0.45);
        config()->set('kb.refusal.min_rerank_score', 0.25);
        config()->set('kb.refusal.min_chunks_required', 1);
        config()->set('chat-log.enabled', false);

        // Pin the active tenant explicitly: Conversation::resolveRouteBinding()
        // scopes by TenantContext::current(), and the conversation below is
        // tenant-stamped from the same context on create. Setting it here
        // (rather than leaning on the global default) keeps route binding
        // deterministic and immune to cross-test tenant leakage.
        app(\App\Support\TenantContext::class)->set('default');

        $this->user = User::create([
            'name' => 'Sync',
            'email' => 'sync-' . uniqid() . '@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'project_key' => 'hr-portal',
            'title' => 'Test',
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    /** A production-shape chunk array (as KbSearchService::search() emits). */
    private function chunk(int $id, string $path, float $vector, float $rerank): array
    {
        return [
            'chunk_id' => $id,
            'project_key' => 'hr-portal',
            'heading_path' => 'Heading ' . $id,
            'chunk_text' => 'body ' . $id,
            'metadata' => [],
            'vector_score' => $vector,
            'rerank_score' => $rerank,
            'document' => [
                'id' => $id,
                'title' => 'Doc ' . $id,
                'source_path' => $path,
                'source_type' => 'markdown',
            ],
        ];
    }

    private function mockSearch(SearchResult $result): void
    {
        $search = Mockery::mock(KbSearchService::class);
        $search->shouldReceive('searchWithContext')->andReturn($result);
        $this->app->instance(KbSearchService::class, $search);
    }

    private function fakeAnthropic(string $content): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-20250514',
                'content' => [['type' => 'text', 'text' => $content]],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);
    }

    public function test_grounded_turn_returns_origin_aware_citations_from_all_buckets(): void
    {
        $this->mockSearch(new SearchResult(
            primary: collect([$this->chunk(1, 'hr/a.md', 0.85, 0.60)]),
            expanded: collect([$this->chunk(2, 'hr/b.md', 0.40, 0.30)]),
            rejected: collect([$this->chunk(3, 'hr/c.md', 0.50, 0.35)]),
        ));
        $this->fakeAnthropic('A grounded answer.');

        $resp = $this->actingAs($this->user)->postJson(
            '/conversations/' . $this->conversation->id . '/messages',
            ['content' => 'How does the stipend work?'],
        );

        $resp->assertOk()
            ->assertJsonPath('refusal_reason', null)
            ->assertJsonPath('content', 'A grounded answer.');

        // v8.16/W3 — server-side cost keys are always present in the message
        // metadata (R27 additive); null here since finops metering is off in tests.
        $this->assertArrayHasKey('cost', $resp->json('metadata'));
        $this->assertArrayHasKey('cost_currency', $resp->json('metadata'));
        // Both keys null when metering is off (the suite default) — neither alone.
        $this->assertNull($resp->json('metadata.cost'));
        $this->assertNull($resp->json('metadata.cost_currency'));

        $origins = collect($resp->json('metadata.citations'))->pluck('origin')->all();
        $this->assertContains('primary', $origins);
        $this->assertContains('related', $origins);
        $this->assertContains('rejected', $origins);
    }

    public function test_ungrounded_primary_refuses_without_calling_the_llm(): void
    {
        // Primary below BOTH floors → refuse. R26-style: prove no LLM call
        // leaves the app on the refusal path.
        $this->mockSearch(new SearchResult(
            primary: collect([$this->chunk(1, 'hr/a.md', 0.20, 0.10)]),
            expanded: collect(),
            rejected: collect(),
        ));
        Http::fake();

        $resp = $this->actingAs($this->user)->postJson(
            '/conversations/' . $this->conversation->id . '/messages',
            ['content' => 'Unanswerable?'],
        );

        $resp->assertOk()
            ->assertJsonPath('refusal_reason', 'no_relevant_context')
            ->assertJsonPath('confidence', 0);

        Http::assertNothingSent();
    }
}
