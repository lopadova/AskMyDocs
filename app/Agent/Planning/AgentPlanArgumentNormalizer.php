<?php

declare(strict_types=1);

namespace App\Agent\Planning;

use App\Agent\Tools\AgentToolDefinition;

/**
 * Removes only optional empty values before schema validation.
 *
 * Unknown properties and required values are deliberately preserved so the
 * validator can reject them instead of silently repairing a malformed plan.
 */
final class AgentPlanArgumentNormalizer
{
    /** @param array<string,AgentToolDefinition> $tools */
    public function normalize(AgentPlan $plan, array $tools): AgentPlan
    {
        $actions = array_map(function (AgentPlannedAction $action) use ($tools): AgentPlannedAction {
            $schema = $tools[$action->tool]->inputSchema ?? [];

            return new AgentPlannedAction(
                id: $action->id,
                tool: $action->tool,
                arguments: $this->object($action->arguments, is_array($schema) ? $schema : []),
                dependsOn: $action->dependsOn,
                purpose: $action->purpose,
            );
        }, $plan->actions);

        return new AgentPlan($plan->decision, $actions, $plan->estimate);
    }

    /**
     * @param  array<string,mixed>  $values
     * @param  array<string,mixed>  $schema
     * @return array<string,mixed>
     */
    private function object(array $values, array $schema): array
    {
        if ($this->isReference($values)) {
            return $values;
        }

        $required = array_values(array_filter(
            is_array($schema['required'] ?? null) ? $schema['required'] : [],
            'is_string',
        ));
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $normalized = [];

        foreach ($values as $key => $value) {
            $knownProperty = is_string($key) && array_key_exists($key, $properties);
            $propertySchema = $knownProperty && is_array($properties[$key] ?? null)
                ? $properties[$key]
                : [];
            $value = $this->value($value, $propertySchema);
            $optional = $knownProperty && ! in_array($key, $required, true);

            if ($optional && $this->isRemovableEmpty($value, $propertySchema)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /** @param array<string,mixed> $schema */
    private function value(mixed $value, array $schema): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if ($this->isReference($value)) {
            return $value;
        }

        if ($this->schemaType($schema) === 'array' || array_is_list($value)) {
            $itemSchema = is_array($schema['items'] ?? null) ? $schema['items'] : [];

            return array_values(array_map(
                fn (mixed $item): mixed => is_array($item)
                    ? $this->object($item, $itemSchema)
                    : $item,
                $value,
            ));
        }

        return $this->object($value, $schema);
    }

    /** @param array<string,mixed> $schema */
    private function isRemovableEmpty(mixed $value, array $schema): bool
    {
        if ($value === null) {
            return ! $this->allowsNull($schema);
        }

        return $value === '' || $value === [];
    }

    /** @param array<string,mixed> $schema */
    private function allowsNull(array $schema): bool
    {
        $type = $schema['type'] ?? null;
        if ($type === 'null' || (is_array($type) && in_array('null', $type, true))) {
            return true;
        }

        foreach (['anyOf', 'oneOf'] as $keyword) {
            foreach (is_array($schema[$keyword] ?? null) ? $schema[$keyword] : [] as $candidate) {
                if (is_array($candidate) && $this->allowsNull($candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string,mixed> $schema */
    private function schemaType(array $schema): ?string
    {
        $type = $schema['type'] ?? null;

        return is_string($type) ? $type : null;
    }

    /** @param array<mixed> $value */
    private function isReference(array $value): bool
    {
        $keys = array_keys($value);
        sort($keys);

        return ! array_is_list($value)
            && $keys === ['$from', 'path']
            && is_string($value['$from'])
            && is_string($value['path']);
    }
}
