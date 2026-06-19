<?php

namespace Tests\Feature\ChatLog;

use App\FinOps\ChatTurnCost;
use App\FinOps\ChatTurnCostResolver;
use App\Models\ChatLog;
use App\Services\ChatLog\ChatLogEntry;
use App\Services\ChatLog\ChatLogManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class ChatLogManagerTest extends TestCase
{
    use RefreshDatabase;

    private function entry(array $overrides = []): ChatLogEntry
    {
        return new ChatLogEntry(
            sessionId: $overrides['sessionId'] ?? '00000000-0000-4000-8000-000000000000',
            userId: $overrides['userId'] ?? null,
            question: $overrides['question'] ?? 'q',
            answer: $overrides['answer'] ?? 'a',
            projectKey: $overrides['projectKey'] ?? null,
            aiProvider: $overrides['aiProvider'] ?? 'openai',
            aiModel: $overrides['aiModel'] ?? 'gpt-4o',
            chunksCount: $overrides['chunksCount'] ?? 0,
            sources: $overrides['sources'] ?? [],
            promptTokens: $overrides['promptTokens'] ?? null,
            completionTokens: $overrides['completionTokens'] ?? null,
            totalTokens: $overrides['totalTokens'] ?? null,
            latencyMs: $overrides['latencyMs'] ?? 100,
            clientIp: $overrides['clientIp'] ?? null,
            userAgent: $overrides['userAgent'] ?? null,
            extra: $overrides['extra'] ?? [],
            anonymous: $overrides['anonymous'] ?? false,
            traceId: $overrides['traceId'] ?? null,
        );
    }

    public function test_enabled_reflects_config(): void
    {
        config()->set('chat-log.enabled', true);
        $this->assertTrue((new ChatLogManager())->enabled());

        config()->set('chat-log.enabled', false);
        $this->assertFalse((new ChatLogManager())->enabled());
    }

    public function test_log_is_noop_when_disabled(): void
    {
        config()->set('chat-log.enabled', false);

        (new ChatLogManager())->log($this->entry());

        $this->assertSame(0, ChatLog::count());
    }

    public function test_log_persists_entry_via_database_driver(): void
    {
        config()->set('chat-log.enabled', true);
        config()->set('chat-log.driver', 'database');

        (new ChatLogManager())->log($this->entry([
            'sessionId' => '11111111-1111-4111-8111-111111111111',
            'question' => 'Come configuro OAuth?',
            'answer' => 'Vedi docs',
            'projectKey' => 'erp-core',
            'aiProvider' => 'openai',
            'aiModel' => 'gpt-4o',
            'chunksCount' => 4,
            'sources' => ['docs/a.md', 'docs/b.md'],
            'totalTokens' => 123,
            'latencyMs' => 500,
            'clientIp' => '127.0.0.1',
            'userAgent' => 'phpunit',
            'extra' => ['few_shot_count' => 1],
        ]));

        $this->assertSame(1, ChatLog::count());

        $row = ChatLog::first();
        $this->assertSame('11111111-1111-4111-8111-111111111111', $row->session_id);
        $this->assertSame('Come configuro OAuth?', $row->question);
        $this->assertSame('erp-core', $row->project_key);
        $this->assertSame(['docs/a.md', 'docs/b.md'], $row->sources);
        $this->assertSame(['few_shot_count' => 1], $row->extra);
    }

    public function test_log_swallows_driver_errors(): void
    {
        config()->set('chat-log.enabled', true);
        config()->set('chat-log.driver', 'unknown-driver');

        Log::shouldReceive('error')->once();

        // Should NOT throw — errors are caught and logged
        (new ChatLogManager())->log($this->entry());

        $this->assertSame(0, ChatLog::count());
    }

    public function test_log_persists_server_resolved_cost_and_trace_id(): void
    {
        // v8.16/W3 — the database driver resolves the per-turn cost server-side
        // via the finops CostResolutionService and persists it. Pricing feeds are
        // disabled so the cascade never HTTPs on a cache miss (cost resolves to 0
        // in the base currency); we assert the SHAPE (cost non-null + currency +
        // trace_id), not the price.
        config()->set('chat-log.enabled', true);
        config()->set('chat-log.driver', 'database');
        config([
            'ai-finops.enabled' => true,
            // Cost resolution requires metering ON (warms the price cache); feeds off.
            'ai-finops.metering' => true,
            'ai-finops.pricing.litellm.enabled' => false,
            'ai-finops.pricing.openrouter.enabled' => false,
        ]);

        (new ChatLogManager())->log($this->entry([
            'aiProvider' => 'openai',
            'aiModel' => 'gpt-4o',
            'promptTokens' => 1200,
            'completionTokens' => 300,
            'totalTokens' => 1500,
            'traceId' => 'trace-abc-123',
        ]));

        $row = ChatLog::first();
        $this->assertNotNull($row->cost, 'cost must be resolved + persisted (0 with feeds off, never null)');
        // Currency follows the configurable base, not a hard-coded literal.
        $this->assertSame((string) config('ai-finops.currency.base', 'USD'), $row->cost_currency);
        $this->assertSame('trace-abc-123', $row->trace_id);
    }

    public function test_log_leaves_cost_null_when_finops_disabled(): void
    {
        config()->set('chat-log.enabled', true);
        config()->set('chat-log.driver', 'database');
        config()->set('ai-finops.enabled', false);

        (new ChatLogManager())->log($this->entry([
            'promptTokens' => 100,
            'completionTokens' => 50,
        ]));

        $row = ChatLog::first();
        $this->assertNull($row->cost);
        $this->assertNull($row->cost_currency);
    }

    public function test_anonymous_turn_prices_from_tokens_only_never_text(): void
    {
        // The anonymous (data-minimised) path persists cost too, but it MUST price
        // from the token counts only — never the stripped question / answer (PII).
        // Prove it with a resolver spy: promptText / completionText must be null.
        config()->set('chat-log.enabled', true);
        config()->set('chat-log.driver', 'database');
        config()->set('chat-log.anonymous_level', 'minimal');

        $resolver = Mockery::mock(ChatTurnCostResolver::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->withArgs(function (
                string $provider,
                string $model,
                ?int $promptTokens,
                ?int $completionTokens,
                ?string $promptText = null,
                ?string $completionText = null,
                ?string $traceId = null,
            ): bool {
                // NO text reaches the resolver on the anonymous path.
                return $promptText === null
                    && $completionText === null
                    && $promptTokens === 800
                    && $completionTokens === 200;
            })
            ->andReturn(new ChatTurnCost('0.00012300', 'USD', 'computed'));
        $this->app->instance(ChatTurnCostResolver::class, $resolver);

        (new ChatLogManager())->log($this->entry([
            'anonymous' => true,
            'question' => 'Email me at mario@example.com', // PII — must never be priced from / stored
            'answer' => 'sensitive answer',
            'aiProvider' => 'openai',
            'aiModel' => 'gpt-4o',
            'promptTokens' => 800,
            'completionTokens' => 200,
            'totalTokens' => 1000,
            'traceId' => 'anon-trace-1',
        ]));

        $row = ChatLog::first();
        $this->assertSame('0.00012300', (string) $row->cost);
        $this->assertSame('USD', $row->cost_currency);
        $this->assertSame('anon-trace-1', $row->trace_id);
        // PII stripped on the anonymous row.
        $this->assertSame('', $row->question);
        $this->assertSame('', $row->answer);
    }

    public function test_anonymous_turn_leaves_cost_null_when_finops_disabled(): void
    {
        config()->set('chat-log.enabled', true);
        config()->set('chat-log.driver', 'database');
        config()->set('chat-log.anonymous_level', 'minimal');
        config()->set('ai-finops.enabled', false);

        (new ChatLogManager())->log($this->entry([
            'anonymous' => true,
            'promptTokens' => 100,
            'completionTokens' => 50,
        ]));

        $row = ChatLog::first();
        $this->assertNull($row->cost);
        $this->assertNull($row->cost_currency);
        $this->assertSame('', $row->question);
    }
}
