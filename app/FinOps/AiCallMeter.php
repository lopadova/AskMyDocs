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
 * Bridges AskMyDocs' raw-`Http::` AI providers into the laravel-ai-finops usage
 * ledger so EVERY provider is metered, not just Regolo.
 *
 * The package meters automatically ONLY for calls that flow through the
 * laravel/ai SDK lifecycle events. In AskMyDocs that is Regolo alone — OpenAI /
 * Anthropic / Gemini / OpenRouter all transit raw `Http::` inside their
 * providers — so without this bridge the ledger would stay empty for the default
 * (openrouter) chat traffic and for every ingestion embedding.
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
 */
final class AiCallMeter
{
    public function meterChat(AiResponse $response, string|array|null $prompt = null): void
    {
        if (! $this->shouldMeter($response->provider)) {
            return;
        }

        try {
            $agentResponse = new AgentResponse(
                invocationId: (string) Str::uuid(),
                text: $response->content,
                usage: new Usage(
                    promptTokens: $response->promptTokens ?? 0,
                    completionTokens: $response->completionTokens ?? 0,
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
            $embeddingsResponse = new LaravelAiEmbeddingsResponse(
                embeddings: [],
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
     * Whether a call from this provider should be metered HERE.
     *
     * Regolo is excluded: it flows through the laravel/ai SDK, which already
     * dispatches the metering events — re-recording it here would double-count.
     * The class guard keeps the host healthy if the finops package is removed.
     */
    private function shouldMeter(string $provider): bool
    {
        if ($provider === 'regolo') {
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
