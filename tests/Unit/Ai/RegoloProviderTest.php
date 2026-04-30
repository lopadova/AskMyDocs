<?php

namespace Tests\Unit\Ai;

use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use App\Ai\Providers\RegoloProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AskMyDocs RegoloProvider — thin adapter over the laravel/ai SDK and
 * the padosoft/laravel-ai-regolo extension.
 *
 * Wire-level Regolo behaviour (request shape, retry, error mapping,
 * streaming, tool loop, etc.) is exhaustively covered by the SDK
 * extension's own test surface in `padosoft/laravel-ai-regolo` (see
 * `vendor/padosoft/laravel-ai-regolo/tests/Unit/Gateway/Regolo/` for
 * the current scenario inventory). The exact test / assertion count
 * is pinned in that package's README + CI sample-output block —
 * intentionally not duplicated here so a future SDK release does not
 * leave a stale number drifting in this comment.
 *
 * The tests here only pin the AskMyDocs adapter contract: that the
 * caller-facing `AiProviderInterface` keeps its existing shape and
 * that the SDK response is mapped onto the AskMyDocs `AiResponse` /
 * `EmbeddingsResponse` DTOs without dropping any field.
 */
class RegoloProviderTest extends TestCase
{
    private function setupConfig(array $overrides = []): void
    {
        config()->set('ai.providers.regolo', array_merge([
            'driver' => 'regolo',
            'name' => 'regolo',
            'key' => 'regolo-test-key',
            'url' => 'https://api.regolo.ai/v1',
            'timeout' => 30,
            'models' => [
                'text' => ['default' => 'Llama-3.3-70B-Instruct'],
                'embeddings' => ['default' => 'Qwen3-Embedding-8B', 'dimensions' => 4096],
                'reranking' => ['default' => 'jina-reranker-v2'],
            ],
        ], $overrides));
    }

    public function test_name_and_embedding_support(): void
    {
        $this->setupConfig();
        $p = new RegoloProvider(config('ai.providers.regolo'));

        $this->assertSame('regolo', $p->name());
        $this->assertTrue($p->supportsEmbeddings());
    }

