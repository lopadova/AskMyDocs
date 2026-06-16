<?php

declare(strict_types=1);

namespace App\Services\Digest;

use App\Ai\AiManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v8.15/W2 — optional LLM "what changed in your KB this week & why it matters"
 * narrative woven into the digest.
 *
 * Config-gated by `kb.digest.ai_narrative_enabled` (default-ON; R43 — tested
 * OFF and ON) and uses a DEDICATED provider/model (`kb.digest.ai_provider` /
 * `kb.digest.ai_model`, default a free OpenRouter model) so digests never
 * compete for the primary chat model. Failures degrade to `null` (the digest
 * ships with deterministic copy instead) — the narrative NEVER breaks the send
 * (R14).
 */
final class AiDigestNarrator
{
    public function __construct(private readonly AiManager $ai)
    {
    }

    /**
     * Returns a 2–4 sentence narrative, or null when disabled / unreachable.
     */
    public function narrate(DigestPayload $payload): ?string
    {
        if (! (bool) config('kb.digest.ai_narrative_enabled', true)) {
            return null;
        }

        if ($payload->isQuiet()) {
            // Nothing happened — a deterministic line reads better than asking
            // an LLM to narrate emptiness, and saves a call.
            return null;
        }

        try {
            $provider = $this->ai->provider(config('kb.digest.ai_provider'));
            $response = $provider->chat(
                $this->systemPrompt(),
                $this->userMessage($payload),
                $this->chatOptions(),
            );

            $text = trim($response->content);

            return $text === '' ? null : $text;
        } catch (Throwable $e) {
            Log::warning('AiDigestNarrator: narrative generation failed, falling back to deterministic copy.', [
                'tenant_id' => $payload->tenantId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function systemPrompt(): string
    {
        return implode(' ', [
            'You are the editor of an internal knowledge-base digest.',
            'Write a concise, energising 2–4 sentence summary of the period for a busy team.',
            'Lead with what matters: new/promoted knowledge, what needs review, and the biggest unanswered questions.',
            'Be specific using the numbers provided; do not invent facts not in the data.',
            'No greeting, no sign-off, no markdown headings — just the summary prose.',
        ]);
    }

    private function userMessage(DigestPayload $payload): string
    {
        // Hand the model the compact JSON facts — it summarises, never fabricates.
        $facts = [
            'period' => $payload->periodLabel(),
            'metrics' => $payload->metrics,
            'new_or_promoted_docs' => $payload->newDocs,
            'docs_needing_review' => $payload->staleDocs,
            'top_unanswered_questions' => $payload->topGaps,
            'top_contributors' => $payload->leaderboard,
        ];

        return "Knowledge-base activity for this period (JSON facts):\n".
            json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).
            "\n\nWrite the digest summary now.";
    }

    /**
     * @return array<string, mixed>
     */
    private function chatOptions(): array
    {
        $options = [];

        $model = config('kb.digest.ai_model');
        if (is_string($model) && $model !== '') {
            $options['model'] = $model;
        }

        $maxTokens = (int) config('kb.digest.narrative_max_tokens', 400);
        if ($maxTokens > 0) {
            $options['max_tokens'] = $maxTokens;
        }

        return $options;
    }
}
