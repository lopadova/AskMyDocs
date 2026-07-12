<?php

declare(strict_types=1);

namespace App\ApiConnectors;

use App\Ai\AiManager;
use Padosoft\AskMyDocsConnectorApi\Contracts\ResponseAnalyst;
use Padosoft\AskMyDocsConnectorApi\Support\RouteConfigSchema;
use Throwable;

/**
 * Host adapter binding the API-connector package's {@see ResponseAnalyst} onto
 * AskMyDocs's {@see AiManager}, so "Configura con AI" produces the route's config
 * JSON through the same provider stack (OpenAI / Anthropic / Gemini / OpenRouter
 * / Regolo) as the rest of the app.
 *
 * It receives the ALREADY-REDUCED response sample + the target config JSON Schema
 * + a deterministic seed, and returns a sanitized config
 * ({@see \Padosoft\AskMyDocsConnectorApi\Support\RouteConfigSchema}). Best-effort
 * (R14): a provider hiccup or an unparseable reply returns null and the service
 * falls back to the deterministic seed. Gated upstream by
 * `connector-api.llm_assist.enabled`.
 */
final readonly class AiResponseAnalyst implements ResponseAnalyst
{
    public function __construct(private AiManager $ai) {}

    public function produceConfig(array $context): ?array
    {
        $reduced = json_encode(
            $context['reduced'] ?? null,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (! is_string($reduced) || $reduced === '' || $reduced === 'null') {
            return null;
        }

        $method = is_string($context['method'] ?? null) ? $context['method'] : '';
        $url = is_string($context['url'] ?? null) ? $context['url'] : '';
        $exampleArgs = json_encode($context['example_args'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $seed = json_encode($context['seed'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $schema = json_encode($context['schema'] ?? [], JSON_UNESCAPED_SLASHES) ?: '{}';

        try {
            $response = $this->ai->chat(
                'You configure an HTTP endpoint as an LLM tool. You are given the request, a truncated sample response, '
                    .'a target JSON Schema, and a deterministic seed. Respond with ONLY a single JSON object that '
                    .'VALIDATES against the schema (groups identity·request·response·options). Infer request.params ONLY '
                    .'from the URL query string / path template / request body evident in the sample — never invent '
                    .'unsupported params. `source` is llm for anything the caller supplies, secret for API keys/tokens, '
                    .'fixed for constants. Keep response.endpoint_type / items_path / pagination from the seed unless the '
                    .'sample clearly contradicts them. NEVER include secret values — only secret_ref key names. Produce a '
                    .'concise snake_case identity.name and a one-sentence identity.description.',
                "Endpoint: {$method} {$url}\nExample args: {$exampleArgs}\n\nDeterministic seed:\n{$seed}\n\n"
                    ."Target JSON Schema:\n{$schema}\n\nReduced response sample:\n{$reduced}",
                ['max_tokens' => 900],
            );
        } catch (Throwable) {
            return null;
        }

        // sanitize() validates + coerces the whole config or returns null (R14);
        // decodeObject tolerates a ```json fenced reply.
        return RouteConfigSchema::sanitize($this->decodeObject($response->content));
    }

    /**
     * Decode a model reply that may be wrapped in a ```json … ``` fence.
     *
     * @return array<string,mixed>|null
     */
    private function decodeObject(string $content): ?array
    {
        $text = trim($content);
        if (str_starts_with($text, '```')) {
            $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
        }
        $decoded = json_decode(trim($text), true);

        return is_array($decoded) ? $decoded : null;
    }
}
