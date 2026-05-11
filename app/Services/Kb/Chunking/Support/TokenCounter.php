<?php

declare(strict_types=1);

namespace App\Services\Kb\Chunking\Support;

/**
 * Cheap whitespace-based token estimator shared across v4.5/W5.5 chunkers.
 *
 * The same `strlen($text) / 4` heuristic the legacy MarkdownChunker has
 * always used, lifted into a reusable helper so every new source-aware
 * chunker reports comparable token budgets without code duplication.
 *
 * Exact tokenisation belongs to the embedding provider — this class only
 * gates the per-chunk hard cap.
 */
final class TokenCounter
{
    private const CHARS_PER_TOKEN = 4;

    public function estimate(string $text): int
    {
        return (int) ceil(strlen($text) / self::CHARS_PER_TOKEN);
    }
}
