<?php

declare(strict_types=1);

namespace App\Agent\Evidence;

/** Explicit business collections only; arbitrary nested arrays are never dumps. */
final class DeclaredRecordCollections
{
    public static function describe(array $properties, string $prefix = '', int $depth = 0): array
    {
        if ($depth > 6) return [];
        $collections = [];
        foreach ($properties as $field => $property) {
            if (! is_string($field) || ! preg_match('/^[a-zA-Z0-9_-]+$/', $field) || ! is_array($property)) continue;
            $path = $prefix.$field;
            if (in_array('array', (array) ($property['type'] ?? []), true)) {
                $path .= '.*';
                $children = $property['items']['properties'] ?? [];
                if (! is_array($children)) continue;
                if (in_array('items', (array) ($property['x-field'] ?? []), true)) {
                    $fields = $duplicates = [];
                    foreach ($children as $key => $child) {
                        if (! is_string($key) || ! preg_match('/^[a-zA-Z0-9_-]+$/', $key) || ! is_array($child)
                            || array_intersect((array) ($child['type'] ?? []), ['string', 'integer', 'number', 'boolean']) === []) continue;
                        foreach ((array) ($child['x-field'] ?? []) as $role) {
                            if (! in_array($role, ['reference', 'description', 'variant', 'quantity', 'total', 'currency'], true)) continue;
                            if (isset($fields[$role])) $duplicates[$role] = true;
                            $fields[$role] = $key;
                        }
                    }
                    $fields = array_diff_key($fields, $duplicates);
                    if ($fields !== []) $collections[] = ['role' => 'items', 'path' => $path, 'fields' => $fields];
                } else {
                    $collections = [...$collections, ...self::describe($children, $path.'.', $depth + 1)];
                }
            } elseif (is_array($property['properties'] ?? null)) {
                $collections = [...$collections, ...self::describe($property['properties'], $path.'.', $depth + 1)];
            }
            if (count($collections) >= 4) break;
        }
        return array_slice($collections, 0, 4);
    }

    /** Original parent identity/hash is checked by EvidenceProjector before use. */
    public static function project(array $record, array $declarations): array
    {
        $collections = [];
        foreach (array_slice($declarations, 0, 4) as $declaration) {
            $path = $declaration['path'] ?? null;
            $fields = $declaration['fields'] ?? [];
            if (! is_string($path) || ! preg_match('/^[a-zA-Z0-9_-]+(?:\.(?:[a-zA-Z0-9_-]+|\*))*\.\*$/', $path)
                || ($declaration['role'] ?? null) !== 'items' || ! is_array($fields) || $fields === [] || count($fields) > 6) continue;
            if (array_filter($fields, fn ($field, $role) => ! in_array($role, ['reference', 'description', 'variant', 'quantity', 'total', 'currency'], true)
                || ! is_string($field) || ! preg_match('/^[a-zA-Z0-9_-]+$/', $field), ARRAY_FILTER_USE_BOTH)) continue;
            $original = data_get($record, $path, []);
            if (! is_array($original) || ! array_is_list($original)) continue;
            $rows = [];
            foreach (array_slice($original, 0, 20) as $row) {
                if (! is_array($row)) continue;
                $selected = array_filter(array_intersect_key($row, array_flip($fields)), fn ($value) => is_scalar($value) || $value === null);
                if ($selected !== []) $rows[] = $selected;
            }
            if ($rows !== []) $collections[] = ['role' => 'items', 'path' => $path, 'fields' => $fields,
                'rows' => $rows, 'total_rows' => count($original)];
        }
        return $collections;
    }
}
