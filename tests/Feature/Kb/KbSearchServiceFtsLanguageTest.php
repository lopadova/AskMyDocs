<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Services\Kb\KbSearchService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * v8.8/W5 — KbSearchService resolves the per-query FTS dictionary:
 *   - detection OFF → the fixed config language (back-compat);
 *   - detection ON  → the detected language among the supported set;
 *   - detection ON but inconclusive → the config fallback (R14, never a wrong
 *     dictionary).
 *
 * The FTS branch itself is pgsql-only (SQLite skips it), so the language
 * RESOLUTION — the part W5 changes — is exercised here via reflection on the
 * private `resolveFtsLanguage()` seam rather than the tsquery SQL.
 */
final class KbSearchServiceFtsLanguageTest extends TestCase
{
    private function resolve(string $query): string
    {
        $service = app(KbSearchService::class);
        $method = new ReflectionMethod($service, 'resolveFtsLanguage');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $query);
    }

    public function test_detection_off_uses_the_fixed_config_language(): void
    {
        config()->set('kb.hybrid_search.fts_language', 'italian');
        config()->set('kb.hybrid_search.fts_language_detection', false);

        // Even an obviously-English query keeps the fixed dictionary when
        // detection is off (byte-for-byte previous behaviour).
        $this->assertSame('italian', $this->resolve('How do I rotate the signing key?'));
    }

    public function test_detection_on_picks_the_query_language(): void
    {
        config()->set('kb.hybrid_search.fts_language', 'italian');
        config()->set('kb.hybrid_search.fts_language_detection', true);
        config()->set('kb.hybrid_search.fts_supported_languages', ['english', 'italian']);

        $this->assertSame('english', $this->resolve('How do I rotate the signing key?'));
        $this->assertSame('italian', $this->resolve('Come ruoto la chiave di firma?'));
    }

    public function test_detection_on_but_inconclusive_falls_back_to_default(): void
    {
        config()->set('kb.hybrid_search.fts_language', 'italian');
        config()->set('kb.hybrid_search.fts_language_detection', true);
        config()->set('kb.hybrid_search.fts_supported_languages', ['english', 'italian']);

        // No function words → no confident signal → config fallback.
        $this->assertSame('italian', $this->resolve('rotate signing key'));
    }
}
