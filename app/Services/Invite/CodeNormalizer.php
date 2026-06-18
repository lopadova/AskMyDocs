<?php

declare(strict_types=1);

namespace App\Services\Invite;

/**
 * The single normalization chokepoint shared by the generator, the validator,
 * and the redemption service (docs/05-code-generation.md). `UNIQUE(code)`
 * protects the normalized form, so every write and every lookup MUST pass
 * through here — otherwise an ingest path and a redeem path can diverge and a
 * valid code reads as "not found".
 *
 * Crockford Base32 input folding: a human who types I/L/O gets 1/1/0 so the
 * confusables resolve to their canonical digit.
 */
final class CodeNormalizer
{
    /**
     * normalize(raw) — trim, uppercase, strip cosmetic separators, fold
     * Crockford confusables. Idempotent: normalize(normalize(x)) == normalize(x).
     */
    public function normalize(string $raw): string
    {
        $s = strtoupper(trim($raw));
        $s = str_replace([' ', '-', '_'], '', $s);

        // Crockford input folding — only the input confusables, never the
        // canonical digits themselves.
        return strtr($s, [
            'I' => '1',
            'L' => '1',
            'O' => '0',
        ]);
    }
}
