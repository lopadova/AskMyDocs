<?php

namespace Tests\Unit\Ai;

use App\Ai\AiResponse;
use App\Ai\Providers\AnthropicProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AskMyDocs AnthropicProvider — thin adapter over the laravel/ai SDK
 * (native `anthropic` driver), migrated off raw Http:: in v8.16/W2.
 *
 * Wire-level Anthropic behaviour (request shape, retry, error mapping) is
 * owned by the SDK's Anthropic gateway. These tests pin the AskMyDocs adapter
 * contract: the caller-facing `AiProviderInterface` keeps its shape and the SDK
 * response maps onto `AiResponse` without dropping a field. The SDK calls the
 * Anthropic API through Illuminate's HTTP client, so `Http::fake()` intercepts
 * it exactly as for the legacy provider.
 */
class AnthropicProviderTest extends TestCase
{
    private function setupConfig(array $overrides = []): void
    {
        config()->set('ai.providers.anthropic', array_merge([
            'driver' => 'anthropic',
            'name' => 'anthropic',
            'key' => 'sk-ant-test',
            'url' => 'https://api.anthropic.com/v1',
            'api_version' => '2023-06-01',
            'timeout' => 30,
            'temperature' => 0.2,
            'max_tokens' => 2048,
            'models' => [
                'text' => ['default' => 'claude-sonnet-4-20250514'],
            ],
        ], $overrides));
    }

    public function test_name_and_no_embedding_support(): void
    {
        $this->setupConfig();
        $p = new AnthropicProvider(config('ai.providers.anthropic'));

        $this->assertSame('anthropic', $p->name());
        $this->assertFalse($p->supportsEmbeddings());
    }

    public function test_chat_returns_ai_response_with_text_and_metadata(): void
    {
        $this->setupConfig();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-20250514',
                'content' => [
                    ['type' => 'text', 'text' => 'Hello'],
                    ['type' => 'text', 'text' => ' world.'],
                ],
                'usage' => ['input_tokens' => 20, 'output_tokens' => 8],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $p = new AnthropicProvider(config('ai.providers.anthropic'));
        $res = $p->chat('You are helpful.', 'Hi');

        $this->assertInstanceOf(AiResponse::class, $res);
        $this->assertSame('Hello world.', $res->content);
        $this->assertSame('anthropic', $res->provider);
        $this->assertSame('claude-sonnet-4-20250514', $res->model);
        $this->assertSame(20, $res->promptTokens);
        $this->assertSame(8, $res->completionTokens);
        $this->assertSame(28, $res->totalTokens);
    }

