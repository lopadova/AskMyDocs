<?php

declare(strict_types=1);

namespace App\FinOps;

use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse as LaravelAiEmbeddingsResponse;
use Padosoft\LaravelAiFinOps\Metering\MeteringListener;
use Throwable;

/**
 * Bridges AskMyDocs' residual raw-`Http::` AI calls into the laravel-ai-finops
 * usage ledger so EVERY call is metered, not just the SDK-native path.
 *
 * The package meters automatically for calls that flow through the laravel/ai SDK
 * lifecycle events. Since v8.16/W2 (ADR 0015) that covers almost everything:
 * Anthropic + Gemini + Regolo run fully through the SDK, and OpenAI + OpenRouter
 * run through it for no-tools chat + embeddings — all metered natively. The ONE
 * path the SDK can't host is the OpenAI / OpenRouter **MCP with-tools turn**,
 * still issued over raw `Http::`. This bridge meters exactly that residual turn
 * (gated by {@see \App\Ai\AiManager::bridgeShouldMeterChat()}); the SDK-metered
 * providers are skipped here ({@see self::SDK_METERED_PROVIDERS}) so nothing is
 * double-counted.
 *
 * We reuse the package's {@see MeteringListener} public `record*` methods
 * directly: they run the FULL pricing cascade + tenant attribution +
 * subscription-coverage check, identical to the SDK path. (We don't re-dispatch
 * the laravel/ai events because their constructors require AgentPrompt / Provider
 * objects we don't have here.)
 *
 * Discipline mirrors {@see \App\Services\ChatLog\ChatLogManager::log()}:
 * config-gated, class-guarded and fully try/catch'd, so a metering failure NEVER
 * breaks a chat turn or an ingestion run.
 *
 * Intentionally NOT `final` (mirrors {@see \App\Ai\AiManager}): the AiManager
 * metering gate is proven by Mockery `shouldNotReceive('meterChat')` /
 * `shouldReceive(...)->once()` (R26 short-circuit proof), which cannot mock a
 * final class.
 */
class AiCallMeter
{
    public function meterChat(AiResponse $response, string|array|null $prompt = null): void
    {
        if (! $this->shouldMeter($response->provider)) {
            return;
        }

        try {
            [$promptTokens, $completionTokens] = $this->resolveTokenSplit(
                $response->promptTokens,
                $response->completionTokens,
                $response->totalTokens,
            );

            $agentResponse = new AgentResponse(
                invocationId: (string) Str::uuid(),
                text: $response->content,
                usage: new Usage(
                    promptTokens: $promptTokens,
                    completionTokens: $completionTokens,
                ),
                meta: new Meta(provider: $response->provider, model: $response->model),
            );

            app(MeteringListener::class)->recordAgentResponse(
                $agentResponse->invocationId,
                $agentResponse,
                $prompt,
            );
        } catch (Throwable $e) {
            $this->logFailure('chat', $response->provider, $response->model, $e);
        }
    }

    public function meterEmbeddings(EmbeddingsResponse $response): void
    {
        if (! $this->shouldMeter($response->provider)) {
            return;
        }

        try {
            // Pass the real vectors through. MeteringListener::recordEmbeddings() prices the
            // call from tokens + meta only, but forwarding the actual embeddings (cheap under
            // PHP copy-on-write for this short-lived, never-mutated DTO) keeps the envelope
            // faithful for any future pricing/footprint logic that reads count/dimension.
            $embeddingsResponse = new LaravelAiEmbeddingsResponse(
                embeddings: $response->embeddings,
                tokens: $response->totalTokens ?? 0,
                meta: new Meta(provider: $response->provider, model: $response->model),
            );

            app(MeteringListener::class)->recordEmbeddings(
                (string) Str::uuid(),
                $embeddingsResponse,
                $response->model,
            );
        } catch (Throwable $e) {
            $this->logFailure('embeddings', $response->provider, $response->model, $e);
        }
    }

    /**
     * Resolve the (prompt, completion) token split recorded on the ledger.
     *
     * Some providers report only a `totalTokens` (prompt/completion null). A bare
     * `?? 0` would then record 0/0 → the price cascade resolves cost to 0 and the
     * call is silently UNDER-metered. Derive the missing side from the total so the
     * ledger at least captures the token VOLUME (and, via the input tariff, a cost
     * floor):
     *  - exactly one side missing → fill it from `total − other` (clamped ≥ 0);
     *  - BOTH sides missing but total present → attribute the whole total to input
     *    (prompt) as a conservative floor (real cost ≥ total × input-rate, since
     *    output-rate ≥ input-rate for every provider we price);
     *  - no total either → 0/0 (nothing to attribute; the listener's text-based
     *    estimator may still kick in upstream).
     *
     * @return array{0: int, 1: int}
     */
    private function resolveTokenSplit(?int $prompt, ?int $completion, ?int $total): array
    {
        if ($total !== null) {
            if ($prompt === null && $completion === null) {
                return [$total, 0];
            }
            if ($prompt === null) {
                return [max(0, $total - $completion), $completion];
            }
            if ($completion === null) {
                return [$prompt, max(0, $total - $prompt)];
            }
        }

        return [$prompt ?? 0, $completion ?? 0];
    }

    /**
     * Providers fully migrated to the laravel/ai SDK — the finops metering hook
     * already records them via the AgentPrompted / EmbeddingsGenerated lifecycle
     * events, so re-recording here would DOUBLE-COUNT. As each provider moves off
     * raw `Http::` (v8.16/W2) it joins this set. openai / openrouter are NOT here:
     * their no-tools path is SDK-metered but their MCP with-tools turn stays on
     * raw `Http::` and IS metered here — AiManager only invokes the bridge for
     * that residual path (see docs/v4-platform/W2-sdk-migration-findings.md).
     */
    private const SDK_METERED_PROVIDERS = ['regolo', 'anthropic', 'gemini'];

    /**
     * Whether a call from this provider should be metered HERE.
     *
     * SDK-native providers are excluded (the lifecycle hook records them).
     * The class guard keeps the host healthy if the finops package is removed.
     */
    private function shouldMeter(string $provider): bool
    {
        if (in_array($provider, self::SDK_METERED_PROVIDERS, true)) {
            return false;
        }

        if (! class_exists(MeteringListener::class)) {
            return false;
        }

        return (bool) config('ai-finops.enabled', true)
            && (bool) config('ai-finops.metering', true);
    }

    private function logFailure(string $kind, string $provider, string $model, Throwable $e): void
    {
        Log::warning("FinOps meter ({$kind}) failed; ledger row skipped.", [
            'provider' => $provider,
            'model' => $model,
            'error' => $e->getMessage(),
        ]);
    }
}
