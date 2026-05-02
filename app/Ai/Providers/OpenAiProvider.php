<?php

namespace App\Ai\Providers;

use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use App\Ai\Providers\Concerns\FallbackStreaming;
use Illuminate\Support\Facades\Http;

final class OpenAiProvider implements AiProviderInterface
{
    use FallbackStreaming;

    private string $baseUrl;

    public function __construct(private readonly array $config)
    {
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/');
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
            $apiMessages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        $response = Http::withToken($this->config['api_key'])
            ->timeout($this->config['timeout'] ?? 120)
            ->post("{$this->baseUrl}/chat/completions", [
                'model' => $options['model'] ?? $this->config['chat_model'] ?? 'gpt-4o',
                'messages' => $apiMessages,
                'temperature' => $options['temperature'] ?? $this->config['temperature'] ?? 0.2,
                'max_tokens' => $options['max_tokens'] ?? $this->config['max_tokens'] ?? 4096,
            ]);

        $response->throw();
        $data = $response->json();

        return new AiResponse(
            content: $data['choices'][0]['message']['content'] ?? '',
            provider: $this->name(),
            model: $data['model'],
            promptTokens: $data['usage']['prompt_tokens'] ?? null,
            completionTokens: $data['usage']['completion_tokens'] ?? null,
            totalTokens: $data['usage']['total_tokens'] ?? null,
            finishReason: $data['choices'][0]['finish_reason'] ?? null,
        );
    }

    public function generateEmbeddings(array $texts): EmbeddingsResponse
    {
        $response = Http::withToken($this->config['api_key'])
            ->timeout($this->config['timeout'] ?? 120)
            ->post("{$this->baseUrl}/embeddings", [
                'model' => $this->config['embeddings_model'] ?? 'text-embedding-3-small',
                'input' => $texts,
            ]);

        $response->throw();
        $data = $response->json();

        $embeddings = collect($data['data'])
            ->sortBy('index')
            ->pluck('embedding')
            ->values()
            ->all();

        return new EmbeddingsResponse(
            embeddings: $embeddings,
            provider: $this->name(),
            model: $data['model'],
            totalTokens: $data['usage']['total_tokens'] ?? null,
        );
    }

    public function chatStream(string $systemPrompt, array $messages, array $options = []): \Generator
    {
        // OpenAI supports `stream: true` over SSE. This W3.1 PR ships
        // the fallback path so the streaming endpoint works end-to-end
        // for every configured provider; native HTTP-SSE streaming is
        // a planned follow-up (W3.2-adjacent or post-W3) and replaces
        // this body without changing the public contract.
        return $this->streamFromChat($systemPrompt, $messages, $options);
    }

    public function name(): string
    {
        return 'openai';
    }

    public function supportsEmbeddings(): bool
    {
        return true;
    }
}
