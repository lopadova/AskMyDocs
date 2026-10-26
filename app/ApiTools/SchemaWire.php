<?php

declare(strict_types=1);
namespace App\ApiTools;

/** Empty PHP maps must remain JSON objects in model signatures and portable schemas. */
final class SchemaWire
{
    public static function normalize(array $schema): array
    {
        if (($schema['type'] ?? null) === 'object' && empty($schema['properties'])) { $schema['properties']=(object) []; }
        elseif (is_array($schema['properties'] ?? null)) {
            foreach ($schema['properties'] as $key=>$property) { $schema['properties'][$key]=self::normalize($property); }
        }
        if (is_array($schema['items'] ?? null)) { $schema['items']=self::normalize($schema['items']); }
        return $schema;
    }
}
