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
 * streaming, tool loop, etc.) is exhaustively covered by the 47 unit
 * tests in `padosoft/laravel-ai-regolo` (see
 * `vendor/padosoft/laravel-ai-regolo/tests/Unit/Gateway/Regolo/`).
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
}
