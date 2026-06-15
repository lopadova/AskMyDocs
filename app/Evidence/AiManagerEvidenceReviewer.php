<?php

declare(strict_types=1);

namespace App\Evidence;

use App\Ai\AiManager;
use Padosoft\EvidenceRiskReview\Contracts\EvidenceReviewerLlmContract;
use Padosoft\EvidenceRiskReview\Data\LlmRequest;
use Padosoft\EvidenceRiskReview\Data\LlmResponse;

/**
 * v8.13/P11 — host adapter binding the evidence-risk-review package's
 * {@see EvidenceReviewerLlmContract} onto AskMyDocs's {@see AiManager}, so the
 * optional LLM semantic-review pass runs through the same provider stack
 * (OpenAI / Anthropic / Gemini / OpenRouter / Regolo) as the rest of the app.
 *
 * Only invoked when `evidence-risk-review.llm.enabled` is true (default-OFF,
 * R43); with the flag off the package keeps using its NullEvidenceReviewerLlm
 * and never calls a model.
 */
final readonly class AiManagerEvidenceReviewer implements EvidenceReviewerLlmContract
{
    public function __construct(private AiManager $ai) {}

    public function complete(LlmRequest $request): LlmResponse
    {
        $response = $this->ai->chat(
            'You are an evidence-and-risk reviewer for grounded answers. '
                .'Respond ONLY with the JSON object the instructions ask for. Purpose: '.$request->purpose,
            $request->prompt,
            ['max_tokens' => $request->maxTokens],
        );

        return new LlmResponse(
            text: $response->content,
            data: $this->decodeJson($response->content),
            tokensUsed: $response->totalTokens ?? 0,
        );
    }

    /**
     * The reviewer prompts ask for a JSON verdict; decode it when present so the
     * package can read structured `data`. A non-JSON answer degrades to `text`
     * only (the package tolerates an empty data map).
     *
     * @return array<string, mixed>
     */
    private function decodeJson(string $content): array
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }
}
