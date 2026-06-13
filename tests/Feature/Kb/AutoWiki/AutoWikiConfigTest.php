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
    public function test_model_override_knobs_default_to_null_not_empty_string(): void
    {
        foreach ([
            'kb.autowiki.ai_provider',
            'kb.autowiki.ai_model',
            'kb.autowiki.agentic_ai_provider',
            'kb.autowiki.agentic_ai_model',
        ] as $key) {
            $value = config($key);
            $this->assertNotSame('', $value, "{$key} must never be the empty string");
            $this->assertNull($value, "{$key} must default to null (fall back to default chat)");
        }
    }
}
