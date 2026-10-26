<?php

declare(strict_types=1);
namespace App\ApiTools;

/** Dot paths never traverse a list implicitly. Selection and projection are explicit. */
final class BindingResolver
{
    public function path(mixed $value, string $path): mixed
    {
        if ($path === '') { return $value; }
        foreach (explode('.', $path) as $part) {
            if (! is_array($value) || ! array_key_exists($part, $value)) { throw new \DomainException('api_tool_value_missing'); }
            $value = $value[$part];
        }
        return $value;
    }

    public function resolve(array $binding, array $inputs, array $outputs, mixed $item = null): mixed
    {
        $source = match ($binding['source'] ?? '') {
            'input' => $inputs,
            'step' => array_key_exists($binding['step'] ?? '', $outputs) ? $outputs[$binding['step']] : throw new \DomainException('api_tool_step_missing'),
            'constant' => $binding['value'] ?? null,
            'item' => $item,
            default => throw new \DomainException('api_tool_binding_invalid'),
        };
        $value = $this->path($source, (string) ($binding['path'] ?? ''));
        return match ($binding['mode'] ?? 'value') {
            'value' => $value,
            'index' => is_array($value) && array_is_list($value) && array_key_exists($binding['index'], $value)
                ? $this->path($value[$binding['index']], $binding['project_path'] ?? '') : throw new \DomainException('api_tool_selection_missing'),
            'project' => is_array($value) && array_is_list($value)
                ? array_map(fn ($v) => $this->path($v, $binding['project_path'] ?? ''), $value) : throw new \DomainException('api_tool_projection_requires_list'),
            default => throw new \DomainException('api_tool_binding_mode_invalid'),
        };
    }

    public function map(array $bindings, array $inputs, array $outputs, mixed $item = null): array
    {
        $result = [];
        foreach ($bindings as $key => $binding) { $result[$key] = $this->resolve($binding, $inputs, $outputs, $item); }
        return $result;
    }
}
