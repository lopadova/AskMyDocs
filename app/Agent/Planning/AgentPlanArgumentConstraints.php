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

    /**
     * After the bounded correction failed, keep only independently valid branches.
     * Malformed schemas and other plan errors still fail closed. No value is rewritten.
     * @param list<string> $completedActionIds
     */
    public function independentValidBranches(AgentPlan $plan, array $tools, array $completedActionIds = []): AgentPlan
    {
        $kept = $rejected = [];
        $rejectedIds = [];
        foreach ($plan->actions as $action) {
            $dependencies = $action->dependsOn;
            $arguments = $action->arguments;
            array_walk_recursive($arguments, static function ($value, $key) use (&$dependencies): void {
                if ($key === '$from' && is_string($value)) {
                    $dependencies[] = $value;
                }
            });
            $blocked = array_intersect($dependencies, $rejectedIds) !== [];
            if (! $blocked) {
                try {
                    $this->validateArguments($tools[$action->tool], $action->arguments, allowReferences: true);
                } catch (AgentPlanValidationException $exception) {
                    if ($exception->validationCode !== 'input_pattern_mismatch') {
                        throw $exception;
                    }
                    $blocked = true;
                }
            }
            if ($blocked) {
                $rejected[] = $action;
                $rejectedIds[] = $action->id;
            } else {
                $kept[] = $action;
            }
        }
        if ($kept === [] || $rejected === []) {
            throw new AgentPlanValidationException('input_pattern_mismatch', 'No independent valid branch remains after input pattern checks.');
        }

        // Reparse the reduced graph, including all dependency checks and fresh estimates.
        $validated = (new AgentPlanParser)->parse(['decision' => 'tools', 'actions' => array_map(
            static fn (AgentPlannedAction $action): array => $action->jsonSerialize(), $kept,
        )], $tools, count($kept), $completedActionIds);

        return new AgentPlan($validated->decision, $validated->actions, $validated->estimate, $rejected);
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
                    "Argument [{$field}] for [{$tool->name}] must match its declared pattern [{$definition['pattern']}]. Select the matching tool; never rewrite an entity identifier to fit. The supplied value is ".json_encode($value).'. Remove this incompatible action or select its correct tool, preserving independent valid actions.');
            }
        }
    }
}
