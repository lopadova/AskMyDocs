<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;

/**
 * v8.19/W1 — PLATFORM-PIN GUARD for the `laravel/ai` 0.8 migration.
 *
 * History: the 0.7/0.8 bump was deferred through v8.16–v8.18 (the SDK surface
 * was untested across all five providers, and `padosoft/laravel-ai-regolo`
 * originally pinned `^0.6`). In v8.19 the migration was done **totally**: regolo
 * was released on `^0.6|^0.7|^0.8.1` (v1.2.1), finops on the 0.8 line (v1.4.0),
 * and the host bumped to `laravel/ai:^0.8.1`. The only 0.6→0.8 breaking change
 * (the `TranscriptionGateway::generateTranscription()` `$providerOptions`
 * parameter, laravel/ai v0.7.0) does not affect AskMyDocs — the host uses chat +
 * embeddings only, never transcription. See `docs/v4-platform/PROGRESS-v8.19.md`
 * + `docs/adr/0016-v819-laravel-ai-0.8-platform-migration.md`.
 *
 * This guard now locks the migrated state: the installed `laravel/ai` must be on
 * the 0.8 line AND the host composer.json must caret-pin `^0.8`. A drift back to
 * `^0.6`/`^0.7` (or a forward jump to `^0.9`/`^1.`) fails the test as the signal
 * to revisit the provider compatibility surface before re-pinning.
 */
final class LaravelAiPinTest extends TestCase
{
    public function test_host_is_on_the_laravel_ai_0_8_line(): void
    {
        // The installed laravel/ai must resolve to the 0.8 line.
        $installed = (string) InstalledVersions::getPrettyVersion('laravel/ai');
        $this->assertMatchesRegularExpression(
            '/^v?0\.8\./',
            $installed,
            "laravel/ai is installed at {$installed}; v8.19 migrated the platform to the 0.8 line. ".
            'A different line means the pin drifted — revisit the provider compatibility surface (see PROGRESS-v8.19.md).',
        );

        // The host composer.json must caret-pin the 0.8 line (e.g. "^0.8.1").
        $hostComposer = __DIR__.'/../../../composer.json';
        $manifest = json_decode((string) file_get_contents($hostComposer), true, 512, JSON_THROW_ON_ERROR);
        $constraint = (string) ($manifest['require']['laravel/ai'] ?? '');

        $this->assertMatchesRegularExpression(
            '/(^|[|\s])\^0\.8(\.\d+)?([|\s]|$)/',
            $constraint,
            "the host composer.json constrains laravel/ai to '{$constraint}', not the expected ^0.8 line — ".
            'a 0.9/1.0 bump or a downgrade needs a fresh provider compatibility pass before the pin moves.',
        );
        // Guard against silently slipping BACK to the deferred 0.6/0.7 pins.
        $this->assertDoesNotMatchRegularExpression(
            '/(^|[|\s])\^0\.[67](\.\d+)?([|\s]|$)/',
            $constraint,
            "the host composer.json laravel/ai constraint '{$constraint}' slipped back to the 0.6/0.7 line — ".
            'the platform is migrated to 0.8; do not downgrade.',
        );
    }
}
