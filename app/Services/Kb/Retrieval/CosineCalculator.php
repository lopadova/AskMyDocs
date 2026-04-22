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
     *
     * @throws \InvalidArgumentException when the vectors have different
     *         non-zero lengths — a dimension mismatch is a configuration
     *         bug (typically the embeddings provider/model was changed
     *         without flushing the cache) and silently truncating would
     *         degrade retrieval quietly. We fail fast so the misconfig is
     *         visible immediately. Callers that want graceful degradation
     *         (e.g. a chat request should still answer on other docs) may
     *         catch the exception per-pair.
     */
    public function similarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        if (count($a) !== count($b)) {
            throw new \InvalidArgumentException(sprintf(
                'CosineCalculator: vector dimension mismatch (%d vs %d). '
                .'This usually means the embeddings provider or model was changed '
                .'without flushing embedding_cache. Run kb:prune-embedding-cache '
                .'and re-ingest affected documents.',
                count($a),
                count($b),
            ));
        }

        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        $len = count($a);
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
