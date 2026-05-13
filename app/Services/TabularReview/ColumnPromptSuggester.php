<?php

declare(strict_types=1);

namespace App\Services\TabularReview;

use App\Ai\AiManager;
use App\Support\TabularReview\FormatType;

/**
 * v4.7/W1 — Auto-generate a 1-2 sentence extraction prompt from a
 * column name + chosen format.
 *
 * Powers the `POST /api/admin/tabular-reviews/prompt` endpoint, exposed
 * to the inline-editor's "Auto-generate prompt" button. The suggester
 * is intentionally tiny — no chunk retrieval, no streaming — because
 * the LLM only sees the column metadata; the result is a draft the
 * user can edit before saving.
 *
 * R14: a refusal path is included. When the LLM returns an empty or
 * whitespace-only completion the caller receives a `RuntimeException`
 * (Laravel maps an unhandled `RuntimeException` to HTTP 500 by default).
 * When the caller passes an empty column name the suggester throws
 * `InvalidArgumentException` instead (HTTP 400 / 422 once mapped by the
 * controller). Either way a silent empty prompt is impossible.
 */
final class ColumnPromptSuggester
{
    public function __construct(
        private readonly AiManager $ai,
    ) {}

    /**
     * @return non-empty-string
     */
    public function suggest(string $columnName, FormatType $format): string
    {
        $columnName = trim($columnName);

        if ($columnName === '') {
            throw new \InvalidArgumentException('Column name is required.');
        }

        $system = $this->systemPrompt();
        $user = $this->userPrompt($columnName, $format);

        $response = $this->ai->chat($system, $user, [
            'temperature' => 0.2,
            'max_tokens' => 200,
        ]);

        $prompt = trim($response->content);
        // Strip wrapping quotes the LLM sometimes adds.
        $prompt = trim($prompt, "\"' \t\n\r\0\x0B");

        if ($prompt === '') {
            throw new \RuntimeException('Prompt suggester returned an empty completion.');
        }

        return $prompt;
    }

    private function systemPrompt(): string
    {
        return <<<'SYS'
You are an assistant that drafts short extraction prompts for a
spreadsheet-style document review tool. Given a column name and an
output format, return ONE short sentence (max 30 words) that instructs
the extractor what to find in a document. Output only the sentence —
no preamble, no quotes, no trailing punctuation other than a period.
SYS;
    }

    private function userPrompt(string $columnName, FormatType $format): string
    {
        return sprintf(
            "Column name: %s\nFormat: %s\nWrite the extraction prompt.",
            $columnName,
            $format->value,
        );
    }
}
