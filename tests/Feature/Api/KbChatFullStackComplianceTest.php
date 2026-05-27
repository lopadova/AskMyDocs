<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\ChatLog;
use App\Models\User;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\SearchResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * v8.3 WS-B — the consolidated "tutto il giro completo" enterprise smoke.
 *
 * Every per-feature integration is already covered in isolation
 * (KbChatAiActMiddlewareTest, ChatLogAnswerRedactionTest, RedactChatPiiTest,
 * the KbChatResponseShape / Refusal suites). What was missing — and what
 * this test pins — is ONE real chat turn with ALL enterprise features ON
 * simultaneously, asserting they fire TOGETHER and don't interfere:
 *
 *   1. Retrieval + evidence citations           (grounded answer + sources)
 *   2. AI Act Art. 50 disclosure                 (X-AI-Disclosure header)
 *   3. Chat logging                              (chat_logs row persisted)
 *   4. PII answer-redaction                      (LLM-echoed email masked
 *                                                 in chat_logs.answer)
 *
 * It hits the REAL POST /api/kb/chat route from routes/api.php — so the
 * production middleware chain (auth:sanctum + tenant.authorize + ai.disclosure)
 * runs for real, not a stripped-down inline stub. The LLM is the only external
 * boundary that is faked (R13); the live LLM path + answer-faithfulness is
 * validated separately by `kb:benchmark --with-answers` and `eval:nightly`
 * (v8.3 WS-A / WS-C). Everything else here is the real controller + middleware
 * + observers + DB.
 */
final class KbChatFullStackComplianceTest extends TestCase
{
    use RefreshDatabase;

    private const LEAKED_EMAIL = 'ops-admin@acme.example';

    protected function defineEnvironment($app): void
    {
        // Mask strategy needs no salt and produces a stable redaction the
        // ChatLogObserver applies on `creating`.
        $app['config']->set('pii-redactor.strategy', 'mask');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // ── Every enterprise feature ON at once ──────────────────────────
        config()->set('chat-log.enabled', true);                  // persist the turn
        config()->set('kb.pii_redactor.enabled', true);           // PII master switch
        config()->set('kb.pii_redactor.redact_answers', true);    // mask LLM-echoed PII
        // disclosure.enabled defaults true → X-AI-Disclosure header always on.

        // Retrieval knobs: keep the stubbed single-chunk result deterministic.
        config()->set('kb.refusal.min_chunk_similarity', 0.0);
        config()->set('kb.refusal.min_chunks_required', 0);
        config()->set('kb.hybrid_search.enabled', false);
        config()->set('kb.reranking.enabled', false);
        config()->set('kb.graph.expansion_enabled', false);
        config()->set('kb.rejected.injection_enabled', false);

        app(TenantContext::class)->set('default');

        $this->stubKbAndAi();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_one_chat_turn_fires_citations_disclosure_chatlog_and_pii_redaction_together(): void
    {
        $user = User::create([
            'name' => 'Full-stack smoke user',
            'email' => 'fullstack-'.uniqid().'@example.test',
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/kb/chat', ['question' => 'What is the cache TTL and who do I contact?']);

        // 1 — grounded answer + evidence citations built from the REAL chunk
        // shape (source_path resolved from document.*, not null) — proving the
        // citation builder ran on representative data, not a degraded shape.
        $response->assertOk();
        $response->assertJsonStructure(['answer', 'citations']);
        self::assertNotEmpty($response->json('answer'), 'a grounded answer is returned');
        self::assertNotEmpty($response->json('citations'), 'evidence citations are returned');
        self::assertSame(
            'policies/remote-work-policy.md',
            $response->json('citations.0.source_path'),
            'the citation resolves source_path from the nested document.* shape',
        );

        // 2 — AI Act Art. 50 disclosure header on the live response.
        self::assertNotEmpty(
            $response->headers->get('X-AI-Disclosure'),
            'AI Act disclosure header must be present',
        );

        // 3 — the turn was persisted to chat_logs (logging enabled). Scope the
        // read to the active tenant (R30) — ChatLog is tenant-aware and has no
        // global read scope, so an unscoped latest() could pick up a row from
        // another tenant if fixtures ever share the connection.
        $log = ChatLog::query()->forTenant('default')->latest('id')->first();
        self::assertNotNull($log, 'the chat turn must be logged to chat_logs');

        // 4 — PII the LLM echoed into the answer is masked in the persisted
        // row (the ChatLogObserver ran with both redaction knobs ON).
        self::assertStringNotContainsString(
            self::LEAKED_EMAIL,
            (string) $log->answer,
            'an email echoed by the LLM must be redacted out of the persisted answer',
        );
        self::assertDoesNotMatchRegularExpression(
            '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i',
            (string) $log->answer,
            'no raw email pattern may survive in chat_logs.answer',
        );
    }

    private function stubKbAndAi(): void
    {
        $kb = Mockery::mock(KbSearchService::class);
        $kb->shouldReceive('searchWithContext')->andReturn(new SearchResult(
            // Production chunk shape (v8.1): flat chunk_id + rerank_score/
            // vector_score + nested document.* — NOT the legacy
            // knowledge_chunk_id / top-level source_path / similarity. Using
            // the real shape so the citation builder + grounding gate run on
            // representative data and this smoke actually catches the
            // array-shape regressions v8.1 P0.1 fixed.
            primary: new Collection([
                [
                    'chunk_id' => 1,
                    'chunk_text' => 'Cache TTL is 10 minutes by default; flushing is manual.',
                    'chunk_hash' => str_repeat('a', 64),
                    'heading_path' => 'Caching',
                    'rerank_score' => 0.92,
                    'vector_score' => 0.88,
                    'document' => [
                        'id' => 101,
                        'title' => 'Remote Work Policy',
                        'source_path' => 'policies/remote-work-policy.md',
                        'source_type' => 'md',
                    ],
                ],
            ]),
            expanded: new Collection(),
            rejected: new Collection(),
            meta: [
                'search_strategy' => [
                    'hybrid_search_enabled' => false,
                    'reranking_enabled' => false,
                    'graph_expansion_enabled' => false,
                    'rejected_injection_enabled' => false,
                    'fusion_method' => 'vector_only',
                ],
                'retrieval_stats' => [
                    'primary_count' => 1,
                    'expanded_count' => 0,
                    'rejected_count' => 0,
                    'min_similarity' => 0.9,
                    'max_similarity' => 0.9,
                ],
            ],
        ));
        $this->app->instance(KbSearchService::class, $kb);

        // The LLM answer deliberately echoes a corpus-leaked email so the
        // PII answer-redaction path has something real to mask.
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->andReturn(new AiResponse(
            content: 'Cache TTL is 10 minutes by default. Contact '.self::LEAKED_EMAIL.' for a manual flush.',
            provider: 'openai',
            model: 'gpt-4o',
            promptTokens: 50,
            completionTokens: 30,
            totalTokens: 80,
        ));
        $this->app->instance(AiManager::class, $ai);
    }
}
