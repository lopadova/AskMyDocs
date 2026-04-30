<?php

namespace App\Ai\Providers;

use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use App\Ai\Providers\Concerns\FallbackStreaming;
use Illuminate\Support\Facades\Http;

final class AnthropicProvider implements AiProviderInterface
{
    use FallbackStreaming;

    public function __construct(private readonly array $config) {}

    public function chat(string $systemPrompt, string $userMessage, array $options = []): AiResponse
    {
        return $this->chatWithHistory($systemPrompt, [
            ['role' => 'user', 'content' => $userMessage],
        ], $options);
    }

    public function chatWithHistory(string $systemPrompt, array $messages, array $options = []): AiResponse
    {
        $apiMessages = [];

        foreach ($messages as $msg) {
            $apiMessages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        $response = Http::withHeaders([
                'x-api-key' => $this->config['api_key'],
                'anthropic-version' => $this->config['api_version'] ?? '2023-06-01',
                'Content-Type' => 'application/json',
            ])
            ->timeout($this->config['timeout'] ?? 120)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $options['model'] ?? $this->config['chat_model'] ?? 'claude-sonnet-4-20250514',
                'max_tokens' => $options['max_tokens'] ?? $this->config['max_tokens'] ?? 4096,
                'system' => $systemPrompt,
                'messages' => $apiMessages,
                'temperature' => $options['temperature'] ?? $this->config['temperature'] ?? 0.2,
            ]);

        $response->throw();
        $data = $response->json();

        $content = collect($data['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        $inputTokens = $data['usage']['input_tokens'] ?? 0;
        $outputTokens = $data['usage']['output_tokens'] ?? 0;

        return new AiResponse(
            content: $content,
            provider: $this->name(),
            model: $data['model'] ?? $this->config['chat_model'],
            promptTokens: $inputTokens ?: null,
            completionTokens: $outputTokens ?: null,
            totalTokens: ($inputTokens + $outputTokens) ?: null,
            finishReason: $data['stop_reason'] ?? null,
        );
    }

    public function chatStream(string $systemPrompt, array $messages, array $options = []): \Generator
    {
        // Anthropic supports SSE streaming natively (`stream: true`),
        // but we wire the fallback for now — token-by-token rendering
        // is a follow-up enhancement. The fallback emits the full
        // assistant response as a single text-delta + finish, which
        // the Vercel SDK on the FE renders identically to a complete
        // synchronous response.
        return $this->streamFromChat($systemPrompt, $messages, $options);
    }

    public function generateEmbeddings(array $texts): EmbeddingsResponse
    {
        throw new \RuntimeException(
            'Anthropic does not provide an embeddings API. '
            . 'Configure AI_EMBEDDINGS_PROVIDER (e.g. openai, gemini).'
        );
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function supportsEmbeddings(): bool
    {
        return false;
    }
}
