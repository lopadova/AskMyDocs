<?php

declare(strict_types=1);

namespace App\Agent\Artifacts;

use App\Support\SensitivePayloadRedactor;

/** Turns a structured multi-record tool result into a generic chat table. */
final class AgentTableArtifactFactory
{
    private const MAX_ROWS = 20;

    private const MAX_COLUMNS = 7;

    public function __construct(private readonly SensitivePayloadRedactor $redactor) {}

    /**
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed>|null
     */
    public function fromToolEvidence(array $tools, bool $requiresSelection): ?array
    {
        foreach (array_reverse($tools) as $tool) {
            $result = is_array($tool['result'] ?? null) ? $tool['result'] : [];
            $rows = $this->bestRowSet($this->redactor->redact($result));
            if (count($rows) < 2) {
                continue;
            }

            $columns = $this->columns($rows);
            if ($columns === []) {
                continue;
            }

            $seen = [];
            $normalizedRows = [];
            foreach (array_slice($rows, 0, self::MAX_ROWS) as $index => $row) {
                $key = $this->rowKey($row, $index);
                if (isset($seen[$key])) {
                    $key .= '-'.($index + 1);
                }
                $seen[$key] = true;
                $normalizedRows[] = [
                    'key' => $key,
                    'label' => $this->rowLabel($row, $key),
                    'values' => array_map(
                        fn (string $column): mixed => $this->valueAt($row, $column),
                        array_combine($columns, $columns) ?: [],
                    ),
                    'record' => $row,
                ];
            }

            return [
                'component_type' => 'ui-data-table',
                'interaction_mode' => $requiresSelection ? 'selection' : 'view',
                'source_execution_id' => $tool['execution_id'] ?? null,
                'tool' => $tool['tool'] ?? null,
                'title' => $tool['display_name'] ?? $tool['tool'] ?? 'Results',
                'columns' => array_map(static fn (string $key): array => [
                    'key' => $key,
                    'label' => str($key)->afterLast('.')->replace('_', ' ')->title()->toString(),
                ], $columns),
                'rows' => $normalizedRows,
                'total_rows' => count($rows),
                'truncated' => count($rows) > self::MAX_ROWS,
            ];
        }

        return null;
    }

    /** @param array<string, mixed> $payload @return list<array<string, mixed>> */
    private function bestRowSet(array $payload): array
    {
        $candidates = [];
        $this->collectRowSets($payload, '', 0, $candidates);
        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $candidates[0]['rows'] ?? [];
    }

    /**
     * @param array<string, mixed>|list<mixed> $value
     * @param list<array{score:int,rows:list<array<string,mixed>>}> $candidates
     */
    private function collectRowSets(array $value, string $path, int $depth, array &$candidates): void
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
                    $this->collectRowSets($nested, $path.'.'.$index, $depth + 1, $candidates);
                }
            }

            return;
        }

        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $nextPath = $path === '' ? (string) $key : $path.'.'.$key;
                $this->collectRowSets($nested, $nextPath, $depth + 1, $candidates);
            }
        }
    }

    /** @param list<array<string,mixed>> $rows @return list<string> */
    private function columns(array $rows): array
    {
        $frequency = [];
        foreach (array_slice($rows, 0, 10) as $row) {
            foreach ($this->scalarFields($row) as $key => $_value) {
                $frequency[$key] = ($frequency[$key] ?? 0) + 1;
            }
        }

        $priority = ['id', 'uuid', 'name', 'full_name', 'title', 'email', 'number', 'order_number', 'code', 'status', 'date', 'created_at'];
        uksort($frequency, static function (string $a, string $b) use ($priority, $frequency): int {
            $aName = str($a)->afterLast('.')->lower()->toString();
            $bName = str($b)->afterLast('.')->lower()->toString();
            $aRank = array_search($aName, $priority, true);
            $bRank = array_search($bName, $priority, true);
            $aRank = $aRank === false ? 100 : $aRank;
            $bRank = $bRank === false ? 100 : $bRank;

            return [$aRank, -$frequency[$a], $a] <=> [$bRank, -$frequency[$b], $b];
        });

        return array_slice(array_keys($frequency), 0, self::MAX_COLUMNS);
    }

    /** @param array<string,mixed> $row @return array<string,scalar|null> */
    private function scalarFields(array $row, string $prefix = '', int $depth = 0): array
    {
        $fields = [];
        foreach ($row as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_scalar($value) || $value === null) {
                $fields[$path] = $value;
            } elseif (is_array($value) && ! array_is_list($value) && $depth < 1) {
                $fields += $this->scalarFields($value, $path, $depth + 1);
            }
        }

        return $fields;
    }

    /** @param array<string,mixed> $row */
    private function rowKey(array $row, int $index): string
    {
        $fields = $this->scalarFields($row);
        foreach (['id', 'uuid', 'customer_id', 'user_id', 'order_id', 'number', 'order_number', 'code', 'email'] as $candidate) {
            foreach ($fields as $key => $value) {
                if (strtolower(str($key)->afterLast('.')->toString()) === $candidate && $value !== null && $value !== '') {
                    return mb_substr((string) $value, 0, 100);
                }
            }
        }

        return substr(hash('sha256', (string) json_encode($row, JSON_UNESCAPED_UNICODE)), 0, 20).'-'.($index + 1);
    }

    /** @param array<string,mixed> $row */
    private function rowLabel(array $row, string $fallback): string
    {
        $fields = $this->scalarFields($row);
        foreach (['name', 'full_name', 'title', 'label', 'email', 'order_number', 'number', 'code', 'id'] as $candidate) {
            foreach ($fields as $key => $value) {
                if (strtolower(str($key)->afterLast('.')->toString()) === $candidate && $value !== null && $value !== '') {
                    return mb_substr((string) $value, 0, 160);
                }
            }
        }

        return $fallback;
    }

    /** @param array<string,mixed> $row */
    private function valueAt(array $row, string $path): mixed
    {
        $value = data_get($row, $path);

        return is_scalar($value) || $value === null ? $value : null;
    }
}
