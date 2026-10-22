<?php

declare(strict_types=1);

namespace App\Agent\Planning;

use App\Agent\Tools\AgentToolDefinition;
use Opis\JsonSchema\Validator;

/** Enforce declared API string patterns without guessing or rewriting identifiers. */
final readonly class AgentPlanArgumentConstraints
{
    public function __construct(private Validator $schema = new Validator) {}

    public function validate(AgentPlan $plan, array $tools): void
    {
        foreach ($plan->actions as $action) {
            $this->validateArguments($tools[$action->tool], $action->arguments, allowReferences: true);
        }
    }

    public function validateArguments(AgentToolDefinition $tool, array $arguments, bool $allowReferences = false): void
    {
        if ($tool->kind !== 'api') {
            return;
        }
        foreach ($tool->inputSchema['properties'] ?? [] as $field => $definition) {
            if (! is_array($definition) || ! is_string($definition['pattern'] ?? null)
                || ! array_key_exists($field, $arguments)) {
                continue;
            }
            $value = $arguments[$field];
            if ($allowReferences && is_array($value) && isset($value['$from'], $value['path']) && count($value) === 2) {
                continue; // The same constraint is checked again after reference resolution.
            }
            try {
                $valid = $this->schema->validate($value, (object) ['type' => 'string', 'pattern' => $definition['pattern']])->isValid();
            } catch (\Throwable $exception) {
                throw new AgentPlanValidationException('input_pattern_invalid', "Invalid declared input pattern for [{$tool->name}.{$field}].");
            }
            if (! $valid) {
                throw new AgentPlanValidationException('input_pattern_mismatch',
                    "Argument [{$field}] for [{$tool->name}] must match its declared pattern [{$definition['pattern']}]. Select the matching tool; never rewrite an entity identifier to fit.");
            }
        }
    }
}
