<?php

declare(strict_types=1);

namespace App\Agent\Planning;

use App\Agent\Capabilities\AgentCapabilityRoute;
use App\Agent\Capabilities\AgentCapabilitySnapshot;
use App\Agent\Tools\AgentToolDefinition;
use Opis\JsonSchema\Validator;

final readonly class AgentPlanValidator
{
    public function __construct(private Validator $schema = new Validator) {}

    /**
     * @param array<string,AgentToolDefinition> $tools
     * @param list<array<string,mixed>> $completedActions
     * @param array<string,array<string,mixed>> $results
     */
    public function validate(
        AgentPlan $plan,
        array $tools,
        AgentCapabilitySnapshot $snapshot,
        AgentCapabilityRoute $route,
        array $completedActions,
        array $results,
    ): void {
        $completedIds = array_values(array_filter(array_column($completedActions, 'id'), 'is_string'));
        $completedTools = array_values(array_filter(array_column($completedActions, 'tool'), 'is_string'));
        $plannedById = [];

        foreach ($plan->actions as $action) {
            $tool = $tools[$action->tool] ?? null;
            if (! $tool instanceof AgentToolDefinition) {
                $this->fail('unknown_tool', "Unknown tool [{$action->tool}].");
            }
            if (! $tool->readOnly || (bool) ($tool->metadata['confirmation_required'] ?? false)) {
                $this->fail('write_tool_forbidden', "Tool [{$action->tool}] is outside the read-only rollout.");
            }

            $this->validateReferences($action, $plannedById, $completedIds, $results, $snapshot);
            $data = $this->materialize($action->arguments, $tool->inputSchema);
            $schema = $this->object($tool->inputSchema);
            $result = $this->schema->validate($data, $schema);
            if (! $result->isValid()) {
                $this->fail('arguments_schema_invalid', "Arguments for [{$action->tool}] do not match its JSON Schema.");
            }
            $plannedById[$action->id] = $action;
        }

        if ($plan->decision === 'insufficient') {
            $untried = array_values(array_diff($route->candidateTools, $completedTools));
            if ($untried !== []) {
                $this->fail(
                    'premature_insufficient',
                    'Relevant read-only tools remain untried: '.implode(', ', array_slice($untried, 0, 8)).'.',
                );
            }
        }
    }

    /**
     * @param array<string,AgentPlannedAction> $plannedById
     * @param list<string> $completedIds
     * @param array<string,array<string,mixed>> $results
     */
    private function validateReferences(
        AgentPlannedAction $action,
        array $plannedById,
        array $completedIds,
        array $results,
        AgentCapabilitySnapshot $snapshot,
    ): void {
        $references = [];
        $this->collectReferences($action->arguments, $references);
        foreach ($references as $reference) {
            $source = $reference['$from'];
            $path = $reference['path'];
            if (! $this->safePath($path)) {
                $this->fail('reference_path_invalid', "Reference path [{$path}] is invalid.");
            }
            if (in_array($source, ['current_selection', 'selected_row'], true)) {
                $sentinel = new \stdClass;
                if (! array_key_exists($source, $results)
                    || data_get($results[$source], $path, $sentinel) === $sentinel) {
                    $this->fail('reference_value_missing', "Selection path [{$path}] is unavailable.");
                }
                continue;
            }
            if (! in_array($source, $action->dependsOn, true)) {
                $this->fail('reference_dependency_missing', "Reference [{$source}] must appear in depends_on.");
            }
            if (in_array($source, $completedIds, true)) {
                $sentinel = new \stdClass;
                if (! array_key_exists($source, $results) || data_get($results[$source], $path, $sentinel) === $sentinel) {
                    $this->fail('reference_value_missing', "Completed result [{$source}.{$path}] is unavailable.");
                }
                continue;
            }
            $sourceAction = $plannedById[$source] ?? null;
            if (! $sourceAction instanceof AgentPlannedAction) {
                $this->fail('reference_source_invalid', "Reference source [{$source}] is not an earlier action.");
            }
            $capability = $snapshot->get($sourceAction->tool);
            if ($capability?->outputSchema === null || ! $this->schemaHasPath($capability->outputSchema, $path)) {
                $this->fail(
                    'speculative_reference_path',
                    "Result path [{$path}] is not declared for [{$sourceAction->tool}]; execute it and re-plan.",
                );
            }
        }
    }

    /** @param array<mixed> $value @param list<array{'$from':string,path:string}> $references */
    private function collectReferences(array $value, array &$references): void
    {
        if ($this->isReference($value)) {
            $references[] = ['$from' => $value['$from'], 'path' => $value['path']];

            return;
        }
        foreach ($value as $nested) {
            if (is_array($nested)) {
                $this->collectReferences($nested, $references);
            }
        }
    }

    /** @param array<string,mixed> $schema */
    private function materialize(array $value, array $schema): object|array
    {
        if (($schema['type'] ?? null) === 'array') {
            $items = is_array($schema['items'] ?? null) ? $schema['items'] : [];

            return array_values(array_map(
                fn (mixed $nested): mixed => $this->materializeValue($nested, $items),
                $value,
            ));
        }

        $out = [];
        foreach ($value as $key => $nested) {
            $propertySchema = is_array($schema['properties'][$key] ?? null) ? $schema['properties'][$key] : [];
            $out[$key] = $this->materializeValue($nested, $propertySchema);
        }

        return (object) $out;
    }

    /** @param array<string,mixed> $schema */
    private function materializeValue(mixed $value, array $schema): mixed
    {
        if (is_array($value) && $this->isReference($value)) {
            return $this->placeholder($schema);
        }
        if (is_array($value)) {
            return $this->materialize($value, $schema);
        }

        return $value;
    }

    /** @param array<string,mixed> $schema */
    private function placeholder(array $schema): mixed
    {
        $type = $schema['type'] ?? null;
        if (is_array($type)) {
            $type = $type[0] ?? null;
        }

        return match ($type) {
            'integer' => 0,
            'number' => 0.0,
            'boolean' => false,
            'array' => [],
            'object' => (object) [],
            default => '',
        };
    }

    /** @param array<string,mixed> $schema */
    private function schemaHasPath(array $schema, string $path): bool
    {
        $node = $schema;
        foreach (explode('.', $path) as $segment) {
            if (ctype_digit($segment) || $segment === '*') {
                if (($node['type'] ?? null) !== 'array' || ! is_array($node['items'] ?? null)) {
                    return false;
                }
                $node = $node['items'];
                continue;
            }
            if (! is_array($node['properties'][$segment] ?? null)) {
                return false;
            }
            $node = $node['properties'][$segment];
        }

        return true;
    }

    /** @param array<mixed> $value */
    private function isReference(array $value): bool
    {
        $keys = array_keys($value);
        sort($keys);

        return ! array_is_list($value)
            && $keys === ['$from', 'path']
            && is_string($value['$from']) && $value['$from'] !== ''
            && is_string($value['path']) && $value['path'] !== '';
    }

    private function safePath(string $path): bool
    {
        return strlen($path) <= 256
            && preg_match('/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_*-]+)*$/', $path) === 1;
    }

    /** @param array<string,mixed> $schema */
    private function object(array $schema): object|bool
    {
        return json_decode(
            json_encode($this->normalizeSchema($schema), JSON_THROW_ON_ERROR),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function normalizeSchema(mixed $value, ?string $key = null): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if ($value === [] && in_array($key, [
            'properties', 'patternProperties', 'dependentSchemas', '$defs', 'definitions',
        ], true)) {
            return (object) [];
        }
        $normalized = [];
        foreach ($value as $nestedKey => $nested) {
            $normalized[$nestedKey] = $this->normalizeSchema(
                $nested,
                is_string($nestedKey) ? $nestedKey : null,
            );
        }

        return $normalized;
    }

    private function fail(string $code, string $message): never
    {
        throw new AgentPlanValidationException($code, $message);
    }
}
