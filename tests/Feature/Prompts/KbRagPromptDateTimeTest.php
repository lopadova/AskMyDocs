<?php

declare(strict_types=1);

namespace Tests\Feature\Prompts;

use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Covers the "current date and time" line injected into the shared RAG
 * system prompt (resources/views/prompts/kb_rag.blade.php) so the LLM has
 * an unambiguous "now" for any time-relative reasoning.
 *
 * Asserts the line is:
 *  - rendered in ISO 8601 (the format the product decision picked),
 *  - converted into the timezone configured by `kb.prompt.timezone`
 *    (NOT a hard-coded literal; driven by config),
 *  - present in addition to the existing prompt blocks.
 *
 * `now()` is frozen with Carbon::setTestNow so the assertion is
 * deterministic; the app keeps running on UTC while the bot presents
 * local time.
 */
final class KbRagPromptDateTimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 12:30 UTC — chosen so the Europe/Rome (summer, CEST = UTC+2)
        // conversion lands on a different wall-clock hour than UTC, proving
        // the timezone knob actually converts rather than just appending.
        Carbon::setTestNow(Carbon::parse('2026-06-27 12:30:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        // Non-throwing reset; keep it before parent::tearDown() so the frozen
        // clock never leaks into a sibling test if teardown triggers other
        // time-sensitive cleanup.
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function renderPrompt(): string
    {
        return view('prompts.kb_rag', [
            'chunks' => collect(),
            'expanded' => collect(),
            'rejected' => collect(),
            'projectKey' => 'demo',
        ])->render();
    }

    public function test_it_renders_the_current_datetime_in_the_configured_timezone(): void
    {
        config(['kb.prompt.timezone' => 'Europe/Rome']);

        $prompt = $this->renderPrompt();

        // 12:30 UTC -> 14:30 in Europe/Rome (CEST, +02:00) on this date.
        $this->assertStringContainsString('2026-06-27T14:30:00+02:00', $prompt);
        $this->assertStringContainsString('(Europe/Rome)', $prompt);
    }

    public function test_the_timezone_knob_is_honoured_not_hard_coded(): void
    {
        config(['kb.prompt.timezone' => 'UTC']);

        $prompt = $this->renderPrompt();

        // Same frozen instant, different knob -> different rendered offset.
        $this->assertStringContainsString('2026-06-27T12:30:00+00:00', $prompt);
        $this->assertStringContainsString('(UTC)', $prompt);
        $this->assertStringNotContainsString('+02:00', $prompt);
    }

    public function test_an_invalid_timezone_falls_back_gracefully_without_throwing(): void
    {
        config(['kb.prompt.timezone' => 'Not/A_Valid_Timezone']);

        // The blade template catches the DateTimeZone constructor exception,
        // reports it, and falls back to app.timezone / UTC — chat must stay alive.
        $prompt = $this->renderPrompt();

        // The timestamp must still be present (fallback rendered without crash).
        $this->assertStringContainsString('Current date and time:', $prompt);

        // The invalid zone string must NOT appear in the rendered output.
        $this->assertStringNotContainsString('Not/A_Valid_Timezone', $prompt);
    }
}
