<?php

declare(strict_types=1);

namespace App\ApiConnectors;

use App\Ai\AiManager;
use Padosoft\AskMyDocsConnectorApi\Contracts\ResponseAnalyst;
use Throwable;

/**
 * Host adapter binding the API-connector package's {@see ResponseAnalyst} onto
 * AskMyDocs's {@see AiManager}, so the workbench "Analisi" narration runs through
 * the same provider stack (OpenAI / Anthropic / Gemini / OpenRouter / Regolo) as
 * the rest of the app.
 *
 * It receives the ALREADY-REDUCED body ({@see \Padosoft\AskMyDocsConnectorApi\Support\StructureReducer}),
 * so the prompt is small and the whole shape is visible. Best-effort (R14): a
 * provider hiccup or an empty reply returns null and the workbench simply shows
 * the deterministic reduced structure without narration. Gated upstream by
 * `connector-api.llm_assist.enabled`.
 */
final readonly class AiResponseAnalyst implements ResponseAnalyst
{
    public function __construct(private AiManager $ai) {}

    public function analyze(array $context): ?string
    {
        $reduced = json_encode(
            $context['reduced'] ?? null,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (! is_string($reduced) || $reduced === '' || $reduced === 'null') {
            return null;
        }

        $method = is_string($context['method'] ?? null) ? $context['method'] : '';
        $url = is_string($context['url'] ?? null) ? $context['url'] : '';

        try {
            $response = $this->ai->chat(
                'You are an API-integration assistant. In a few sentences, describe the STRUCTURE of this '
                    .'(already truncated) JSON response so an operator can turn the endpoint into an LLM tool: the '
                    .'top-level shape, where the main collection is, the key fields of each item, and anything notable '
                    .'(identifiers, nesting, pagination hints). Be concise; do NOT invent fields that are not present.',
                "Endpoint: {$method} {$url}\n\nReduced response:\n{$reduced}",
                ['max_tokens' => 400],
            );
        } catch (Throwable) {
            return null;
        }

        $text = trim($response->content);

        return $text === '' ? null : $text;
    }
}
