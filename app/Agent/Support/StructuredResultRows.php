<?php

declare(strict_types=1);

namespace App\Agent\Support;

/** Locates the most relevant record collection inside a structured tool result. */
final class StructuredResultRows
{
    /** @param array<string,mixed> $payload @return list<array<string,mixed>> */
    public function best(array $payload): array
    {
        $candidates = [];
        $this->collect($payload, '', 0, $candidates);
        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $candidates[0]['rows'] ?? [];
    }

    /** @param array<string,mixed> $payload */
    public function paginationTotal(array $payload, int $fallback): int
    {
        $totals = [];
        $this->collectPaginationTotals($payload, '', 0, $totals);

        return max([$fallback, ...$totals]);
    }

    /**
     * @param array<string, mixed>|list<mixed> $value
     * @param list<array{score:int,rows:list<array<string,mixed>>}> $candidates
     */
    private function collect(array $value, string $path, int $depth, array &$candidates): void
    {
        if ($depth > 8) {
            return;
        }

        if (array_is_list($value)) {
            $rows = array_values(array_filter($value, static fn (mixed $row): bool => is_array($row) && ! array_is_list($row)));
            if (count($rows) >= 2 && count($rows) === count($value)) {
                $lowerPath = strtolower($path);
                $score = count($rows);
                if (preg_match('/(?:^|\.)(items|results|rows|customers|orders|users|records)$/', $lowerPath) === 1) {
                    $score += 100;
                }
                if (str_contains($lowerPath, 'structuredcontent')) {
                    $score += 40;
                }
                if (preg_match('/(?:content|attachments|messages)/', $lowerPath) === 1) {
                    $score -= 100;
                }
                $candidates[] = ['score' => $score, 'rows' => $rows];
            }

            foreach ($value as $index => $nested) {
                if (is_array($nested)) {
                    $this->collect($nested, $path.'.'.$index, $depth + 1, $candidates);
                }
            }

            return;
        }

        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $nextPath = $path === '' ? (string) $key : $path.'.'.$key;
                $this->collect($nested, $nextPath, $depth + 1, $candidates);
            }
        }
    }

    /** @param array<string,mixed>|list<mixed> $value @param list<int> $totals */
    private function collectPaginationTotals(array $value, string $path, int $depth, array &$totals): void
    {
        if ($depth > 8) {
            return;
        }

        if (! array_is_list($value) && str_ends_with(strtolower($path), 'pagination')) {
            $total = $value['total'] ?? null;
            if (is_numeric($total) && (int) $total >= 0) {
                $totals[] = (int) $total;
            }
        }

        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $nextPath = $path === '' ? (string) $key : $path.'.'.$key;
                $this->collectPaginationTotals($nested, $nextPath, $depth + 1, $totals);
            }
        }
    }
}
