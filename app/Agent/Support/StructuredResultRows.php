<?php

declare(strict_types=1);

namespace App\Agent\Support;

/** Locates the most relevant record collection inside a structured tool result. */
final class StructuredResultRows
{
    /** @param array<string,mixed> $payload @return list<array<string,mixed>> */
    public function atPath(array $payload, string $path, int $minimumRows = 2): array
    {
        $candidate = $path === '$' ? $payload : data_get($payload, $path);
        $rows = $this->recordRows($candidate, $minimumRows);
        if ($rows !== []) {
            return $rows;
        }

        // MCP envelopes may wrap the declared structured result. Match the
        // declared suffix only; never fall back to an unrelated nested list.
        $matches = [];
        $this->collectDeclaredPath($payload, '', 0, $path, $minimumRows, $matches);

        return $matches[0] ?? [];
    }

    /** @param array<string,mixed> $payload @return list<array<string,mixed>> */
    public function best(array $payload, int $minimumRows = 2): array
    {
        $candidates = [];
        $this->collect($payload, '', 0, max(1, $minimumRows), $candidates);
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
    private function collect(array $value, string $path, int $depth, int $minimumRows, array &$candidates): void
    {
        if ($depth > 8) {
            return;
        }

        if (array_is_list($value)) {
            $rows = array_values(array_filter($value, static fn (mixed $row): bool => is_array($row) && ! array_is_list($row)));
            if (count($rows) >= $minimumRows && count($rows) === count($value)) {
                $lowerPath = strtolower($path);
                if (preg_match('/(?:^|\.)(?:content|attachments|messages)(?:\.|$)/', $lowerPath) !== 1) {
                    $score = count($rows);
                    if (preg_match('/(?:^|\.)(items|results|rows|customers|orders|users|records)$/', $lowerPath) === 1) {
                        $score += 100;
                    }
                    if (str_contains($lowerPath, 'structuredcontent')) {
                        $score += 40;
                    }
                    $candidates[] = ['score' => $score, 'rows' => $rows];
                }
            }

            foreach ($value as $index => $nested) {
                if (is_array($nested)) {
                    $this->collect($nested, $path.'.'.$index, $depth + 1, $minimumRows, $candidates);
                }
            }

            return;
        }

        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $nextPath = $path === '' ? (string) $key : $path.'.'.$key;
                $this->collect($nested, $nextPath, $depth + 1, $minimumRows, $candidates);
            }
        }
    }

    /** @param list<list<array<string,mixed>>> $matches */
    private function collectDeclaredPath(
        array $value,
        string $path,
        int $depth,
        string $declaredPath,
        int $minimumRows,
        array &$matches,
    ): void {
        if ($depth > 8) {
            return;
        }
        $normalized = ltrim($path, '.');
        if ($normalized === $declaredPath || str_ends_with($normalized, '.'.$declaredPath)) {
            $rows = $this->recordRows($value, $minimumRows);
            if ($rows !== []) {
                $matches[] = $rows;
                return;
            }
        }
        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $next = $path === '' ? (string) $key : $path.'.'.$key;
                $this->collectDeclaredPath($nested, $next, $depth + 1, $declaredPath, $minimumRows, $matches);
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function recordRows(mixed $value, int $minimumRows): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }
        $rows = array_values(array_filter(
            $value,
            static fn (mixed $row): bool => is_array($row) && ! array_is_list($row),
        ));

        return count($rows) >= max(1, $minimumRows) && count($rows) === count($value) ? $rows : [];
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
