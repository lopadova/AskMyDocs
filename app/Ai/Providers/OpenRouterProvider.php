<?php

namespace App\Ai\Providers;

use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use Illuminate\Support\Facades\Http;

final class OpenRouterProvider implements AiProviderInterface
{
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
            $apiMessages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        $response = Http::withToken($this->config['api_key'])
            ->withHeaders([
                'HTTP-Referer' => $this->config['site_url'] ?? config('app.url', ''),
                'X-Title' => $this->config['app_name'] ?? config('app.name', 'Enterprise KB'),
            ])
            ->timeout($this->config['timeout'] ?? 120)
            ->post("{$this->baseUrl}/chat/completions", [
                'model' => $options['model'] ?? $this->config['chat_model'] ?? 'anthropic/claude-sonnet-4-20250514',
                'messages' => $apiMessages,
                'temperature' => $options['temperature'] ?? $this->config['temperature'] ?? 0.2,
                'max_tokens' => $options['max_tokens'] ?? $this->config['max_tokens'] ?? 4096,
            ]);

        $response->throw();
        $data = $response->json();

        return new AiResponse(
            content: $data['choices'][0]['message']['content'] ?? '',
            provider: $this->name(),
            model: $data['model'] ?? $this->config['chat_model'],
            promptTokens: $data['usage']['prompt_tokens'] ?? null,
            completionTokens: $data['usage']['completion_tokens'] ?? null,
            totalTokens: $data['usage']['total_tokens'] ?? null,
            finishReason: $data['choices'][0]['finish_reason'] ?? null,
        );
    }

    public function generateEmbeddings(array $texts): EmbeddingsResponse
    {
        throw new \RuntimeException(
            'OpenRouter does not support embeddings. '
            . 'Configure AI_EMBEDDINGS_PROVIDER (e.g. openai, gemini).'
        );
    }

    public function name(): string
    {
        return 'openrouter';
    }

    public function supportsEmbeddings(): bool
    {
        return false;
    }
}
