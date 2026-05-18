<?php

namespace Tests\Unit\Ai;

use App\Ai\Providers\OpenRouterProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenRouterProviderTest extends TestCase
{
    private function config(array $overrides = []): array
    {
        return array_merge([
            'api_key' => 'sk-or-test',
            'base_url' => 'https://openrouter.ai/api/v1',
            'chat_model' => 'anthropic/claude-sonnet-4-20250514',
            'app_name' => 'Enterprise KB',
            'site_url' => 'https://kb.example.com',
            'temperature' => 0.2,
            'max_tokens' => 1024,
            'timeout' => 30,
        ], $overrides);
    }

    public function test_name_and_embedding_support(): void
    {
        $p = new OpenRouterProvider($this->config());
        $this->assertSame('openrouter', $p->name());
        $this->assertTrue($p->supportsEmbeddings());
    }

    public function test_chat_sends_referer_and_title_headers(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'model' => 'anthropic/claude-sonnet-4-20250514',
                'choices' => [[
                    'message' => ['content' => 'Hi there'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2, 'total_tokens' => 5],
            ], 200),
        ]);

        $p = new OpenRouterProvider($this->config());
        $res = $p->chat('sys', 'user');

        $this->assertSame('Hi there', $res->content);
        $this->assertSame('openrouter', $res->provider);
        $this->assertSame(5, $res->totalTokens);

        Http::assertSent(function (Request $req) {
            return $req->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && $req->hasHeader('Authorization', 'Bearer sk-or-test')
                && $req->hasHeader('HTTP-Referer', 'https://kb.example.com')
                && $req->hasHeader('X-Title', 'Enterprise KB');
        });
    }

    public function test_generate_embeddings_calls_openai_compatible_endpoint(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'model' => 'qwen/qwen3-embedding-4b',
                'data' => [
                    ['index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                    ['index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
                ],
                'usage' => ['total_tokens' => 12],
            ], 200),
        ]);

        $p = new OpenRouterProvider($this->config([
            'embeddings_model' => 'qwen/qwen3-embedding-4b',
        ]));

        $res = $p->generateEmbeddings(['hello', 'world']);

        $this->assertSame('openrouter', $res->provider);
        $this->assertSame('qwen/qwen3-embedding-4b', $res->model);
        $this->assertSame(12, $res->totalTokens);
        $this->assertCount(2, $res->embeddings);
        $this->assertSame([0.1, 0.2, 0.3], $res->embeddings[0]);

        Http::assertSent(function (Request $req) {
            return $req->url() === 'https://openrouter.ai/api/v1/embeddings'
                && $req->hasHeader('Authorization', 'Bearer sk-or-test')
                && $req->hasHeader('HTTP-Referer', 'https://kb.example.com')
                && $req->hasHeader('X-Title', 'Enterprise KB')
                && $req['model'] === 'qwen/qwen3-embedding-4b'
                && $req['input'] === ['hello', 'world'];
        });
    }

    public function test_generate_embeddings_uses_default_model_when_unset(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'model' => 'openai/text-embedding-3-small',
                'data' => [['index' => 0, 'embedding' => [0.5]]],
                'usage' => ['total_tokens' => 1],
            ], 200),
        ]);

        $cfg = $this->config();
        unset($cfg['embeddings_model']);
        $p = new OpenRouterProvider($cfg);

        $p->generateEmbeddings(['x']);

        Http::assertSent(fn (Request $req) => $req['model'] === 'openai/text-embedding-3-small');
    }

    public function test_generate_embeddings_preserves_input_order_via_index(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'model' => 'qwen/qwen3-embedding-4b',
                'data' => [
                    ['index' => 2, 'embedding' => [0.3]],
                    ['index' => 0, 'embedding' => [0.1]],
                    ['index' => 1, 'embedding' => [0.2]],
                ],
                'usage' => ['total_tokens' => 3],
            ], 200),
        ]);

        $p = new OpenRouterProvider($this->config());
        $res = $p->generateEmbeddings(['a', 'b', 'c']);

        $this->assertSame([[0.1], [0.2], [0.3]], $res->embeddings);
    }
}
