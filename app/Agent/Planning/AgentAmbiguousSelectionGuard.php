<?php

declare(strict_types=1);

namespace App\Agent\Planning;

use App\Agent\Support\StructuredResultRows;

/** Prevents a downstream call from silently choosing one record out of many. */
final readonly class AgentAmbiguousSelectionGuard
{
    public function __construct(private StructuredResultRows $rows) {}

    /**
     * @param list<array<string,mixed>> $toolEvidence
     * @param array<string,mixed> $arguments
     * @param array<string,mixed>|null $selection
     */
    public function blocks(array $toolEvidence, array $arguments, ?array $selection): bool
    {
        $argumentIdentities = $this->identityValues($arguments);
        if ($argumentIdentities === []) {
            return false;
        }

        $selectedRecord = is_array($selection['record'] ?? null) ? $selection['record'] : [];
        if (array_intersect($argumentIdentities, $this->identityValues($selectedRecord)) !== []) {
            return false;
        }

        foreach (array_reverse($toolEvidence) as $tool) {
            $result = is_array($tool['result'] ?? null) ? $tool['result'] : [];
            $rows = $this->rows->best($result);
            if (count($rows) < 2) {
                continue;
            }

            $candidateIdentities = [];
            foreach ($rows as $row) {
                $candidateIdentities = array_merge($candidateIdentities, $this->identityValues($row));
            }

            if (array_intersect($argumentIdentities, array_values(array_unique($candidateIdentities))) !== []) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $payload @return list<string> */
    private function identityValues(array $payload): array
    {
        $values = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $values = array_merge($values, $this->identityValues($value));

                continue;
            }
            if (! is_scalar($value) || $value === '') {
                continue;
            }

            $name = strtolower((string) $key);
            if ($name !== 'id'
                && $name !== 'uuid'
                && $name !== 'code'
                && $name !== 'number'
                && ! str_ends_with($name, '_id')
                && ! str_ends_with($name, '_uuid')
                && ! str_ends_with($name, '_code')
                && ! str_ends_with($name, '_number')) {
                continue;
            }

            $values[] = (string) $value;
        }

        return array_values(array_unique($values));
    }
}
