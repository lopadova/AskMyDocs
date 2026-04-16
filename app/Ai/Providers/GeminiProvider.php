<?php

namespace App\Ai\Providers;

use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use Illuminate\Support\Facades\Http;

final class GeminiProvider implements AiProviderInterface
{
    private string $baseUrl;

    public function __construct(private readonly array $config)
    {
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta', '/');
    }

    public function chat(string $systemPrompt, string $userMessage, array $options = []): AiResponse
    {
        return $this->chatWithHistory($systemPrompt, [
            ['role' => 'user', 'content' => $userMessage],
        ], $options);
    }

    public function chatWithHistory(string $systemPrompt, array $messages, array $options = []): AiResponse
    {
        $model = $options['model'] ?? $this->config['chat_model'] ?? 'gemini-2.0-flash';

        // Gemini uses "model" instead of "assistant" for the role
        $contents = [];
        foreach ($messages as $msg) {
            $contents[] = [
                'role' => $msg['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $msg['content']]],
            ];
        }

        $response = Http::timeout($this->config['timeout'] ?? 120)
            ->post("{$this->baseUrl}/models/{$model}:generateContent?key={$this->config['api_key']}", [
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => $options['temperature'] ?? $this->config['temperature'] ?? 0.2,
                    'maxOutputTokens' => $options['max_tokens'] ?? $this->config['max_tokens'] ?? 4096,
                ],
            ]);

        $response->throw();
        $data = $response->json();

        return new AiResponse(
            content: data_get($data, 'candidates.0.content.parts.0.text', ''),
            provider: $this->name(),
            model: $model,
            promptTokens: data_get($data, 'usageMetadata.promptTokenCount'),
            completionTokens: data_get($data, 'usageMetadata.candidatesTokenCount'),
            totalTokens: data_get($data, 'usageMetadata.totalTokenCount'),
            finishReason: data_get($data, 'candidates.0.finishReason'),
        );
    }

    public function generateEmbeddings(array $texts): EmbeddingsResponse
    {
        $model = $this->config['embeddings_model'] ?? 'text-embedding-004';

        $requests = array_map(fn (string $text) => [
            'model' => "models/{$model}",
            'content' => ['parts' => [['text' => $text]]],
        ], $texts);

        $response = Http::timeout($this->config['timeout'] ?? 120)
            ->post("{$this->baseUrl}/models/{$model}:batchEmbedContents?key={$this->config['api_key']}", [
                'requests' => $requests,
            ]);

        $response->throw();
        $data = $response->json();

        return new EmbeddingsResponse(
            embeddings: collect($data['embeddings'] ?? [])->pluck('values')->all(),
            provider: $this->name(),
            model: $model,
        );
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function supportsEmbeddings(): bool
    {
        return true;
    }
}
