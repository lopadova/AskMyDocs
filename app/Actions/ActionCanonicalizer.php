<?php

declare(strict_types=1);

namespace App\Actions;

final class ActionCanonicalizer
{
    /** @param array<string, mixed> $value */
    public function json(array $value): string
    {
        return json_encode($this->normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @param array<string, mixed> $value */
    public function digest(array $value): string
    {
        return hash('sha256', $this->json($value));
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_keys($value) === range(0, count($value) - 1)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[(string) $key] = $this->normalize($item);
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}
