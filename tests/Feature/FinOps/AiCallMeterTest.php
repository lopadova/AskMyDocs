<?php

declare(strict_types=1);

namespace Tests\Feature\FinOps;

use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use App\FinOps\AiCallMeter;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Padosoft\LaravelAiFinOps\Metering\MeteringListener;
use RuntimeException;
use Tests\TestCase;

/**
 * Full-coverage metering bridge (R44): the AiManager chokepoint feeds EVERY
 * raw-Http provider into the finops ledger, while Regolo (already metered by the
 * laravel/ai SDK) is skipped to avoid double-counting.
 */
final class AiCallMeterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite runs with AI_FINOPS_METERING=false (phpunit.xml). Turn it on
        // for this test, with BOTH price feeds disabled so PricingRegistry never
        // HTTPs on a cache miss — cost resolves to 0, which is fine: we assert the
        // ledger ROW (provider / model / tenant / tokens), not the price.
        config([
            'ai-finops.enabled' => true,
            'ai-finops.metering' => true,
            'ai-finops.pricing.litellm.enabled' => false,
            'ai-finops.pricing.openrouter.enabled' => false,
        ]);
    }

    public function test_meters_a_raw_http_chat_call_attributed_to_the_active_tenant(): void
    {
        app(TenantContext::class)->set('acme');

        app(AiCallMeter::class)->meterChat(new AiResponse(
            content: 'hello world',
            provider: 'openrouter',
            model: 'openai/gpt-4o-mini',
            promptTokens: 120,
            completionTokens: 45,
            totalTokens: 165,
        ));

        $row = DB::table('ai_finops_usage_ledger')
            ->where('tenant_id', app(TenantContext::class)->current())
            ->latest('id')
            ->first();

        $this->assertNotNull($row, 'expected a usage-ledger row for the openrouter call');
        $this->assertSame('openrouter', $row->provider);
        $this->assertSame('openai/gpt-4o-mini', $row->model);
        $this->assertSame('acme', $row->tenant_id);
        $this->assertSame(120, (int) $row->tokens_input);
        $this->assertSame(45, (int) $row->tokens_output);
        $this->assertSame('text', $row->modality);
    }

    public function test_meters_a_chat_with_history_array_prompt(): void
    {
        // AiManager::chatWithHistory() passes the full message ARRAY as $prompt to
        // meterChat(string|array|null). Assert that shape reaches the ledger — i.e.
        // MeteringListener::recordAgentResponse() accepts an array prompt and does
        // NOT throw and get swallowed by the bridge's try/catch (silent
        // under-metering). The first chat test only exercises the null-prompt path.
        app(TenantContext::class)->set('acme');

        app(AiCallMeter::class)->meterChat(
            new AiResponse(
                content: 'grounded answer',
                provider: 'anthropic',
                model: 'claude-sonnet-4-20250514',
                promptTokens: 200,
                completionTokens: 60,
                totalTokens: 260,
            ),
            [
                ['role' => 'user', 'content' => 'first turn'],
                ['role' => 'assistant', 'content' => 'reply'],
                ['role' => 'user', 'content' => 'follow-up question'],
            ],
        );

        $row = DB::table('ai_finops_usage_ledger')
            ->where('tenant_id', app(TenantContext::class)->current())
            ->latest('id')
            ->first();

        $this->assertNotNull($row, 'expected a usage-ledger row for the array-prompt (chatWithHistory) call');
        $this->assertSame('anthropic', $row->provider);
        $this->assertSame('claude-sonnet-4-20250514', $row->model);
        $this->assertSame('acme', $row->tenant_id);
        $this->assertSame(200, (int) $row->tokens_input);
        $this->assertSame(60, (int) $row->tokens_output);
        $this->assertSame('text', $row->modality);
    }

    public function test_derives_the_token_split_from_total_when_a_provider_omits_it(): void
    {
        // Some providers report only a total (prompt/completion null). The bridge
        // must derive the split from the total so the ledger captures the token
        // VOLUME (and a cost floor) instead of recording 0/0 — silent under-metering.
        app(TenantContext::class)->set('acme');

        app(AiCallMeter::class)->meterChat(new AiResponse(
            content: 'total-only answer',
            provider: 'openrouter',
            model: 'openai/gpt-4o-mini',
            totalTokens: 300, // prompt + completion both null
        ));

        $row = DB::table('ai_finops_usage_ledger')
            ->where('tenant_id', app(TenantContext::class)->current())
            ->latest('id')
            ->first();

        $this->assertNotNull($row, 'expected a ledger row even when only totalTokens is present');
        // Whole total attributed to input as the documented floor — NOT 0/0.
        $this->assertSame(300, (int) $row->tokens_input);
        $this->assertSame(0, (int) $row->tokens_output);
    }

    public function test_does_not_double_count_regolo_which_the_sdk_already_meters(): void
    {
        app(AiCallMeter::class)->meterChat(new AiResponse(
            content: 'hi',
            provider: 'regolo',
            model: 'Llama-3.3-70B-Instruct',
            promptTokens: 10,
            completionTokens: 5,
            totalTokens: 15,
        ));

        $this->assertSame(
            0,
            DB::table('ai_finops_usage_ledger')
                ->where('tenant_id', app(TenantContext::class)->current())
                ->count(),
            'Regolo flows through the laravel/ai SDK and is metered there; the host bridge must skip it.',
        );
    }

    public function test_records_nothing_when_metering_is_disabled(): void
    {
        config(['ai-finops.metering' => false]);

        app(AiCallMeter::class)->meterChat(new AiResponse(
            content: 'hi',
            provider: 'openrouter',
            model: 'openai/gpt-4o-mini',
            promptTokens: 10,
            completionTokens: 5,
            totalTokens: 15,
        ));

        $this->assertSame(0, DB::table('ai_finops_usage_ledger')
            ->where('tenant_id', app(TenantContext::class)->current())
            ->count());
    }

    public function test_meters_an_embeddings_call(): void
    {
        app(TenantContext::class)->set('acme');

        app(AiCallMeter::class)->meterEmbeddings(new EmbeddingsResponse(
            embeddings: [[0.1, 0.2, 0.3]],
            provider: 'openai',
            model: 'text-embedding-3-small',
            totalTokens: 512,
        ));

        $row = DB::table('ai_finops_usage_ledger')
            ->where('tenant_id', app(TenantContext::class)->current())
            ->latest('id')
            ->first();

        $this->assertNotNull($row, 'expected a usage-ledger row for the embeddings call');
        $this->assertSame('openai', $row->provider);
        $this->assertSame('text-embedding-3-small', $row->model);
        $this->assertSame('acme', $row->tenant_id);
        $this->assertSame(512, (int) $row->tokens_input);
        $this->assertSame('embedding', $row->modality);
    }

    public function test_a_metering_failure_never_propagates_to_the_caller(): void
    {
        // Force the metering pipeline to blow up; the bridge must swallow it
        // (ChatLogManager discipline — a ledger failure can't break a chat turn).
        $this->app->bind(MeteringListener::class, function (): MeteringListener {
            throw new RuntimeException('boom');
        });

        app(AiCallMeter::class)->meterChat(new AiResponse(
            content: 'hi',
            provider: 'openrouter',
            model: 'openai/gpt-4o-mini',
            promptTokens: 10,
            completionTokens: 5,
            totalTokens: 15,
        ));

        $this->expectNotToPerformAssertions();
    }
}
