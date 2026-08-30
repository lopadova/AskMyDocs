<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;

/**
 * PLATFORM-PIN GUARD for `laravel/ai`. Currently locks the 0.11 line.
 *
 * History (v8.19/W1): the 0.7/0.8 bump was deferred through v8.16–v8.18 (the SDK surface
 * was untested across all five providers, and `padosoft/laravel-ai-regolo`
 * originally pinned `^0.6`). In v8.19 the migration was done **totally**: regolo
 * was released on `^0.6|^0.7|^0.8.1` (v1.2.1), finops on the 0.8 line (v1.4.0),
 * and the host bumped to `laravel/ai:^0.8.1`. The only 0.6→0.8 breaking change
 * (the `TranscriptionGateway::generateTranscription()` `$providerOptions`
 * parameter, laravel/ai v0.7.0) does not affect AskMyDocs — the host uses chat +
 * embeddings only, never transcription. See `docs/v4-platform/PROGRESS-v8.19.md`
 * + `docs/adr/0016-v819-laravel-ai-0.8-platform-migration.md`.
 *
 * v8.35 — moved to the 0.11 line, and the guard did its job on the way: it
 * failed the bump and forced the compatibility pass it exists to force.
 *
 * That pass found 0.11's breaking change lands entirely BELOW where this
 * application works. 0.11 inverts the multi-step tool loop -- `TextGateway`
 * becomes `StepTextGateway`, a gateway performs one step and the SDK owns the
 * loop -- and the host touches no gateway class at all. Its whole SDK surface
 * is `AnonymousAgent`, `Embeddings`, `Messages\*`, `Responses\*`,
 * `TextGenerationOptions`, `Enums\Lab` and `Contracts\HasProviderOptions`,
 * none of which changed. The inversion was `padosoft/laravel-ai-regolo`'s
 * problem, and it is migrated in v2.0.0; `laravel-ai-guardrails` v1.6.0 came
 * through unchanged because it only touches `Contracts\Tool`.
 *
 * The guard now locks the 0.11 line on the same terms: a drift backwards or a
 * forward jump to `^0.12`/`^1.` fails, as the signal to repeat that pass
 * before the pin moves again.
 */
final class LaravelAiPinTest extends TestCase
{
    public function test_host_is_on_the_laravel_ai_0_11_line(): void
    {
        // The installed laravel/ai must resolve to the 0.11 line.
        $installed = (string) InstalledVersions::getPrettyVersion('laravel/ai');
        $this->assertMatchesRegularExpression(
            '/^v?0\.11\./',
            $installed,
            "laravel/ai is installed at {$installed}; v8.35 migrated the platform to the 0.11 line. ".
            'A different line means the pin drifted — revisit the provider compatibility surface before re-pinning.',
        );

        // The host composer.json must caret-pin the 0.11 line (e.g. "^0.11").
        $hostComposer = __DIR__.'/../../../composer.json';
        $manifest = json_decode((string) file_get_contents($hostComposer), true, 512, JSON_THROW_ON_ERROR);
        $constraint = (string) ($manifest['require']['laravel/ai'] ?? '');

        // Must be an EXACT single caret-pin on the 0.11 line (e.g. "^0.11") —
        // anchored, so any OR-widening ("^0.11 || ^0.12"), a forward bump
        // ("^0.12"/"^1."), or a downgrade ("^0.8.1"/"^0.9") all fail
        // deterministically and force a fresh provider compatibility pass
        // before the pin moves.
        $this->assertMatchesRegularExpression(
            '/^\^0\.11(\.\d+)?$/',
            $constraint,
            "the host composer.json must pin laravel/ai to an exact single caret on the 0.11 line ".
            "(e.g. ^0.11); it is '{$constraint}'. An OR-range, a 0.12/1.0 forward bump, or a downgrade ".
            'all require a fresh provider compatibility pass before the pin moves.',
        );
    }
}
