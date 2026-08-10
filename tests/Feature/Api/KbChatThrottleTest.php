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
use Tests\TestCase;

/**
 * Identity+tenant-aware rate limit on POST /kb/chat (SEC-THROTTLE-001, F-06).
 *
 * The endpoint drives a real AI provider turn; without a per-caller cap one
 * tenant user could exhaust provider quota for everyone. The `kb-chat`
 * RateLimiter keys by identity + tenant, so buckets are independent per user.
 */
class KbChatThrottleTest extends TestCase
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
        config()->set('kb.counterfactual.enabled', false);
        // Low cap so the test is fast + deterministic.
        config()->set('kb.chat.rate_limit_per_minute', 2);

        $this->stubKbAndAi();

        Route::post('/api/kb/chat', KbChatController::class)->middleware('throttle:kb-chat');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function user(string $tag): User
    {
        return User::create([
            'name' => $tag,
            'email' => $tag.'-'.uniqid().'@example.test',
            'password' => Hash::make('secret-password'),
        ]);
    }

    public function test_requests_over_the_per_minute_cap_are_throttled(): void
    {
        $user = $this->user('throttle-user');

        $this->actingAs($user, 'sanctum')->postJson('/api/kb/chat', ['question' => 'q1'])->assertOk();
        $this->actingAs($user, 'sanctum')->postJson('/api/kb/chat', ['question' => 'q2'])->assertOk();
        $this->actingAs($user, 'sanctum')->postJson('/api/kb/chat', ['question' => 'q3'])->assertStatus(429);
    }

    public function test_buckets_are_independent_per_identity(): void
    {
        $first = $this->user('user-a');
        $second = $this->user('user-b');

        // Exhaust the first user's bucket.
        $this->actingAs($first, 'sanctum')->postJson('/api/kb/chat', ['question' => 'q1'])->assertOk();
        $this->actingAs($first, 'sanctum')->postJson('/api/kb/chat', ['question' => 'q2'])->assertOk();
        $this->actingAs($first, 'sanctum')->postJson('/api/kb/chat', ['question' => 'q3'])->assertStatus(429);

        // A different identity is unaffected.
        $this->actingAs($second, 'sanctum')->postJson('/api/kb/chat', ['question' => 'q1'])->assertOk();
    }

    public function test_zero_config_still_enforces_a_floor_of_one_per_minute(): void
    {
        config()->set('kb.chat.rate_limit_per_minute', 0);
        $user = $this->user('floor-user');

        $this->actingAs($user, 'sanctum')->postJson('/api/kb/chat', ['question' => 'q1'])->assertOk();
        $this->actingAs($user, 'sanctum')->postJson('/api/kb/chat', ['question' => 'q2'])->assertStatus(429);
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
