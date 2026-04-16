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

    public function test_name_and_no_embedding_support(): void
    {
        $p = new OpenRouterProvider($this->config());
        $this->assertSame('openrouter', $p->name());
        $this->assertFalse($p->supportsEmbeddings());
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

    public function test_generate_embeddings_throws(): void
    {
        $p = new OpenRouterProvider($this->config());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/OpenRouter does not support embeddings/i');

        $p->generateEmbeddings(['x']);
    }
}
