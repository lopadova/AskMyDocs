<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use Tests\TestCase;

/**
 * v8.11 — the dedicated AI model override knobs must resolve to NULL (not the
 * empty string "") so "empty => fall back to the default chat provider" holds.
 * `env('KB_AUTOWIKI_AI_PROVIDER')` returns "" for a present-but-blank var
 * (`KB_AUTOWIKI_AI_PROVIDER=` in .env), so config uses `?: null` to normalize.
 */
final class AutoWikiConfigTest extends TestCase
{
    public function test_model_override_knobs_are_never_the_empty_string(): void
    {
        // The contract is "empty => fall back to default chat" — i.e. the
        // resolved value is NULL or a non-empty string, NEVER "". (We don't
        // hard-assert null: an environment that legitimately exports
        // KB_AUTOWIKI_AI_PROVIDER=openai must not fail this — only the blank
        // string is forbidden.)
        foreach ([
            'kb.autowiki.ai_provider',
            'kb.autowiki.ai_model',
            'kb.autowiki.agentic_ai_provider',
            'kb.autowiki.agentic_ai_model',
        ] as $key) {
            $value = config($key);
            $this->assertNotSame('', $value, "{$key} must never be the empty string");
            $this->assertTrue(
                $value === null || (is_string($value) && $value !== ''),
                "{$key} must be null or a non-empty string",
            );
        }
    }
}
