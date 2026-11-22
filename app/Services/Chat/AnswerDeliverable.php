<?php

declare(strict_types=1);

namespace App\Services\Chat;

/** The result requested by the user, independent of the sources needed to produce it. */
final class AnswerDeliverable
{
    private const KINDS = ['answer', 'examples', 'comparison', 'summary', 'procedure', 'rewrite'];
    private const FRESHNESS = ['source_context', 'current'];

    public static function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false,
            'required' => ['kind', 'quantity', 'freshness'],
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => self::KINDS],
                'quantity' => ['type' => ['integer', 'null'], 'minimum' => 1],
                'freshness' => ['type' => 'string', 'enum' => self::FRESHNESS],
            ]];
    }

    public static function parse(mixed $value): ?array
    {
        if (! is_array($value) || count($value) !== 3 || ! array_key_exists('quantity', $value)
            || ! in_array($value['kind'] ?? null, self::KINDS, true)
            || ! in_array($value['freshness'] ?? null, self::FRESHNESS, true)
            || ($value['quantity'] !== null && (! is_int($value['quantity']) || $value['quantity'] < 1))) {
            return null;
        }

        return ['kind' => $value['kind'], 'quantity' => $value['quantity'] ?? ($value['kind'] === 'examples' ? 2 : null),
            'freshness' => $value['freshness']];
    }
}
