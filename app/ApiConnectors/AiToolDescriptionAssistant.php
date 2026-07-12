<?php

declare(strict_types=1);

namespace App\ApiConnectors;

use App\Ai\AiManager;
use Padosoft\AskMyDocsConnectorApi\Contracts\ToolDescriptionAssistant;
use Throwable;

/**
 * Host adapter binding the API-connector package's {@see ToolDescriptionAssistant}
 * onto {@see AiManager}. With this bound, testing a route asks the model for a
 * concise snake_case tool name + one-sentence description from the endpoint +
 * response sample (the package otherwise uses field-derived drafts).
 *
 * Best-effort (R14): any provider error or a non-JSON reply returns null and the
 * package falls back to its draft. Gated by `connector-api.llm_assist.enabled`.
 */
final readonly class AiToolDescriptionAssistant implements ToolDescriptionAssistant
{
    public function __construct(private AiManager $ai) {}

    public function suggest(array $context): ?array
    {
        $payload = json_encode([
            'method' => $context['method'] ?? null,
            'url' => $context['url'] ?? null,
            'current_name' => $context['name'] ?? null,
            'input_schema' => $context['input_schema'] ?? null,
            'response_sample' => $context['response_sample'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($payload)) {
            return null;
        }

        try {
            $response = $this->ai->chat(
                'You name and describe HTTP endpoints as LLM tools. Respond ONLY with a JSON object '
                    .'{"name":"snake_case_tool_name","description":"one concise sentence on when to call it"}. '
                    .'The name must be a short snake_case identifier; do NOT invent parameters not in the input schema.',
                $payload,
                ['max_tokens' => 200],
            );
        } catch (Throwable) {
            return null;
        }

        $decoded = json_decode(trim($response->content), true);
        if (! is_array($decoded)) {
            return null;
        }

        $out = [];
        if (isset($decoded['name']) && is_string($decoded['name']) && $decoded['name'] !== '') {
            $out['name'] = $decoded['name'];
        }
        if (isset($decoded['description']) && is_string($decoded['description']) && $decoded['description'] !== '') {
            $out['description'] = $decoded['description'];
        }

        return $out === [] ? null : $out;
    }
}
