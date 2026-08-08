<?php

declare(strict_types=1);

namespace App\Agent\Planning;

/** Resolves planner references without evaluating expressions or arbitrary paths. */
final class AgentArgumentResolver
{
    /**
     * @param  array<string,mixed>  $arguments
     * @param  array<string,array<string,mixed>>  $completedResults
     * @return array<string,mixed>
     */
    public function resolve(array $arguments, array $completedResults): array
    {
        return $this->walk($arguments, $completedResults);
    }

    /**
     * @param  array<string,array<string,mixed>>  $completedResults
     * @return array<mixed>
     */
    private function walk(array $value, array $completedResults): array
    {
        if ($this->isReference($value)) {
            $step = (string) $value['$from'];
            $path = (string) $value['path'];
            if (! array_key_exists($step, $completedResults)) {
                throw new \DomainException("agent_dependency_not_completed:{$step}");
            }
            if (! $this->safePath($path)) {
                throw new \DomainException('agent_dependency_path_invalid');
            }

            $sentinel = new \stdClass;
            $resolved = data_get($completedResults[$step], $path, $sentinel);
            if ($resolved === $sentinel) {
                throw new \DomainException("agent_dependency_value_missing:{$step}:{$path}");
            }

            return is_array($resolved) ? $resolved : [$resolved];
        }

        $resolved = [];
        foreach ($value as $key => $item) {
            if (is_array($item) && $this->isReference($item)) {
                $reference = $this->resolveScalarReference($item, $completedResults);
                $resolved[$key] = $reference;
            } elseif (is_array($item)) {
                $resolved[$key] = $this->walk($item, $completedResults);
            } else {
                $resolved[$key] = $item;
            }
        }

        return $resolved;
    }

    /** @param array<string,mixed> $reference @param array<string,array<string,mixed>> $completedResults */
    private function resolveScalarReference(array $reference, array $completedResults): mixed
    {
        $step = (string) $reference['$from'];
        $path = (string) $reference['path'];
        if (! array_key_exists($step, $completedResults)) {
            throw new \DomainException("agent_dependency_not_completed:{$step}");
        }
        if (! $this->safePath($path)) {
            throw new \DomainException('agent_dependency_path_invalid');
        }

        $sentinel = new \stdClass;
        $resolved = data_get($completedResults[$step], $path, $sentinel);
        if ($resolved === $sentinel) {
            throw new \DomainException("agent_dependency_value_missing:{$step}:{$path}");
        }

        return $resolved;
    }

    /** @param array<mixed> $value */
    private function isReference(array $value): bool
    {
        $keys = array_keys($value);
        sort($keys);

        return ! array_is_list($value)
            && $keys === ['$from', 'path']
            && is_string($value['$from'])
            && is_string($value['path'])
            && $value['$from'] !== '';
    }

    private function safePath(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= 256
            && preg_match('/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_*\-]+)*$/', $path) === 1;
    }
}
