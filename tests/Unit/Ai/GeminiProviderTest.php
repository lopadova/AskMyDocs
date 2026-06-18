<?php

namespace Tests\Unit\Ai;

use App\Ai\AiResponse;
use App\Ai\Providers\GeminiProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AskMyDocs GeminiProvider — thin adapter over the laravel/ai SDK
 * (native `gemini` driver), migrated off raw Http:: in v8.16/W2.
 *
 * Wire-level Gemini behaviour (the assistant→model role remap, the
 * x-goog-api-key header auth, generateContent / batchEmbedContents) is owned by
 * the SDK's Gemini gateway. These tests pin the AskMyDocs adapter contract: the
 * caller-facing `AiProviderInterface` keeps its shape, the SDK responses map
 * onto the AskMyDocs DTOs, and the R-logging-security invariant (key in HEADER,
 * never the URL) survives the migration. `Http::fake()` intercepts the SDK's
 * wire call, which uses the same Google API endpoints as the legacy provider.
 */
class GeminiProviderTest extends TestCase
{
    private function setupConfig(array $overrides = []): void
    {
        config()->set('ai.providers.gemini', array_merge([
            'driver' => 'gemini',
            'name' => 'gemini',
            'key' => 'AIzaTest',
            'url' => 'https://generativelanguage.googleapis.com/v1beta/',
            'timeout' => 30,
            'temperature' => 0.3,
            'max_tokens' => 512,
            'models' => [
                'text' => ['default' => 'gemini-2.0-flash'],
                'embeddings' => ['default' => 'text-embedding-004'],
            ],
        ], $overrides));
    }

    public function test_name_and_embedding_support(): void
    {
        $this->setupConfig();
        $p = new GeminiProvider(config('ai.providers.gemini'));

        $this->assertSame('gemini', $p->name());
        $this->assertTrue($p->supportsEmbeddings());
    }

    public function test_chat_returns_ai_response_with_text_and_metadata(): void
    {
        $this->setupConfig();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Ciao!']], 'role' => 'model'],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 11,
                    'candidatesTokenCount' => 3,
                    'totalTokenCount' => 14,
                ],
                'modelVersion' => 'gemini-2.0-flash',
            ], 200),
        ]);

        $p = new GeminiProvider(config('ai.providers.gemini'));
        $res = $p->chatWithHistory('sys', [
            ['role' => 'user', 'content' => 'q1'],
            ['role' => 'assistant', 'content' => 'a1'],
            ['role' => 'user', 'content' => 'q2'],
        ]);

        $this->assertInstanceOf(AiResponse::class, $res);
        $this->assertSame('Ciao!', $res->content);
        $this->assertSame('gemini', $res->provider);
        $this->assertSame(11, $res->promptTokens);
        $this->assertSame(3, $res->completionTokens);
        $this->assertSame(14, $res->totalTokens);

        Http::assertSent(fn (Request $req) => str_contains($req->url(), 'models/gemini-2.0-flash:generateContent'));
    }

    public function test_api_key_is_sent_as_header_not_url_query_string(): void
    {
        // R-logging-security regression guard — query-string secrets leak into
        // access / proxy logs + APM traces. The SDK gemini gateway authenticates
        // via the x-goog-api-key HEADER (CreatesGeminiClient); pin that here so a
        // future SDK bump can't silently reintroduce the URL-key leak.
        $this->setupConfig();
        Http::fake([
            '*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'x']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2],
                'embeddings' => [['values' => [0.1]]],
            ], 200),
        ]);

        $p = new GeminiProvider(config('ai.providers.gemini'));
        $p->chat('sys', 'q');
        $p->generateEmbeddings(['one']);

        Http::assertSent(fn (Request $req) => $req->hasHeader('x-goog-api-key', 'AIzaTest')
            && ! str_contains($req->url(), 'AIzaTest')
            && ! str_contains($req->url(), 'key='));
    }

    public function test_generate_embeddings_returns_vectors(): void
    {
        $this->setupConfig();
        Http::fake([
            '*' => Http::response([
                'embeddings' => [
                    ['values' => [0.1, 0.2]],
                    ['values' => [0.3, 0.4]],
                ],
            ], 200),
        ]);

        $p = new GeminiProvider(config('ai.providers.gemini'));
        $res = $p->generateEmbeddings(['one', 'two']);

        $this->assertSame([[0.1, 0.2], [0.3, 0.4]], $res->embeddings);
        $this->assertSame('gemini', $res->provider);

        Http::assertSent(fn (Request $req) => str_contains($req->url(), ':batchEmbedContents'));
    }

    public function test_chat_with_history_rejects_non_user_last_message(): void
    {
        $this->setupConfig();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chatWithHistory requires the last message to have role="user"; got role="assistant".');

        (new GeminiProvider(config('ai.providers.gemini')))->chatWithHistory('s', [
            ['role' => 'user', 'content' => 'Hi.'],
            ['role' => 'assistant', 'content' => 'Hello.'],
        ]);
    }
}
