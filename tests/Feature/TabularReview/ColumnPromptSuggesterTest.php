<?php

declare(strict_types=1);

namespace Tests\Feature\TabularReview;

use App\Services\TabularReview\ColumnPromptSuggester;
use App\Support\TabularReview\FormatType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * v4.7/W1 — ColumnPromptSuggester unit-feature tests.
 */
final class ColumnPromptSuggesterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // openai is on the SDK config shape since v8.16/W2 (no-tools chat → SDK
        // `/responses`). Tests fake that shape via TestCase::openAiSdkResponsesBody.
        config()->set('ai.default', 'openai');
        config()->set('ai.providers.openai.key', 'test-key');
        config()->set('ai.providers.openai.models.text.default', 'gpt-4o-mini');
    }

    public function test_suggest_returns_llm_prompt(): void
    {
        Http::fake([
            '*' => Http::response(self::openAiSdkResponsesBody('Find the document title at the top of the page.'), 200),
        ]);

        $suggester = app(ColumnPromptSuggester::class);
        $out = $suggester->suggest('Title', FormatType::TEXT);

        $this->assertSame('Find the document title at the top of the page.', $out);
    }

    public function test_suggest_strips_wrapping_quotes(): void
    {
        Http::fake([
            '*' => Http::response(self::openAiSdkResponsesBody("\"Find the title.\""), 200),
        ]);

        $out = app(ColumnPromptSuggester::class)->suggest('Title', FormatType::TEXT);

        $this->assertSame('Find the title.', $out);
    }

    public function test_suggest_rejects_empty_column_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(ColumnPromptSuggester::class)->suggest('   ', FormatType::TEXT);
    }

    public function test_suggest_throws_when_llm_returns_empty(): void
    {
        Http::fake([
            '*' => Http::response(self::openAiSdkResponsesBody('   '), 200),
        ]);

        $this->expectException(\RuntimeException::class);
        app(ColumnPromptSuggester::class)->suggest('Title', FormatType::TEXT);
    }
}
