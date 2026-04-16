<?php

namespace Tests\Unit\Ai;

use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use App\Ai\Providers\OpenAiProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiProviderTest extends TestCase
{
    private function config(array $overrides = []): array
    {
        return array_merge([
            'api_key' => 'sk-test',
            'base_url' => 'https://api.openai.com/v1',
            'chat_model' => 'gpt-4o',
            'embeddings_model' => 'text-embedding-3-small',
            'temperature' => 0.2,
            'max_tokens' => 1024,
            'timeout' => 30,
        ], $overrides);
    }

    public function test_name_and_embedding_support(): void
    {
        $p = new OpenAiProvider($this->config());
        $this->assertSame('openai', $p->name());
        $this->assertTrue($p->supportsEmbeddings());
    }

    public function test_chat_posts_to_chat_completions_with_system_and_user(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'gpt-4o',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Hello!'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 4,
                    'total_tokens' => 14,
                ],
            ], 200),
        ]);

        $p = new OpenAiProvider($this->config());
        $res = $p->chat('You are helpful.', 'Hi');

        $this->assertInstanceOf(AiResponse::class, $res);
        $this->assertSame('Hello!', $res->content);
        $this->assertSame('openai', $res->provider);
        $this->assertSame('gpt-4o', $res->model);
        $this->assertSame(10, $res->promptTokens);
        $this->assertSame(4, $res->completionTokens);
        $this->assertSame(14, $res->totalTokens);
        $this->assertSame('stop', $res->finishReason);

        Http::assertSent(function (Request $req) {
            $body = $req->data();
            return $req->url() === 'https://api.openai.com/v1/chat/completions'
                && $req->hasHeader('Authorization', 'Bearer sk-test')
                && $body['model'] === 'gpt-4o'
                && $body['messages'][0] === ['role' => 'system', 'content' => 'You are helpful.']
                && $body['messages'][1] === ['role' => 'user', 'content' => 'Hi'];
        });
    }

    public function test_chat_with_history_forwards_all_messages(): void
    {
        Http::fake(['*' => Http::response(['model' => 'gpt-4o', 'choices' => [['message' => ['content' => 'ok']]]])]);

        $p = new OpenAiProvider($this->config());
        $p->chatWithHistory('sys', [
            ['role' => 'user', 'content' => 'q1'],
            ['role' => 'assistant', 'content' => 'a1'],
            ['role' => 'user', 'content' => 'q2'],
        ]);

        Http::assertSent(function (Request $req) {
            $msgs = $req->data()['messages'];
            return count($msgs) === 4
                && $msgs[0]['role'] === 'system'
                && $msgs[1]['role'] === 'user'
                && $msgs[2]['role'] === 'assistant'
                && $msgs[3]['content'] === 'q2';
        });
    }

    public function test_chat_overrides_via_options(): void
    {
        Http::fake(['*' => Http::response(['model' => 'gpt-4o-mini', 'choices' => [['message' => ['content' => 'x']]]])]);

        $p = new OpenAiProvider($this->config());
        $p->chat('s', 'u', ['model' => 'gpt-4o-mini', 'temperature' => 0.9, 'max_tokens' => 256]);

        Http::assertSent(function (Request $req) {
            $body = $req->data();
            return $body['model'] === 'gpt-4o-mini'
                && $body['temperature'] === 0.9
                && $body['max_tokens'] === 256;
        });
    }

    public function test_generate_embeddings_sorts_by_index(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'text-embedding-3-small',
                'data' => [
                    ['index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
                    ['index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                ],
                'usage' => ['total_tokens' => 12],
            ], 200),
        ]);

        $p = new OpenAiProvider($this->config());
        $res = $p->generateEmbeddings(['first', 'second']);

        $this->assertInstanceOf(EmbeddingsResponse::class, $res);
        $this->assertSame([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]], $res->embeddings);
        $this->assertSame('text-embedding-3-small', $res->model);
        $this->assertSame(12, $res->totalTokens);
    }

    public function test_throws_on_http_error(): void
    {
        Http::fake(['*' => Http::response(['error' => 'quota'], 429)]);

        $p = new OpenAiProvider($this->config());

        $this->expectException(\Illuminate\Http\Client\RequestException::class);
        $p->chat('s', 'u');
    }
}
