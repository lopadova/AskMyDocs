<?php

declare(strict_types=1);

namespace App\Support\Benchmark;

/**
 * Deterministic, dependency-free, content-discriminative pseudo-embedding
 * for the offline benchmark + the deterministic E2E pipeline scenario.
 *
 * Unlike the constant `[0.1, 0.2, 0.3]` mock used in existing tests — which
 * makes every chunk equidistant and so makes ranking meaningless — this
 * maps a text to a token-hash bag vector and L2-normalises it. Texts that
 * share vocabulary get a high cosine similarity; unrelated texts get a low
 * one. That gives a realistic, reproducible ranking signal with NO API key,
 * so the full retrieval pipeline (vector search → rerank → citations) can be
 * exercised end-to-end in CI.
 *
 * NOT a substitute for a real embedding model — semantic paraphrase without
 * shared tokens won't match. The LIVE benchmark (real OpenRouter embeddings)
 * is what validates true semantic quality; this is the deterministic
 * wiring/ranking harness.
 */
final class DeterministicEmbedder
{
    // 4096 buckets keep lexically-disjoint texts near-orthogonal (low hash
    // collision), so unrelated queries score ~0 and the refusal gate fires
    // reliably; shared vocabulary still dominates the cosine for matches.
    public const DEFAULT_DIM = 4096;

    /**
     * @return list<float>  an L2-normalised vector of length $dim
     */
    public static function embed(string $text, int $dim = self::DEFAULT_DIM): array
    {
        $vector = array_fill(0, $dim, 0.0);

        foreach (self::tokenize($text) as $token) {
            // Stable bucket from the token; a second hash gives the sign so
            // distinct tokens don't all push the same direction.
            $bucket = (int) (hexdec(substr(md5($token), 0, 8)) % $dim);
            $sign = (hexdec(substr(md5($token), 8, 1)) % 2) === 0 ? 1.0 : -1.0;
            $vector[$bucket] += $sign;
        }

        return self::l2normalize($vector);
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public static function embedBatch(array $texts, int $dim = self::DEFAULT_DIM): array
    {
        return array_map(static fn (string $t): array => self::embed($t, $dim), $texts);
    }

    /** @return list<string> */
    private static function tokenize(string $text): array
    {
        $lower = mb_strtolower($text);
        $parts = preg_split('/[^a-z0-9]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(
            $parts ?: [],
            static fn (string $t): bool => mb_strlen($t) >= 2,
        ));
    }

    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    private static function l2normalize(array $vector): array
    {
        $norm = 0.0;
        foreach ($vector as $v) {
            $norm += $v * $v;
        }
        if ($norm <= 0.0) {
            return $vector;
        }
        $norm = sqrt($norm);

        return array_map(static fn (float $v): float => $v / $norm, $vector);
    }
}
