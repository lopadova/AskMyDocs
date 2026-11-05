<?php

declare(strict_types=1);

namespace App\Agent\Capabilities;

/** Validates optional advisory metadata without allowing it to override policy. */
final class AgentCapabilityHint
{
    private const OPERATIONS = ['search', 'list', 'get', 'detail', 'summary', 'count', 'check'];

    /** @return array<string,mixed>|null */
    public function sanitize(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $entity = $this->identifier($value['entity'] ?? null);
        $operation = $this->identifier($value['operation'] ?? null);
        if ($operation !== null && ! in_array($operation, self::OPERATIONS, true)) {
            $operation = null;
        }

        $hint = array_filter([
            'entity' => $entity,
            'operation' => $operation,
            'intent_tags' => $this->identifiers($value['intent_tags'] ?? null, 12),
            'requires' => $this->identifiers($value['requires'] ?? null, 20),
            'produces' => $this->identifiers($value['produces'] ?? null, 20),
            'collection_path' => $this->path($value['collection_path'] ?? null),
            'identity_fields' => $this->identifiers($value['identity_fields'] ?? null, 12),
            'next_tools' => $this->identifiers($value['next_tools'] ?? null, 12),
        ], static fn (mixed $item): bool => $item !== null && $item !== []);

        return $hint === [] ? null : $hint;
    }

    private function identifier(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));

        return $value !== '' && strlen($value) <= 80
            && preg_match('/^[a-z0-9][a-z0-9_.:-]*$/', $value) === 1 ? $value : null;
    }

    /** @return list<string> */
    private function identifiers(mixed $value, int $limit): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_slice(array_filter(
            array_map(fn (mixed $item): ?string => $this->identifier($item), $value),
        ), 0, $limit)));
    }

    private function path(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' && strlen($value) <= 160
            && preg_match('/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_*-]+)*$/', $value) === 1 ? $value : null;
    }
}
