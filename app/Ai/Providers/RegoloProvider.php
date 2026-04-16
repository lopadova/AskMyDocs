<?php

namespace App\Ai\Providers;

use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use Illuminate\Support\Facades\Http;

/**
 * Regolo.ai provider (by Seeweb) — OpenAI-compatible REST API.
 *
 * Docs: https://docs.regolo.ai/
 *
 * Regolo exposes the same request/response shape as OpenAI for both
 * /v1/chat/completions and /v1/embeddings, so the wire format mirrors
 * OpenAiProvider. Only the base URL and default model differ.
 */
final class RegoloProvider implements AiProviderInterface
{
    private string $baseUrl;

    public function __construct(private readonly array $config)
    {
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.regolo.ai/v1', '/');
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
                'model' => $options['model'] ?? $this->config['chat_model'] ?? 'Llama-3.3-70B-Instruct',
                'messages' => $apiMessages,
                'temperature' => $options['temperature'] ?? $this->config['temperature'] ?? 0.2,
                'max_tokens' => $options['max_tokens'] ?? $this->config['max_tokens'] ?? 4096,
            ]);

        $response->throw();
        $data = $response->json();

        return new AiResponse(
            content: $data['choices'][0]['message']['content'] ?? '',
            provider: $this->name(),
            model: $data['model'] ?? $this->config['chat_model'] ?? 'unknown',
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
                'model' => $this->config['embeddings_model'] ?? 'gte-Qwen2',
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
            model: $data['model'] ?? ($this->config['embeddings_model'] ?? 'unknown'),
            totalTokens: $data['usage']['total_tokens'] ?? null,
        );
    }

    public function name(): string
    {
        return 'regolo';
    }

    public function supportsEmbeddings(): bool
    {
        return true;
    }
}
