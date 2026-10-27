<?php

declare(strict_types=1);

namespace App\ApiConnectors;

/** A connection test infers parameter shape; it must not erase declared scalar constraints. */
final class ApiInputSchemaConstraints
{
    private const KEYS = ['enum', 'const', 'pattern', 'format', 'minLength', 'maxLength',
        'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'default', 'x-require-observed-identifier'];

    public function preserve(array $inferred, ?array $previous, ?array $definition = null): array
    {
        foreach ($inferred['properties'] ?? [] as $name => $property) {
            $type = $property['type'] ?? null;
            if (! in_array($type, ['string', 'integer', 'number', 'boolean'], true)) continue;
            foreach ([$definition, $previous] as $source) {
                $declared = $source['properties'][$name] ?? null;
                if (is_array($declared) && ($declared['type'] ?? null) === $type) {
                    $inferred['properties'][$name] = [...$inferred['properties'][$name],
                        ...array_intersect_key($declared, array_flip(self::KEYS))];
                }
            }
        }

        return $inferred;
    }
}
