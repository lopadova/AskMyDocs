<?php

namespace Tests\Unit\Ai;

use App\Ai\Providers\GeminiProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiProviderTest extends TestCase
{
    private function config(array $overrides = []): array
    {
        return array_merge([
            'api_key' => 'AIzaTest',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'chat_model' => 'gemini-2.0-flash',
            'embeddings_model' => 'text-embedding-004',
            'temperature' => 0.3,
            'max_tokens' => 512,
            'timeout' => 30,
        ], $overrides);
    }

    public function test_name_and_embedding_support(): void
    {
        $p = new GeminiProvider($this->config());
        $this->assertSame('gemini', $p->name());
        $this->assertTrue($p->supportsEmbeddings());
    }

    public function test_chat_translates_assistant_role_to_model(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Ciao!']]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 11,
                    'candidatesTokenCount' => 3,
                    'totalTokenCount' => 14,
                ],
            ], 200),
        ]);

        $p = new GeminiProvider($this->config());
        $res = $p->chatWithHistory('sys', [
            ['role' => 'user', 'content' => 'q1'],
            ['role' => 'assistant', 'content' => 'a1'],
            ['role' => 'user', 'content' => 'q2'],
        ]);

        $this->assertSame('Ciao!', $res->content);
        $this->assertSame(11, $res->promptTokens);
        $this->assertSame(3, $res->completionTokens);
        $this->assertSame(14, $res->totalTokens);
        $this->assertSame('STOP', $res->finishReason);
        $this->assertSame('gemini-2.0-flash', $res->model);

        Http::assertSent(function (Request $req) {
            $body = $req->data();
            // system instruction present + roles translated
            return $body['system_instruction']['parts'][0]['text'] === 'sys'
                && $body['contents'][0]['role'] === 'user'
                && $body['contents'][1]['role'] === 'model'
                && $body['contents'][2]['role'] === 'user'
                && str_contains($req->url(), 'models/gemini-2.0-flash:generateContent')
                && str_contains($req->url(), 'key=AIzaTest');
        });
    }

    public function test_generate_embeddings_batches_into_requests(): void
    {
        Http::fake([
            '*' => Http::response([
                'embeddings' => [
                    ['values' => [0.1, 0.2]],
                    ['values' => [0.3, 0.4]],
                ],
            ], 200),
        ]);

        $p = new GeminiProvider($this->config());
        $res = $p->generateEmbeddings(['one', 'two']);

        $this->assertSame([[0.1, 0.2], [0.3, 0.4]], $res->embeddings);
        $this->assertSame('text-embedding-004', $res->model);

        Http::assertSent(function (Request $req) {
            $body = $req->data();
            return str_contains($req->url(), ':batchEmbedContents')
                && count($body['requests']) === 2
                && $body['requests'][0]['content']['parts'][0]['text'] === 'one';
        });
    }

    public function test_returns_empty_content_if_response_malformed(): void
    {
        Http::fake(['*' => Http::response(['candidates' => []], 200)]);

        $p = new GeminiProvider($this->config());
        $res = $p->chat('sys', 'user');

        $this->assertSame('', $res->content);
    }
}
