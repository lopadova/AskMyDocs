<?php

declare(strict_types=1);

namespace App\ApiConnectors;

use App\Ai\AiManager;
use Padosoft\AskMyDocsConnectorApi\Contracts\ResponseAnalyst;
use Padosoft\AskMyDocsConnectorApi\Support\RouteConfigSchema;
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

    public function detectPagination(array $context): ?array
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

        try {
            $response = $this->ai->chat(
                'You identify how an HTTP endpoint paginates from its response + URL. Respond ONLY with a JSON '
                    .'object. For page-number: {"type":"page","page_param":"page","size_param":"per_page"}. For '
                    .'cursor/token: {"type":"cursor","cursor_param":"cursor","next_cursor_path":"meta.next_cursor"} or '
                    .'{"type":"cursor","next_url_path":"links.next"}. If it is NOT paginated, respond {"type":"none"}. '
                    .'Use dot-paths for body locations. Do NOT invent params/paths that are not implied by the data.',
                "Endpoint: {$method} {$url}\n\nReduced response:\n{$reduced}",
                ['max_tokens' => 200],
            );
        } catch (Throwable) {
            return null;
        }

        $decoded = json_decode(trim($response->content), true);
        if (! is_array($decoded)) {
            return null;
        }

        $type = is_string($decoded['type'] ?? null) ? $decoded['type'] : null;
        if ($type !== 'page' && $type !== 'cursor') {
            return null; // 'none' / unclear → let the operator configure manually
        }

        return $decoded;
    }

    public function suggestConfiguration(array $context): ?array
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

        try {
            $response = $this->ai->chat(
                'You configure an HTTP endpoint as an LLM tool. Respond ONLY with a JSON object: '
                    .'{"tool_name":"snake_case","tool_description":"one sentence","parameters":[{"name":"q",'
                    .'"location":"query|path|header|body","source":"llm|fixed|secret","type":"string|integer|number|boolean|array|object",'
                    .'"required":true,"description":"…"}],"pagination":{"type":"page|cursor","page_param":"page"}}. '
                    .'Infer parameters ONLY from the URL query string / path template and the response — do NOT invent '
                    .'unsupported params. `source` is llm for anything the caller supplies, secret for API keys/tokens. '
                    .'Omit `pagination` if not evident.',
                "Endpoint: {$method} {$url}\n\nReduced response:\n{$reduced}",
                ['max_tokens' => 600],
            );
        } catch (Throwable) {
            return null;
        }

        $decoded = json_decode(trim($response->content), true);
        if (! is_array($decoded)) {
            return null;
        }

        return $this->sanitizeSuggestion($decoded);
    }

    /**
     * @param  array<string,mixed>  $decoded
     * @return array{tool_name?: string, tool_description?: string, parameters?: list<array<string,mixed>>, pagination?: array<string,mixed>}
     */
    private function sanitizeSuggestion(array $decoded): array
    {
        $out = [];
        if (is_string($decoded['tool_name'] ?? null) && $decoded['tool_name'] !== '') {
            $out['tool_name'] = $decoded['tool_name'];
        }
        if (is_string($decoded['tool_description'] ?? null) && $decoded['tool_description'] !== '') {
            $out['tool_description'] = $decoded['tool_description'];
        }

        $locations = ['path', 'query', 'header', 'body'];
        $sources = ['llm', 'fixed', 'secret'];
        $types = ['string', 'integer', 'number', 'boolean', 'array', 'object'];
        $params = [];
        foreach (is_array($decoded['parameters'] ?? null) ? $decoded['parameters'] : [] as $param) {
            if (! is_array($param) || ! is_string($param['name'] ?? null) || $param['name'] === '') {
                continue;
            }
            $params[] = [
                'name' => $param['name'],
                'location' => in_array($param['location'] ?? null, $locations, true) ? $param['location'] : 'query',
                'source' => in_array($param['source'] ?? null, $sources, true) ? $param['source'] : 'llm',
                'type' => in_array($param['type'] ?? null, $types, true) ? $param['type'] : 'string',
                'required' => (bool) ($param['required'] ?? false),
                'description' => is_string($param['description'] ?? null) ? $param['description'] : null,
            ];
        }
        if ($params !== []) {
            $out['parameters'] = $params;
        }

        if (is_array($decoded['pagination'] ?? null) && in_array($decoded['pagination']['type'] ?? null, ['page', 'cursor'], true)) {
            $out['pagination'] = $decoded['pagination'];
        }

        return $out;
    }

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
