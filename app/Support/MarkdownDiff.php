<?php

declare(strict_types=1);

namespace App\Support;

/**
 * v8.7/W5 — line-based diff between two markdown strings.
 *
 * Used by the Cloud Time Machine to show what changed between two
 * document versions. Computes a longest-common-subsequence over LINES
 * (not characters) and emits a flat hunk list of `context` / `add` /
 * `remove` rows plus added/removed counts. Pure + deterministic — no
 * external diff library (in-house, like `MarkdownChunker`).
 */
final class MarkdownDiff
{
    /**
     * @return array{rows: list<array{type: 'context'|'add'|'remove', text: string}>, added: int, removed: int}
     */
    public static function compute(string $from, string $to): array
    {
        $a = self::lines($from);
        $b = self::lines($to);

        $lcs = self::lcsTable($a, $b);
        $rows = [];
        self::walk($lcs, $a, $b, count($a), count($b), $rows);

        $added = 0;
        $removed = 0;
        foreach ($rows as $row) {
            if ($row['type'] === 'add') {
                $added++;
            } elseif ($row['type'] === 'remove') {
                $removed++;
            }
        }

        return ['rows' => $rows, 'added' => $added, 'removed' => $removed];
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        // Normalise CRLF/CR to LF so a line-ending change isn't a false diff.
        $normalised = str_replace(["\r\n", "\r"], "\n", $text);
        if ($normalised === '') {
            return [];
        }

        return explode("\n", $normalised);
    }

    /**
     * Classic LCS length table over lines.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return array<int, array<int, int>>
     */
    private static function lcsTable(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $table[$i][$j] = $a[$i] === $b[$j]
                    ? $table[$i + 1][$j + 1] + 1
                    : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }

        return $table;
    }

    /**
     * Backtrack the LCS table into an ordered row list.
     *
     * @param  array<int, array<int, int>>  $table
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @param  list<array{type: 'context'|'add'|'remove', text: string}>  $rows
     */
    private static function walk(array $table, array $a, array $b, int $n, int $m, array &$rows): void
    {
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $rows[] = ['type' => 'context', 'text' => $a[$i]];
                $i++;
                $j++;
            } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $rows[] = ['type' => 'remove', 'text' => $a[$i]];
                $i++;
            } else {
                $rows[] = ['type' => 'add', 'text' => $b[$j]];
                $j++;
            }
        }
        while ($i < $n) {
            $rows[] = ['type' => 'remove', 'text' => $a[$i]];
            $i++;
        }
        while ($j < $m) {
            $rows[] = ['type' => 'add', 'text' => $b[$j]];
            $j++;
        }
    }
}
