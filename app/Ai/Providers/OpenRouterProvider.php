<?php

namespace App\Ai\Providers;

use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use App\Ai\Providers\Concerns\FallbackStreaming;
use Illuminate\Support\Facades\Http;

final class OpenRouterProvider implements AiProviderInterface
{
    use FallbackStreaming;

    private string $baseUrl;

    public function __construct(private readonly array $config)
    {
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://openrouter.ai/api/v1', '/');
    }

    public function chat(string $systemPrompt, string $userMessage, array $options = []): AiResponse
    {
        return $this->chatWithHistory($systemPrompt, [
            ['role' => 'user', 'content' => $userMessage],
        ], $options);
    }

    public function chatWithHistory(string $systemPrompt, array $messages, array $options = []): AiResponse
    {
        $apiMessages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($messages as $msg) {
            if (! is_array($msg)) {
                continue;
            }

            $role = (string) ($msg['role'] ?? '');
            if ($role === '') {
                continue;
            }

            $apiMessage = [
                'role' => $role,
                'content' => is_string($msg['content'] ?? null) ? (string) $msg['content'] : '',
            ];

            if (array_key_exists('tool_calls', $msg)) {
                $apiMessage['tool_calls'] = $msg['tool_calls'];
            }
            if (array_key_exists('tool_call_id', $msg)) {
                $apiMessage['tool_call_id'] = (string) $msg['tool_call_id'];
            }
            if (array_key_exists('name', $msg)) {
                $apiMessage['name'] = (string) $msg['name'];
            }

            $apiMessages[] = $apiMessage;
        }

        $payload = [
            'model' => $options['model'] ?? $this->config['chat_model'] ?? 'anthropic/claude-sonnet-4-20250514',
            'messages' => $apiMessages,
            'temperature' => $options['temperature'] ?? $this->config['temperature'] ?? 0.2,
            'max_tokens' => $options['max_tokens'] ?? $this->config['max_tokens'] ?? 4096,
        ];

        if (array_key_exists('tools', $options)) {
            $payload['tools'] = $options['tools'];
        }
        if (array_key_exists('tool_choice', $options)) {
            $payload['tool_choice'] = $options['tool_choice'];
        }

        $response = Http::withToken($this->config['api_key'])
            ->withHeaders([
                'HTTP-Referer' => $this->config['site_url'] ?? config('app.url', ''),
                'X-Title' => $this->config['app_name'] ?? config('app.name', 'Enterprise KB'),
            ])
            ->timeout($this->config['timeout'] ?? 120)
            ->post("{$this->baseUrl}/chat/completions", $payload);

        $response->throw();
        $data = $response->json();
        $message = is_array($data['choices'][0]['message'] ?? null) ? $data['choices'][0]['message'] : [];

        return new AiResponse(
            content: is_string($message['content'] ?? null) ? $message['content'] : '',
            provider: $this->name(),
            model: $data['model'] ?? $this->config['chat_model'],
            promptTokens: $data['usage']['prompt_tokens'] ?? null,
            completionTokens: $data['usage']['completion_tokens'] ?? null,
            totalTokens: $data['usage']['total_tokens'] ?? null,
            finishReason: $data['choices'][0]['finish_reason'] ?? null,
            toolCalls: $this->normalizeToolCalls($message['tool_calls'] ?? null),
        );
    }

    public function chatStream(string $systemPrompt, array $messages, array $options = []): \Generator
    {
        // OpenRouter relays streaming for any upstream model that
        // supports it (`stream: true` over SSE). Fallback shipped in
        // W3.1; native streaming variant lands later without breaking
        // the public contract.
        return $this->streamFromChat($systemPrompt, $messages, $options);
    }

    public function generateEmbeddings(array $texts): EmbeddingsResponse
    {
        $response = Http::withToken($this->config['api_key'])
            ->withHeaders([
                'HTTP-Referer' => $this->config['site_url'] ?? config('app.url', ''),
                'X-Title' => $this->config['app_name'] ?? config('app.name', 'Enterprise KB'),
            ])
            ->timeout($this->config['timeout'] ?? 120)
            ->post("{$this->baseUrl}/embeddings", [
                'model' => $this->config['embeddings_model'] ?? 'openai/text-embedding-3-small',
                'input' => $texts,
            ]);

        $response->throw();
        $data = $response->json();

        $embeddings = collect($data['data'] ?? [])
            ->sortBy('index')
            ->pluck('embedding')
            ->values()
            ->all();

        return new EmbeddingsResponse(
            embeddings: $embeddings,
            provider: $this->name(),
            model: $data['model'] ?? ($this->config['embeddings_model'] ?? 'openai/text-embedding-3-small'),
            totalTokens: $data['usage']['total_tokens'] ?? null,
        );
    }

    public function name(): string
    {
        return 'openrouter';
    }

    public function supportsEmbeddings(): bool
    {
        return true;
    }

    private function normalizeToolCalls(mixed $rawToolCalls): array
    {
        if (! is_array($rawToolCalls)) {
            return [];
        }

        $toolCalls = [];
        foreach ($rawToolCalls as $toolCall) {
            if (! is_array($toolCall)) {
                continue;
            }

            $name = (string) data_get($toolCall, 'function.name', $toolCall['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $toolCalls[] = [
                'id' => (string) data_get($toolCall, 'id', 'tool_' . bin2hex(random_bytes(8))),
                'name' => $name,
                'arguments' => $this->toolArgumentsToString(
                    data_get($toolCall, 'function.arguments', $toolCall['arguments'] ?? '')
                ),
            ];
        }

        return $toolCalls;
    }

    private function toolArgumentsToString(mixed $arguments): string
    {
        if (is_string($arguments)) {
            return $arguments;
        }

        $json = json_encode($arguments, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return '{}';
        }

        return $json;
    }
}

