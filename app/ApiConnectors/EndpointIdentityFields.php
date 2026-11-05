<?php

declare(strict_types=1);

namespace App\ApiConnectors;

/** Exact, case-sensitive field names on a returned record, never nested foreign keys. */
final class EndpointIdentityFields
{
    public static function normalize(mixed $fields): array
    {
        if (! is_array($fields) || ! array_is_list($fields)) {
            return [];
        }

        return array_values(array_unique(array_slice(array_filter($fields, static fn ($field) =>
            is_string($field) && strlen($field) <= 128 && preg_match('/^[a-zA-Z0-9_-]+$/', $field) === 1
        ), 0, 12)));
    }
}
