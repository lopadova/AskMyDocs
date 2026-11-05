<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\Expansion;

/**
 * v8.44/W4 (ADR 0035 §D5) — turns the model's raw answer into a
 * {@see QueryExpansion}, or `null`. The model's output is ATTACKER-INFLUENCED
 * data (the query is user text), so the contract is closed and strict:
 *
 *  - a single JSON object (optionally inside a ``` fence — the one tolerated
 *    wrapper); anything else → null;
 *  - exactly the keys `lex`, `vec`, `hyde`; ANY other key → the WHOLE
 *    expansion is discarded (not repaired, not partially used);
 *  - `lex`/`vec` lists of strings, at most `$maxVariants` each; `hyde` a
 *    string or null, at most `$maxHydeChars`;
 *  - non-string / empty / over-long (`$maxVariantChars`) entries → discarded;
 *  - control characters stripped, whitespace collapsed;
 *  - drift guard: a variant must share at least one significant token
 *    (≥ 3 letters) with the ORIGINAL query, else it is dropped — a model that
 *    answers a different question cannot steer retrieval away;
 *  - a variant equal to the original (case-insensitive) adds nothing → dropped.
 */
final class QueryExpansionParser
{
    public function __construct(
        private readonly int $maxVariants = 3,
        private readonly int $maxVariantChars = 200,
        private readonly int $maxHydeChars = 400,
    ) {}

    public function parse(string $raw, string $originalQuery): ?QueryExpansion
    {
        $json = $this->unwrap($raw);
        $data = json_decode($json, true);

        if (! is_array($data) || array_is_list($data)) {
            return null;
        }
        if (array_diff(array_keys($data), ['lex', 'vec', 'hyde']) !== []) {
            return null;
        }

        $lex = $this->variants($data['lex'] ?? [], $originalQuery);
        $vec = $this->variants($data['vec'] ?? [], $originalQuery);
        $hyde = $this->hyde($data['hyde'] ?? null, $originalQuery);

        if ($lex === null || $vec === null) {
            return null;
        }

        $expansion = new QueryExpansion($lex, $vec, $hyde);

        return $expansion->isEmpty() ? null : $expansion;
    }

    private function unwrap(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $raw, $m) === 1) {
            return $m[1];
        }

        return $raw;
    }

    /**
     * @return list<string>|null  null = the field itself is malformed (discard everything)
     */
    private function variants(mixed $value, string $original): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $this->maxVariants) {
            return $value === [] ? [] : null;
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                return null;
            }
            $clean = $this->clean($item);
            if ($clean === '' || mb_strlen($clean) > $this->maxVariantChars) {
                continue;
            }
            if (mb_strtolower($clean) === mb_strtolower(trim($original)) || ! $this->sharesATerm($clean, $original)) {
                continue;
            }
            // First spelling wins (stable, order-preserving de-duplication).
            $out[mb_strtolower($clean)] ??= $clean;
        }

        return array_values($out);
    }

    private function hyde(mixed $value, string $original): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $clean = $this->clean($value);
        if ($clean === '' || mb_strlen($clean) > $this->maxHydeChars || ! $this->sharesATerm($clean, $original)) {
            return null;
        }

        return $clean;
    }

    private function clean(string $s): string
    {
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    private function sharesATerm(string $variant, string $original): bool
    {
        $a = $this->tokens($variant);
        $b = $this->tokens($original);

        return $b === [] || array_intersect($a, $b) !== [];
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($parts, static fn (string $t): bool => mb_strlen($t) >= 3)));
    }
}
