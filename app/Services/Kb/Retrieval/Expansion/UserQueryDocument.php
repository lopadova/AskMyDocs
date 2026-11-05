<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\Expansion;

/**
 * W6 (qmd parity) — a query DOCUMENT written by the user (or by a calling
 * agent) instead of a single line, the way qmd accepts it:
 *
 *     how do I cancel an order
 *     lex: annullamento ordine "rimborso"
 *     vec: steps to cancel a purchase after payment
 *     hyde: To cancel an order, open the order page and press Cancel …
 *     intent: customer-facing refund policy, not the internal ERP
 *
 * Lines that start with `lex:` / `vec:` / `hyde:` / `intent:`
 * (case-insensitive) are typed; every other non-blank line is part of the
 * ORIGINAL query. With no plain line, the original is the first `vec`, else
 * the first `lex`, else the `hyde` text. The typed variants feed the same
 * weighted N-list fusion as a model expansion — but no model is called (zero
 * LLM cost, no drift: the author wrote them).
 *
 * Bounded like the model contract: at most {@see self::MAX_VARIANTS} `lex` and
 * `vec` each, one `hyde`, length caps, control characters stripped. A text with
 * no typed line is NOT a query document ({@see parse()} returns null) and is
 * searched exactly as before. Gated by `kb.query_expansion.user_documents`
 * (default OFF, R43): a query that happens to begin with "vec:" today keeps
 * meaning what it means today until an operator opts in.
 */
final readonly class UserQueryDocument
{
    public const MAX_VARIANTS = 5;

    private const MAX_VARIANT_CHARS = 300;

    private const MAX_HYDE_CHARS = 1200;

    private const MAX_INTENT_CHARS = 300;

    public function __construct(
        public string $original,
        public QueryExpansion $expansion,
        public ?string $intent = null,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('kb.query_expansion.user_documents', false);
    }

    public static function parse(string $text): ?self
    {
        $plain = [];
        $lex = [];
        $vec = [];
        $hyde = null;
        $intent = null;
        $typed = false;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = self::clean($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(lex|vec|hyde|intent)\s*:\s*(.*)$/iu', $line, $m) !== 1) {
                $plain[] = $line;

                continue;
            }
            $typed = true;
            $value = trim($m[2]);
            if ($value === '') {
                continue;
            }
            match (strtolower($m[1])) {
                'lex' => $lex[mb_strtolower($value)] ??= mb_substr($value, 0, self::MAX_VARIANT_CHARS),
                'vec' => $vec[mb_strtolower($value)] ??= mb_substr($value, 0, self::MAX_VARIANT_CHARS),
                'hyde' => $hyde ??= mb_substr($value, 0, self::MAX_HYDE_CHARS),
                'intent' => $intent ??= mb_substr($value, 0, self::MAX_INTENT_CHARS),
            };
        }

        if (! $typed) {
            return null;
        }

        $lex = array_slice(array_values($lex), 0, self::MAX_VARIANTS);
        $vec = array_slice(array_values($vec), 0, self::MAX_VARIANTS);
        $original = $plain !== [] ? implode(' ', $plain) : ($vec[0] ?? $lex[0] ?? $hyde);
        if ($original === null || $original === '') {
            return null;
        }
        // The original is searched on its own list already: do not count it twice.
        $vec = array_values(array_filter($vec, static fn (string $v): bool => mb_strtolower($v) !== mb_strtolower($original)));
        $lex = array_values(array_filter($lex, static fn (string $v): bool => mb_strtolower($v) !== mb_strtolower($original)));

        return new self($original, new QueryExpansion($lex, $vec, $hyde === $original ? null : $hyde), $intent);
    }

    private static function clean(string $s): string
    {
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);

        return trim((string) preg_replace('/[ \t]+/u', ' ', $s));
    }
}
