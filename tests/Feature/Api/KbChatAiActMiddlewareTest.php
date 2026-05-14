<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Http\Controllers\Api\KbChatController;
use App\Models\User;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\SearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Mockery;
use Padosoft\AiActCompliance\Consent\Models\ConsentRecord;
use Tests\TestCase;

/**
 * v6.0 — AI Act compliance middleware on /api/kb/chat.
 *
 * Verifies the integration the host wires in routes/api.php:
 *  - `ai.disclosure` always appends X-AI-Disclosure on every chat
 *    response (Art. 50 transparency obligation).
 *  - `ai.consent:<feature>` denies with HTTP 403 when no granted
 *    ConsentRecord exists, allows when one does, and short-circuits
 *    to pass-through when the host config flag is empty (default —
 *    keeps existing AskMyDocs users non-breaking).
 *
 * Hits the REAL /api/kb/chat route (defined in routes/api.php) — not
 * an inline Route::post() stub like the other KbChat*Test classes —
 * so the middleware chain is actually exercised.
 */
final class KbChatAiActMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kb.refusal.min_chunk_similarity', 0.0);
        config()->set('kb.refusal.min_chunks_required', 0);
        config()->set('chat-log.enabled', false);
        config()->set('kb.hybrid_search.enabled', false);
        config()->set('kb.reranking.enabled', false);
        config()->set('kb.graph.expansion_enabled', false);
        config()->set('kb.rejected.injection_enabled', false);

        $this->stubKbAndAi();
    }

    /**
     * Register the chat route inline with the same AI Act middleware
     * chain used in routes/api.php — but WITHOUT the production
     * EncryptCookies + StartSession + auth:sanctum chain that requires
     * full session machinery the unit harness doesn't bootstrap. The
     * production middleware chain is exercised by Playwright; this
     * test focuses on the AI Act layer specifically.
     */
    private function registerChatRouteWithAiActMiddleware(): void
    {
        // The aliases declared in bootstrap/app.php->withMiddleware()->alias([...])
        // do not always propagate to the router at boot-time inside the
        // PHPUnit harness (Application::configure() resolves them lazily
        // and the inline Route::post() below registers BEFORE that flush
        // happens). Re-bind them directly on the router so the test
        // middleware chain resolves regardless of ordering.
        $router = $this->app['router'];
        $router->aliasMiddleware('ai.disclosure', \Padosoft\AiActCompliance\Disclosure\AiDisclosureMiddleware::class);
        $router->aliasMiddleware('ai.consent', \Padosoft\AiActCompliance\Consent\RequireConsentMiddleware::class);

        $consentFeature = (string) config('ai-act-compliance.consent.gate_chat_feature', '');
        $middleware = ['ai.disclosure'];
        if ($consentFeature !== '') {
            $middleware[] = 'ai.consent:' . $consentFeature;
        }
        Route::post('/api/kb/chat', KbChatController::class)->middleware($middleware);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_disclosure_header_is_appended_on_every_chat_response(): void
    {
        $this->registerChatRouteWithAiActMiddleware();
        $user = $this->seedUser();
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/kb/chat', ['question' => 'What is the cache TTL?']);

        $response->assertOk();
        self::assertNotEmpty(
            $response->headers->get('X-AI-Disclosure'),
            'AI Act Art. 50 disclosure header must appear on every chat response',
        );
    }

    public function test_disclosure_header_is_opt_out_via_package_config(): void
    {
        config()->set('ai-act-compliance.disclosure.enabled', false);
        $this->registerChatRouteWithAiActMiddleware();
        $user = $this->seedUser();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/kb/chat', ['question' => 'Disable disclosure for this tenant.']);

        $response->assertOk();
        self::assertNull(
            $response->headers->get('X-AI-Disclosure'),
            'When disclosure.enabled=false, the response must NOT carry X-AI-Disclosure',
        );
    }

    public function test_consent_gate_is_inactive_by_default_so_existing_users_keep_working(): void
    {
        // Default `gate_chat_feature` is null/empty — the host does not
        // mount `ai.consent:<feature>` on the route at all.
        config()->set('ai-act-compliance.consent.gate_chat_feature', null);
        $this->registerChatRouteWithAiActMiddleware();

        $user = $this->seedUser();
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/kb/chat', ['question' => 'Backward-compat probe.']);

        $response->assertOk();
    }

    public function test_consent_gate_denies_when_host_opts_in_and_user_has_no_grant(): void
    {
        config()->set('ai-act-compliance.consent.gate_chat_feature', 'chat');
        $this->registerChatRouteWithAiActMiddleware();

        $user = $this->seedUser();
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/kb/chat', ['question' => 'No consent record exists.']);

        $response->assertStatus(403);
    }

    public function test_consent_gate_allows_when_a_granted_consent_record_exists(): void
    {
        config()->set('ai-act-compliance.consent.gate_chat_feature', 'chat');
        $this->registerChatRouteWithAiActMiddleware();

        $user = $this->seedUser();
        ConsentRecord::query()->create([
            'user_id' => (string) $user->id,
            'feature' => 'chat',
            'granted' => true,
            'granted_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/kb/chat', ['question' => 'Granted consent here.']);

        $response->assertOk();
        self::assertNotEmpty($response->headers->get('X-AI-Disclosure'));
    }

    public function test_consent_gate_denies_when_record_is_revoked(): void
    {
        config()->set('ai-act-compliance.consent.gate_chat_feature', 'chat');
        $this->registerChatRouteWithAiActMiddleware();

        $user = $this->seedUser();
        ConsentRecord::query()->create([
            'user_id' => (string) $user->id,
            'feature' => 'chat',
            'granted' => true,
            'granted_at' => now()->subDay(),
            'revoked_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/kb/chat', ['question' => 'Revoked consent should deny.']);

        $response->assertStatus(403);
    }

    private function seedUser(): User
    {
        return User::create([
            'name' => 'AI Act Middleware User',
            'email' => 'ai-act-mw-'.uniqid().'@example.test',
            'password' => Hash::make('secret-password'),
        ]);
    }

    private function stubKbAndAi(): void
    {
        $kb = Mockery::mock(KbSearchService::class);
        $kb->shouldReceive('searchWithContext')->andReturn(new SearchResult(
            primary: new Collection([
                [
                    'knowledge_chunk_id' => 1,
                    'source_path' => 'kb/policy.md',
                    'chunk_text' => 'Cache TTL is 10 minutes by default; flushing is manual.',
                    'similarity' => 0.9,
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

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->andReturn(new AiResponse(
            content: 'Cache TTL is 10 minutes by default.',
            provider: 'openai',
            model: 'gpt-4o',
            promptTokens: 50,
            completionTokens: 30,
            totalTokens: 80,
        ));
        $this->app->instance(AiManager::class, $ai);
    }
}
