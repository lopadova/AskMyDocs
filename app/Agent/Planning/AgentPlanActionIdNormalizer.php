<?php

declare(strict_types=1);

namespace App\Agent\Planning;

/** Rename opaque graph keys only when their reuse cannot make a dependency ambiguous. */
final class AgentPlanActionIdNormalizer
{
    public function normalize(array $payload, array $completedIds): array
    {
        if (($payload['decision'] ?? null) !== 'tools' || ! is_array($payload['actions'] ?? null)) {
            return $payload;
        }
        $ids = [];
        foreach ($payload['actions'] as $action) {
            $id = is_array($action) ? trim((string) ($action['id'] ?? '')) : '';
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id) !== 1 || isset($ids[$id])) {
                return $payload; // The strict parser must reject malformed/duplicate new keys.
            }
            $ids[$id] = true;
        }
        $collisions = array_intersect(array_keys($ids), $completedIds);
        if ($collisions === []) {
            return $payload;
        }
        foreach ($payload['actions'] as $action) {
            $references = is_array($action['depends_on'] ?? null) ? $action['depends_on'] : [];
            $this->references($action['arguments'] ?? [], $references);
            if (array_intersect(array_filter($references, 'is_string'), $collisions) !== []) {
                return $payload; // It is unclear whether a reference means the old or the new action.
            }
        }
        $reserved = array_fill_keys([...$completedIds, ...array_keys($ids)], true);
        $next = 1;
        foreach ($payload['actions'] as &$action) {
            if (! in_array(trim($action['id']), $collisions, true)) {
                continue;
            }
            while (isset($reserved['step_'.$next])) {
                $next++;
            }
            $action['id'] = 'step_'.$next++;
            $reserved[$action['id']] = true;
        }
        unset($action);

        return $payload;
    }

    private function references(mixed $value, array &$references): void
    {
        if (! is_array($value)) {
            return;
        }
        if (is_string($value['$from'] ?? null)) {
            $references[] = $value['$from'];
        }
        foreach ($value as $nested) {
            $this->references($nested, $references);
        }
    }
}
