<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;

/**
 * v8.18/W1.2 — DEFERRAL GUARD for the `laravel/ai` 0.7 bump.
 *
 * History: the bump was originally blocked because `padosoft/laravel-ai-regolo`
 * pinned `laravel/ai:^0.6`. As of regolo v1.2.0 that constraint was widened to
 * `^0.6|^0.7|^0.8`, so REGOLO NO LONGER BLOCKS the bump. The host nonetheless
 * intentionally STAYS on `laravel/ai:^0.6.8` for now — moving to 0.7 is a
 * separate, untested change (new SDK surface across all five providers) tracked
 * as a follow-up, not shipped in v8.18.
 *
 * So the deferral is now a HOST-SIDE choice, and this guard locks THAT: the host
 * composer.json must keep `laravel/ai` on the `^0.6` line and the installed
 * version must resolve to 0.6.x. When someone deliberately does the 0.7 bump
 * (host constraint → `^0.7`), THIS TEST intentionally fails — the signal to
 * update/remove it. See `docs/v4-platform/PROGRESS-v8.18.md`.
 */
final class LaravelAiPinTest extends TestCase
{
    public function test_host_keeps_laravel_ai_on_0_6_until_the_0_7_bump_is_done(): void
    {
        // The installed laravel/ai must still resolve to the 0.6 line.
        $installed = (string) InstalledVersions::getPrettyVersion('laravel/ai');
        $this->assertMatchesRegularExpression(
            '/^v?0\.6\./',
            $installed,
            "laravel/ai is installed at {$installed}; the host intentionally stays on 0.6 (the 0.7 bump is a ".
            'deferred follow-up, see PROGRESS-v8.18.md). If this fails because the bump was done, update this guard.',
        );

        // The deferral lever is now the HOST composer.json pin (regolo widened its
        // own constraint to ^0.6|^0.7|^0.8 in v1.2.0 and no longer blocks 0.7).
        $hostComposer = __DIR__.'/../../../composer.json';
        $manifest = json_decode((string) file_get_contents($hostComposer), true, 512, JSON_THROW_ON_ERROR);
        $constraint = (string) ($manifest['require']['laravel/ai'] ?? '');

        // Host must caret-pin the 0.6 line (e.g. "^0.6.8"); a "^0.7"/">=0.7"/"^1."
        // form means the bump was done → revisit/remove this guard.
        $this->assertMatchesRegularExpression(
            '/(^|[|\s])\^0\.6(\.\d+)?([|\s]|$)/',
            $constraint,
            "the host composer.json now constrains laravel/ai to '{$constraint}' (no longer the deferred ^0.6 ".
            'pin) — the 0.7 bump appears done; revisit W1.2 and update/remove this guard.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\^0\.7|>=\s*0\.7|0\.7\s*\|\||\|\|\s*0\.7|\^1\./',
            $constraint,
            "the host composer.json laravel/ai constraint '{$constraint}' now allows 0.7+ — the deferral is over; ".
            'update/remove this guard.',
        );
    }
}
