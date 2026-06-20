<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;

/**
 * v8.18/W1.2 — DEFERRAL GUARD for the `laravel/ai` 0.7 bump.
 *
 * The host would like to move `laravel/ai` to `^0.7`, but
 * `padosoft/laravel-ai-regolo` still requires `laravel/ai:^0.6` — bumping the
 * host would break the Regolo provider. The bump is therefore DEFERRED until
 * regolo ships a `^0.7`-compatible release.
 *
 * This test locks the deferral so the pin can't drift silently: the installed
 * `laravel/ai` must stay on the `0.x`/0.6 line WHILE regolo still constrains
 * `^0.6`. When regolo relaxes its constraint to allow `^0.7`, THIS TEST
 * intentionally starts failing — that failure is the signal to revisit the bump
 * (and then update/remove this guard). See `docs/v4-platform/PROGRESS-v8.18.md`.
 */
final class LaravelAiPinTest extends TestCase
{
    public function test_laravel_ai_stays_on_0_6_while_regolo_pins_caret_0_6(): void
    {
        $installed = (string) InstalledVersions::getPrettyVersion('laravel/ai');
        // e.g. "v0.6.8" or "0.6.8"
        $this->assertMatchesRegularExpression(
            '/^v?0\.6\./',
            $installed,
            "laravel/ai is installed at {$installed}; the 0.7 bump is DEFERRED until ".
            'padosoft/laravel-ai-regolo allows ^0.7 (see PROGRESS-v8.18.md). '.
            'If this fails because regolo now allows ^0.7, revisit the bump and update this guard.',
        );

        $regoloComposer = __DIR__.'/../../../vendor/padosoft/laravel-ai-regolo/composer.json';
        if (! is_file($regoloComposer)) {
            $this->markTestSkipped('regolo package not installed in this environment.');
        }

        $manifest = json_decode((string) file_get_contents($regoloComposer), true, 512, JSON_THROW_ON_ERROR);
        $constraint = (string) ($manifest['require']['laravel/ai'] ?? '');

        $this->assertStringContainsString(
            '0.6',
            $constraint,
            "padosoft/laravel-ai-regolo now constrains laravel/ai to '{$constraint}' (no longer ^0.6) — ".
            'the 0.7-bump blocker may be lifted; revisit W1.2 and update this guard.',
        );
        $this->assertStringNotContainsString(
            '0.7',
            $constraint,
            'regolo appears to allow ^0.7 now — revisit the deferred laravel/ai 0.7 bump.',
        );
    }
}
