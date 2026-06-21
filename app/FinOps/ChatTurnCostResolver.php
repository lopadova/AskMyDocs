<?php

declare(strict_types=1);

namespace App\FinOps;

use Illuminate\Support\Facades\Log;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Pricing\Cost\CostResolutionService;
use Throwable;

/**
 * Resolves the SERVER-SIDE cost of a single chat turn (v8.16/W3) — the
 * authoritative replacement for the old "token cost set arbitrarily" model
 * (static `config/ai.php cost_rates` resolved client-side).
 *
 * It runs the SAME pricing cascade the finops usage ledger uses
 * ({@see CostResolutionService}: actual billed → tokens×tariff → estimated),
 * via the package's already-warmed price cache (the metering hook resolved the
 * same call moments earlier during `AiManager::chat()`), so this is a cache hit
 * in the normal path — no extra HTTP on the response path.
 *
 * Discipline mirrors {@see AiCallMeter} / {@see \App\Services\ChatLog\ChatLogManager}:
 * class-guarded (host stays healthy if the finops package is absent),
 * config-gated and fully try/catch'd — a pricing failure NEVER breaks a chat
 * turn or its logging; it just yields `null` (cost stays unset).
 *
 * Intentionally NOT `final` so the cost gate is mockable in tests (mirrors
 * AiManager / AiCallMeter).
 */
class ChatTurnCostResolver
{
    /**
     * @param  string|null  $traceId  The request-scoped finops trace id, threaded
     *         into the envelope so the cascade's actual-billed step can correlate
     *         to the metered call (the computed/estimated fallback is unaffected
     *         when no billed cost is available at log time).
     * @return ChatTurnCost|null  Null when finops is absent/disabled or pricing fails.
     */
    public function resolve(
        string $provider,
        string $model,
        ?int $promptTokens,
        ?int $completionTokens,
        ?string $promptText = null,
        ?string $completionText = null,
        ?string $traceId = null,
    ): ?ChatTurnCost {
        if (! $this->enabled()) {
            return null;
        }

        // Synthetic / non-AI turns (refusal + error logs record provider/model as
        // `none`, or empty) never made a metered LLM call, so there is nothing to
        // price AND their (provider, model) was never warmed in the price cache —
        // resolving them would risk the very cold-cache price-feed HTTP fetch on the
        // response path we otherwise avoid. Skip → leave cost null.
        if ($this->isSyntheticTurn($provider, $model)) {
            return null;
        }

        try {
            $usage = new TokenUsage(
                input: max(0, $promptTokens ?? 0),
                output: max(0, $completionTokens ?? 0),
            );

            // Draft envelope so the cascade can route pricing by provider/model
            // (+ correlate to the metered call by trace id when present).
            $draft = new AiCallEnvelope(
                traceId: (string) ($traceId ?? ''),
                provider: $provider,
                model: $model,
                tokens: $usage,
            );

            $resolution = app(CostResolutionService::class)->resolve(
                $draft,
                $usage,
                $promptText,
                $completionText,
            );

            return new ChatTurnCost(
                // v8.18/W1.3 — consume the package's own fixed-precision accessor
                // (laravel-ai-finops ^1.3) instead of re-formatting the float here.
                // CostBreakdown::totalDecimal() is number_format($total, 8) with
                // signed-zero normalization, matching the chat_logs.cost
                // decimal(18,8) column + the ledger's cost_total precision — and
                // it can never emit a surprising "-0.00000000".
                cost: $resolution->cost->totalDecimal(),
                currency: $resolution->cost->currency,
                method: $resolution->method->value,
            );
        } catch (Throwable $e) {
            Log::warning('FinOps chat-turn cost resolution failed; cost left unset.', [
                'provider' => $provider,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * A synthetic / non-AI turn — refusal and error chat-log rows record
     * `ai_provider` / `ai_model` as `none` (or empty) because no LLM was called.
     * There is nothing to price, and the pair was never warmed in the price cache.
     */
    private function isSyntheticTurn(string $provider, string $model): bool
    {
        $p = strtolower(trim($provider));
        $m = strtolower(trim($model));

        return $p === '' || $p === 'none' || $m === '' || $m === 'none';
    }

    /**
     * The class guard keeps the host healthy if the finops package is removed.
     *
     * We require BOTH `ai-finops.enabled` AND `ai-finops.metering` (mirrors
     * {@see AiCallMeter::shouldMeter()}) — and this is load-bearing for
     * RESPONSE-PATH SAFETY, not just symmetry: the metering hook resolves the
     * SAME (provider, model) cost during `AiManager::chat()` earlier in the
     * request, which WARMS the finops `PricingRegistry` cache. Gating on metering
     * guarantees that by the time we resolve here (post-response, at log time) the
     * price is already cached, so we NEVER trigger a synchronous price-feed HTTP
     * fetch on the response path. When metering is off there is no ledger row
     * either, so leaving `chat_logs.cost` null is consistent (R43 clean OFF path).
     */
    private function enabled(): bool
    {
        if (! class_exists(CostResolutionService::class)) {
            return false;
        }

        return (bool) config('ai-finops.enabled', true)
            && (bool) config('ai-finops.metering', true);
    }
}
