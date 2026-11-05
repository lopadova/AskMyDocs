<?php

declare(strict_types=1);

namespace App\Actions;

final class ActionRegistry
{
    /** @param array<string, array<string, mixed>> $definitions */
    public function __construct(private readonly array $definitions) {}

    public function isKnown(string $tool): bool
    {
        return array_key_exists($tool, $this->definitions);
    }

    public function isExecutable(string $tool): bool
    {
        $definition = $this->definitions[$tool] ?? null;

        return is_array($definition)
            && isset($definition['effect'])
            && is_string($definition['effect'])
            && $definition['effect'] !== ''
            && ($definition['executable'] ?? false) === true;
    }
}
