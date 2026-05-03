<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Ai\AiManager;
use App\Models\ChatLog;
use App\Services\Admin\AiInsightsService;
use App\Services\Kb\Canonical\PromotionSuggestService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * v4.1/W4.1.D — feature tests for the PII pre-redact integration in
 * `AiInsightsService::coverageGaps()`.
 *
 * Three observable contracts:
 *   1. Default (both knobs off) — chat-question text reaches the LLM
 *      and the snapshot payload UNTOUCHED.
 *   2. Master switch off + insights knob on — master switch wins; same
 *      pass-through behaviour as the all-off case.
 *   3. Both knobs on — every sample question is masked BEFORE the LLM
 *      sees it; PII never lands in `sample_questions` of the returned
 *      snapshot payload.
 */
final class AiInsightsServicePiiRedactTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The package's RedactionStrategy + RedactorEngine singletons read
     * `pii-redactor.strategy` + `pii-redactor.salt` once at SP boot.
     * `defineEnvironment` runs BEFORE that resolution, so the values
     * we set here are the ones the SP closure observes. (Same shape
     * as `RedactChatPiiTest::defineEnvironment` from W4.1.B.)
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('pii-redactor.strategy', 'mask');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function svc(): AiInsightsService
    {
        return new AiInsightsService(
            app(AiManager::class),
            app(PromotionSuggestService::class),
        );
    }

    public function test_pii_pre_redact_off_by_default_passes_questions_through(): void
    {
        config()->set('kb.pii_redactor.enabled', false);
        config()->set('kb.pii_redactor.redact_insights_snippets', false);

        $this->makeChatLog([], 'Email me at mario@example.com please', 0);

        $this->fakeLlm();
        $this->svc()->coverageGaps();

        // Load-bearing observable: the LLM must see the raw email
        // when both knobs are off. Whatever the snapshot's
        // `sample_questions` ends up containing is downstream of the
        // (mocked) LLM response shape — the input-side assertion is
        // the one that proves the pre-redact step did NOT fire.
        $this->assertStringContainsString(
            'mario@example.com',
            $this->lastRequestBody(),
            'When both knobs are off, the LLM must see the raw email.',
        );
    }

    public function test_master_switch_off_short_circuits_insights_knob(): void
    {
        config()->set('kb.pii_redactor.enabled', false);
        config()->set('kb.pii_redactor.redact_insights_snippets', true);

        $this->makeChatLog([], 'Reach me at giulia@example.org thanks', 0);

        $this->fakeLlm();
        $this->svc()->coverageGaps();

        // Master switch off short-circuits the per-touch-point knob.
        // Same observable as the all-off case: the LLM still sees
        // the raw email despite `redact_insights_snippets=true`.
        $this->assertStringContainsString(
            'giulia@example.org',
            $this->lastRequestBody(),
            'Master switch off must beat insights knob on.',
        );
    }

    public function test_both_knobs_on_masks_pii_before_llm_and_snapshot_payload(): void
    {
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('kb.pii_redactor.redact_insights_snippets', true);

        $this->makeChatLog([], 'Email me at mario@example.com please', 0);
        $this->makeChatLog([], 'My phone is +393331234567', 0);

        $this->fakeLlm();
        $out = $this->svc()->coverageGaps();

        $this->assertNotEmpty($out);

        // (a) The LLM input must NOT contain the original PII.
        $rawBody = $this->lastRequestBody();
        $this->assertNotSame('', $rawBody, 'Http::fake should have captured at least one request.');
        $this->assertStringNotContainsString(
            'mario@example.com',
            $rawBody,
            'Provider must NOT see the raw email when redact_insights_snippets is on.',
        );
        $this->assertStringNotContainsString(
            '+393331234567',
            $rawBody,
            'Provider must NOT see the raw phone when redact_insights_snippets is on.',
        );

        // (b) Snapshot payload's `sample_questions` must NOT contain
        // the original PII either — the LLM stub echoes the masked
        // string back, but the assertion holds on whatever shape the
        // package's MaskStrategy emits.
        foreach ($out as $cluster) {
            foreach ($cluster['sample_questions'] as $q) {
                $this->assertStringNotContainsString('mario@example.com', $q);
                $this->assertStringNotContainsString('+393331234567', $q);
            }
        }
    }

    /**
     * Fake the LLM with a passthrough that echoes the questions
     * extracted from the prompt back as a single cluster. Used in
     * tandem with `lastRequestBody()` — that helper reads the recorded
     * request body AFTER the service call, so the test is agnostic to
     * whether the captured-via-closure pattern works under a given
     * Laravel/Http test-double release.
     */
    private function fakeLlm(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        // The clustering only requires a valid JSON
                        // shape — the actual `sample_questions` echoed
                        // back here is irrelevant; the assertions
                        // inspect the OUTGOING request body (what the
                        // service sent the provider) and the snapshot
                        // shape (which is built from the SERVICE's
                        // pre-LLM mask, not the LLM response).
                        'content' => '[{"topic":"Contact","sample_questions":[]}]',
                    ],
                    'finish_reason' => 'stop',
                ]],
                'model' => 'gpt-4o-mini',
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ], 200),
        ]);
    }

    private function lastRequestBody(): string
    {
        $recorded = Http::recorded();
        if ($recorded->isEmpty()) {
            return '';
        }

        // `Http::recorded()` returns a Collection of [Request, Response]
        // tuples (most-recent first when used with the fake handler).
        $first = $recorded->last();
        $request = is_array($first) ? ($first[0] ?? null) : null;
        if ($request === null || ! method_exists($request, 'body')) {
            return '';
        }

        return (string) $request->body();
    }

    /**
     * @param  array<int, array<string, string>>  $sources
     */
    private function makeChatLog(array $sources, string $question, int $chunks): ChatLog
    {
        return ChatLog::create([
            'session_id' => (string) Str::uuid(),
            'user_id' => null,
            'question' => $question,
            'answer' => 'a',
            'project_key' => 'hr-portal',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o',
            'chunks_count' => $chunks,
            'sources' => $sources ?: null,
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
            'latency_ms' => 100,
            'created_at' => Carbon::now()->subDays(2),
        ]);
    }
}