    public function test_chat_returns_ai_response_with_text_and_metadata(): void
    {
        $this->setupConfig();
        Http::fake([
            'api.regolo.ai/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-1',
                'object' => 'chat.completion',
                'created' => 1745846400,
                'model' => 'Llama-3.3-70B-Instruct',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Ciao!'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 2, 'total_tokens' => 9],
            ], 200),
        ]);

        $p = new RegoloProvider(config('ai.providers.regolo'));
        $res = $p->chat('You are helpful.', 'Hi');

        $this->assertInstanceOf(AiResponse::class, $res);
        $this->assertSame('Ciao!', $res->content);
        $this->assertSame('regolo', $res->provider);
        $this->assertSame('Llama-3.3-70B-Instruct', $res->model);
        $this->assertSame(7, $res->promptTokens);
        $this->assertSame(2, $res->completionTokens);
        $this->assertSame(9, $res->totalTokens);
        $this->assertSame('stop', $res->finishReason);
    }

    public function test_chat_with_history_propagates_message_order_to_sdk(): void
    {
        $this->setupConfig();
        Http::fake([
            'api.regolo.ai/v1/chat/completions' => Http::response([
                'model' => 'Llama-3.3-70B-Instruct',
                'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ], 200),
        ]);

        $p = new RegoloProvider(config('ai.providers.regolo'));
        $p->chatWithHistory('sys', [
            ['role' => 'user', 'content' => 'q1'],
            ['role' => 'assistant', 'content' => 'a1'],
            ['role' => 'user', 'content' => 'q2'],
        ]);

        Http::assertSent(function (\Illuminate\Http\Client\Request $req) {
            $msgs = $req->data()['messages'];
            return count($msgs) === 4
                && $msgs[0]['role'] === 'system'
                && $msgs[0]['content'] === 'sys'
                && $msgs[1]['role'] === 'user'
                && $msgs[1]['content'] === 'q1'
                && $msgs[2]['role'] === 'assistant'
                && $msgs[2]['content'] === 'a1'
                && $msgs[3]['role'] === 'user'
                && $msgs[3]['content'] === 'q2';
        });
    }

    public function test_chat_options_model_override(): void
    {
        $this->setupConfig();
        Http::fake([
            'api.regolo.ai/v1/chat/completions' => Http::response([
                'model' => 'Llama-3.1-8B-Instruct',
                'choices' => [['message' => ['content' => 'x'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ], 200),
        ]);

        $p = new RegoloProvider(config('ai.providers.regolo'));
        $p->chat('s', 'u', ['model' => 'Llama-3.1-8B-Instruct']);

        Http::assertSent(fn (\Illuminate\Http\Client\Request $req) => $req->data()['model'] === 'Llama-3.1-8B-Instruct');
    }

    public function test_generate_embeddings_returns_response_with_vectors(): void
    {
        $this->setupConfig();
        Http::fake([
            'api.regolo.ai/v1/embeddings' => Http::response([
                'object' => 'list',
                'model' => 'Qwen3-Embedding-8B',
                'data' => [
                    ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2]],
                    ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5]],
                ],
                'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
            ], 200),
        ]);

        $p = new RegoloProvider(config('ai.providers.regolo'));
        $res = $p->generateEmbeddings(['first', 'second']);

        $this->assertInstanceOf(EmbeddingsResponse::class, $res);
        $this->assertSame([[0.1, 0.2], [0.4, 0.5]], $res->embeddings);
        $this->assertSame('regolo', $res->provider);
        $this->assertSame('Qwen3-Embedding-8B', $res->model);
        $this->assertSame(5, $res->totalTokens);
    }

    public function test_chat_with_history_rejects_empty_message_list(): void
    {
        $this->setupConfig();
        $this->expectException(\InvalidArgumentException::class);

        (new RegoloProvider(config('ai.providers.regolo')))->chatWithHistory('s', []);
    }

    public function test_chat_with_history_rejects_non_user_last_message(): void
    {
        $this->setupConfig();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chatWithHistory requires the last message to have role="user"; got role="assistant".');

        // Sending a history that ends with an assistant turn would make
        // the SDK treat the assistant's previous reply as the new user
        // prompt — at best a confusing model output, at worst a prompt-
        // injection surface. The provider must refuse the input
        // explicitly so the bug surfaces at the call site.
        (new RegoloProvider(config('ai.providers.regolo')))->chatWithHistory('s', [
            ['role' => 'user', 'content' => 'Ciao.'],
            ['role' => 'assistant', 'content' => 'Salve, in che cosa posso aiutarti?'],
        ]);
    }

    public function test_respects_custom_base_url_via_config_url(): void
    {
        $this->setupConfig(['url' => 'https://custom.regolo.example/v1']);
        Http::fake([
            'custom.regolo.example/v1/chat/completions' => Http::response([
                'model' => 'X',
                'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ], 200),
        ]);

        $p = new RegoloProvider(config('ai.providers.regolo'));
        $p->chat('s', 'u');

        Http::assertSent(fn (\Illuminate\Http\Client\Request $req) => str_starts_with($req->url(), 'https://custom.regolo.example/v1/'));
    }

    public function test_chat_with_history_rejects_unsupported_role_in_history(): void
    {
        $this->setupConfig();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported message role');

        // The provider only accepts user/assistant roles in the history
        // tail because the laravel/ai SDK shape carries `system` via the
        // dedicated `instructions` argument, not as a history message.
        // A `system` (or `tool`) entry slipped into the history must
        // surface loudly at the adapter boundary so the misuse never
        // reaches the wire as a malformed message list.
        (new RegoloProvider(config('ai.providers.regolo')))->chatWithHistory('s', [
            ['role' => 'system', 'content' => 'should-not-be-here'],
            ['role' => 'user', 'content' => 'Hi'],
        ]);
    }

    public function test_chat_with_history_rejects_last_message_missing_content(): void
    {
        $this->setupConfig();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty string "content"');

        // A malformed last message (missing `content` key) would otherwise
        // emit a PHP "undefined array key" notice and feed null to the
        // SDK's `prompt(string)` argument, raising a TypeError far from
        // the adapter boundary. The guard turns it into a deterministic
        // `InvalidArgumentException` callers can catch.
        (new RegoloProvider(config('ai.providers.regolo')))->chatWithHistory('s', [
            ['role' => 'user'],
        ]);
    }

    public function test_chat_with_history_rejects_history_entry_missing_content(): void
    {
        $this->setupConfig();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty string "content"');

        // Same shape guard for entries earlier in the history (mapped
        // through `mapHistoryToSdkMessages()`). Without the guard the
        // map() callable raised a "undefined array key" warning before
        // failing — the new behaviour is a single clear exception.
        (new RegoloProvider(config('ai.providers.regolo')))->chatWithHistory('s', [
            ['role' => 'user'], // missing content
            ['role' => 'user', 'content' => 'q'],
        ]);
    }

    public function test_chat_options_max_tokens_and_temperature_propagate_to_request(): void
    {
        $this->setupConfig();
        Http::fake([
            'api.regolo.ai/v1/chat/completions' => Http::response([
                'model' => 'Llama-3.3-70B-Instruct',
                'choices' => [['message' => ['content' => 'short'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ], 200),
        ]);

        // Per-call overrides MUST propagate end-to-end:
        //   RegoloProvider::chat($options)
        //     → RegoloAnonymousAgent::maxTokens()/temperature()
        //     → Laravel\Ai\Gateway\TextGenerationOptions::forAgent()
        //     → padosoft/laravel-ai-regolo BuildsTextRequests::buildTextRequest()
        //     → wire `body['max_tokens']` / `body['temperature']`.
        // ConversationController::generateTitle relies on this — capping
        // titles at 60 tokens. A regression here silently hands the
        // provider's own default (4096) to Regolo.
        $p = new RegoloProvider(config('ai.providers.regolo'));
        $p->chat('s', 'u', ['max_tokens' => 60, 'temperature' => 0.7]);

        Http::assertSent(function (\Illuminate\Http\Client\Request $req) {
            $body = $req->data();

            return ($body['max_tokens'] ?? null) === 60
                && ($body['temperature'] ?? null) === 0.7;
        });
    }
}
