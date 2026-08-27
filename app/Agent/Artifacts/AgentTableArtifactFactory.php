<?php

declare(strict_types=1);

namespace App\Agent\Artifacts;

use App\Agent\Support\StructuredResultRows;
use App\Support\SensitivePayloadRedactor;

/** Turns a structured multi-record tool result into a generic chat table. */
final class AgentTableArtifactFactory
{
    private const MAX_ROWS = 20;

    private const MAX_COLUMNS = 7;

    public function __construct(
        private readonly SensitivePayloadRedactor $redactor,
        private readonly StructuredResultRows $structuredRows,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed>|null
     */
    public function fromToolEvidence(array $tools, bool $requiresSelection): ?array
    {
        foreach (array_reverse($tools) as $tool) {
            $result = is_array($tool['result'] ?? null) ? $tool['result'] : [];
            $safeResult = $this->redactor->redact($result);
            $rows = $this->structuredRows->best($safeResult);
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

            $totalRows = $this->structuredRows->paginationTotal($safeResult, count($rows));

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
                'total_rows' => $totalRows,
                'truncated' => $totalRows > count($normalizedRows),
            ];
        }

        return null;
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
