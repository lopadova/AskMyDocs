<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

/**
 * Cosine similarity between two equal-length float vectors.
 *
 * Extracted as its own service so:
 *   - Unit tests can substitute a {@see \Tests\Feature\Kb\Retrieval\FakeCosineCalculator}
 *     that returns a deterministic value (SQLite test DBs store embeddings
 *     as JSON text, not pgvector — no in-DB similarity available).
 *   - Production can later swap to a pgvector-native implementation when
 *     KbSearchService already-rank output is reused.
 */
class CosineCalculator
{
    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public function similarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $magA += $a[$i] * $a[$i];
            $magB += $b[$i] * $b[$i];
        }
        if ($magA === 0.0 || $magB === 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($magA) * sqrt($magB));
    }
}