    public function test_generate_embeddings_throws(): void
    {
        $this->setupConfig();
        $p = new AnthropicProvider(config('ai.providers.anthropic'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not provide an embeddings API/i');

        $p->generateEmbeddings(['any']);
    }

    public function test_chat_with_history_maps_multi_turn_response(): void
    {
        $this->setupConfig();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-20250514',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $p = new AnthropicProvider(config('ai.providers.anthropic'));
        $res = $p->chatWithHistory('sys', [
            ['role' => 'user', 'content' => 'q1'],
            ['role' => 'assistant', 'content' => 'a1'],
            ['role' => 'user', 'content' => 'q2'],
        ]);

        $this->assertSame('ok', $res->content);
        $this->assertSame(5, $res->promptTokens);
        $this->assertSame(2, $res->completionTokens);
    }

    public function test_chat_with_history_rejects_empty_message_list(): void
    {
        $this->setupConfig();
        $this->expectException(\InvalidArgumentException::class);

        (new AnthropicProvider(config('ai.providers.anthropic')))->chatWithHistory('s', []);
    }

    public function test_chat_with_history_rejects_non_user_last_message(): void
    {
        $this->setupConfig();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chatWithHistory requires the last message to have role="user"; got role="assistant".');

        (new AnthropicProvider(config('ai.providers.anthropic')))->chatWithHistory('s', [
            ['role' => 'user', 'content' => 'Hi.'],
            ['role' => 'assistant', 'content' => 'Hello.'],
        ]);
    }

    public function test_chat_with_history_rejects_unsupported_role(): void
    {
        $this->setupConfig();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported message role');

        (new AnthropicProvider(config('ai.providers.anthropic')))->chatWithHistory('s', [
            ['role' => 'system', 'content' => 'nope'],
            ['role' => 'user', 'content' => 'Hi'],
        ]);
    }

    public function test_chat_rejects_non_numeric_max_tokens(): void
    {
        $this->setupConfig();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max_tokens must be numeric');

        (new AnthropicProvider(config('ai.providers.anthropic')))->chat('s', 'u', ['max_tokens' => 'abc']);
    }

    public function test_chat_rejects_non_numeric_temperature(): void
    {
        $this->setupConfig();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('temperature must be numeric');

        (new AnthropicProvider(config('ai.providers.anthropic')))->chat('s', 'u', ['temperature' => 'hot']);
    }

    public function test_chat_stream_via_fallback_emits_text_envelope_then_finish(): void
    {
        // chatStream() delegates to FallbackStreaming::streamFromChat() → the new
        // SDK chatViaSdk() path → re-emits the response as one SDK v6 text
        // envelope (text-start, text-delta, text-end) + one finish chunk. This
        // pins that the SDK migration did NOT break the streaming envelope shape.
        $this->setupConfig();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-20250514',
                'content' => [['type' => 'text', 'text' => 'Streamed reply']],
                'usage' => ['input_tokens' => 12, 'output_tokens' => 7],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $p = new AnthropicProvider(config('ai.providers.anthropic'));
        $chunks = iterator_to_array($p->chatStream('SYS', [
            ['role' => 'user', 'content' => 'Hi'],
        ]), preserve_keys: false);

        $this->assertCount(4, $chunks, 'fallback streaming yields text-start + text-delta + text-end + finish');
        $this->assertSame('text-start', $chunks[0]->type);
        $this->assertSame('text-delta', $chunks[1]->type);
        $this->assertSame('text-end', $chunks[2]->type);
        $this->assertSame('finish', $chunks[3]->type);

        // Text envelope MUST share one id end-to-end so SDK v6 stitches the
        // deltas into one rendered text part.
        $textId = $chunks[0]->payload['id'];
        $this->assertSame($textId, $chunks[1]->payload['id']);
        $this->assertSame($textId, $chunks[2]->payload['id']);

        // SDK v6 shape: text-delta carries `delta` (NOT `textDelta`).
        $this->assertSame('Streamed reply', $chunks[1]->payload['delta']);

        // Anthropic `end_turn` normalizes to the SDK union `'stop'`.
        $this->assertSame('stop', $chunks[3]->payload['finishReason']);
        $this->assertSame(12, $chunks[3]->payload['usage']['promptTokens']);
        $this->assertSame(7, $chunks[3]->payload['usage']['completionTokens']);
    }

    public function test_chat_stream_with_empty_content_emits_only_finish_chunk(): void
    {
        // Edge case (regression guard re-added after the SDK migration, Copilot R2):
        // FallbackStreaming::streamFromChat() skips the whole text envelope when the
        // provider returns empty content (`if ($response->content !== '')`), so an
        // empty Anthropic response must yield ONLY a single `finish` chunk — never an
        // empty `text-start`/`text-delta`/`text-end` that renders as a blank bubble.
        $this->setupConfig();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-20250514',
                'content' => [], // no text blocks → SDK text === ''
                'usage' => ['input_tokens' => 4, 'output_tokens' => 0],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $p = new AnthropicProvider(config('ai.providers.anthropic'));
        $chunks = iterator_to_array($p->chatStream('SYS', [
            ['role' => 'user', 'content' => 'Hi'],
        ]), preserve_keys: false);

        $this->assertCount(1, $chunks, 'empty content yields only the finish chunk (no text envelope)');
        $this->assertSame('finish', $chunks[0]->type);
        $this->assertSame('stop', $chunks[0]->payload['finishReason']);
    }
}
