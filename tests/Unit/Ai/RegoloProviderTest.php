<?php

namespace Tests\Unit\Ai;

use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use App\Ai\Providers\RegoloProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegoloProviderTest extends TestCase
{
    private function config(array $overrides = []): array
    {
        return array_merge([
            'api_key' => 'regolo-test-key',
            'base_url' => 'https://api.regolo.ai/v1',
            'chat_model' => 'Llama-3.3-70B-Instruct',
            'embeddings_model' => 'gte-Qwen2',
            'temperature' => 0.2,
            'max_tokens' => 1024,
            'timeout' => 30,
        ], $overrides);
    }

    public function test_name_and_embedding_support(): void
    {
        $p = new RegoloProvider($this->config());
        $this->assertSame('regolo', $p->name());
        $this->assertTrue($p->supportsEmbeddings());
    }

    public function test_chat_posts_openai_compatible_body(): void
    {
        Http::fake([
            'api.regolo.ai/*' => Http::response([
                'model' => 'Llama-3.3-70B-Instruct',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Ciao!'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 7,
                    'completion_tokens' => 2,
                    'total_tokens' => 9,
                ],
            ], 200),
        ]);

        $p = new RegoloProvider($this->config());
        $res = $p->chat('You are helpful.', 'Hi');

        $this->assertInstanceOf(AiResponse::class, $res);
        $this->assertSame('Ciao!', $res->content);
        $this->assertSame('regolo', $res->provider);
        $this->assertSame('Llama-3.3-70B-Instruct', $res->model);
        $this->assertSame(9, $res->totalTokens);
        $this->assertSame('stop', $res->finishReason);

        Http::assertSent(function (Request $req) {
            $body = $req->data();
            return $req->url() === 'https://api.regolo.ai/v1/chat/completions'
                && $req->hasHeader('Authorization', 'Bearer regolo-test-key')
                && $body['model'] === 'Llama-3.3-70B-Instruct'
                && $body['messages'][0] === ['role' => 'system', 'content' => 'You are helpful.']
                && $body['messages'][1] === ['role' => 'user', 'content' => 'Hi'];
        });
    }

    public function test_chat_with_history_prepends_system_and_keeps_turn_order(): void
    {
        Http::fake(['*' => Http::response(['model' => 'X', 'choices' => [['message' => ['content' => 'ok']]]])]);

        $p = new RegoloProvider($this->config());
        $p->chatWithHistory('sys', [
            ['role' => 'user', 'content' => 'q1'],
            ['role' => 'assistant', 'content' => 'a1'],
            ['role' => 'user', 'content' => 'q2'],
        ]);

        Http::assertSent(function (Request $req) {
            $msgs = $req->data()['messages'];
            return count($msgs) === 4
                && $msgs[0]['role'] === 'system'
                && $msgs[1]['content'] === 'q1'
                && $msgs[2]['role'] === 'assistant'
                && $msgs[3]['content'] === 'q2';
        });
    }

    public function test_chat_options_override_config(): void
    {
        Http::fake(['*' => Http::response(['model' => 'other', 'choices' => [['message' => ['content' => 'x']]]])]);

        $p = new RegoloProvider($this->config());
        $p->chat('s', 'u', ['model' => 'Qwen3-Embedding-8B', 'temperature' => 0.9, 'max_tokens' => 256]);

        Http::assertSent(function (Request $req) {
            $body = $req->data();
            return $body['model'] === 'Qwen3-Embedding-8B'
                && $body['temperature'] === 0.9
                && $body['max_tokens'] === 256;
        });
    }

    public function test_generate_embeddings_sorts_by_index(): void
    {
        Http::fake([
            'api.regolo.ai/*' => Http::response([
                'model' => 'gte-Qwen2',
                'data' => [
                    ['index' => 1, 'embedding' => [0.4, 0.5]],
                    ['index' => 0, 'embedding' => [0.1, 0.2]],
                ],
                'usage' => ['total_tokens' => 5],
            ], 200),
        ]);

        $p = new RegoloProvider($this->config());
        $res = $p->generateEmbeddings(['first', 'second']);

        $this->assertInstanceOf(EmbeddingsResponse::class, $res);
        $this->assertSame([[0.1, 0.2], [0.4, 0.5]], $res->embeddings);
        $this->assertSame('gte-Qwen2', $res->model);
        $this->assertSame(5, $res->totalTokens);

        Http::assertSent(fn (Request $req) => $req->url() === 'https://api.regolo.ai/v1/embeddings'
            && $req->data()['model'] === 'gte-Qwen2'
            && $req->data()['input'] === ['first', 'second']);
    }

    public function test_throws_on_http_error(): void
    {
        Http::fake(['*' => Http::response(['error' => 'rate-limited'], 429)]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);
        (new RegoloProvider($this->config()))->chat('s', 'u');
    }

    public function test_respects_custom_base_url(): void
    {
        Http::fake(['custom.regolo.example/*' => Http::response(['model' => 'X', 'choices' => [['message' => ['content' => 'ok']]]])]);

        $p = new RegoloProvider($this->config(['base_url' => 'https://custom.regolo.example/v1']));
        $p->chat('s', 'u');

        Http::assertSent(fn (Request $req) => str_starts_with($req->url(), 'https://custom.regolo.example/v1/'));
    }
}
