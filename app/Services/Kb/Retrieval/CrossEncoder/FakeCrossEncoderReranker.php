<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\CrossEncoder;

/**
 * v8.43/W3 — deterministic, offline cross-encoder for tests and the
 * `kb:benchmark --stub` path. The score is the share of the query's
 * significant tokens that occur in the passage, which makes it behave like a
 * (weak) pair reader: paraphrases score low, literal answers high.
 *
 * REFUSED in production by {@see CrossEncoderManager}: it is a test double,
 * not a ranking model.
 */
final class FakeCrossEncoderReranker implements CrossEncoderReranker
{
    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-token-overlap';
    }

    public function score(string $query, array $passages): array
    {
        $tokens = $this->tokens($query);

        return array_map(function (string $passage) use ($tokens): float {
            if ($tokens === []) {
                return 0.0;
            }
            $hay = mb_strtolower($passage);
            $hits = 0;
            foreach ($tokens as $t) {
                if (mb_strpos($hay, $t) !== false) {
                    $hits++;
                }
            }

            return round($hits / count($tokens), 6);
        }, array_values($passages));
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($parts, static fn (string $t): bool => mb_strlen($t) > 2)));
    }
}
