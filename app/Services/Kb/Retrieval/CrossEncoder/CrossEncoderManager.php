<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\CrossEncoder;

/**
 * v8.43/W3 (ADR 0035 §D4) — resolves the configured cross-encoder driver.
 *
 *   KB_CROSS_RERANK_DRIVER   http (default) | fake
 *   KB_CROSS_RERANK_PROFILE  self_hosted (default) | api      (http only)
 *
 * Unknown values degrade to "no driver" (the stage reports `unavailable`
 * and the heuristic order stands) — never to a permissive guess. `fake` is a
 * test double and is REFUSED when the app runs in production (R14/SEC-ENV).
 */
final class CrossEncoderManager
{
    public function driver(): CrossEncoderReranker
    {
        $driver = strtolower(trim((string) config('kb.cross_rerank.driver', 'http')));

        if ($driver === 'fake') {
            if (app()->environment('production')) {
                throw new CrossEncoderUnavailableException('fake_in_production', 'The fake cross-encoder is a test double and is refused in production.');
            }

            return new FakeCrossEncoderReranker();
        }

        if ($driver !== 'http') {
            throw new CrossEncoderUnavailableException('unknown_driver', "Unknown cross-encoder driver [{$driver}].");
        }

        $profileName = strtolower(trim((string) config('kb.cross_rerank.profile', 'self_hosted')));
        $profile = config("kb.cross_rerank.profiles.{$profileName}");
        if (! in_array($profileName, ['self_hosted', 'api'], true) || ! is_array($profile)) {
            throw new CrossEncoderUnavailableException('unknown_profile', "Unknown cross-encoder profile [{$profileName}].");
        }

        return new HttpCrossEncoderReranker(
            $profileName,
            $profile,
            (int) config('kb.cross_rerank.timeout', 10),
            (int) config('kb.cross_rerank.candidates', 30) + 20,
            (int) config('kb.cross_rerank.max_passage_chars', 2000),
        );
    }
}
