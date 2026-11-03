<?php

declare(strict_types=1);

namespace App\Agent\Evidence;

use App\Services\Chat\Reasoning\EvidenceProjector;

/** Exact host-recorded argument dependencies; remote payloads never declare links. */
final class ToolResultLineage
{
    public function capture(array $planned, array $resolved, array $completed, array $evidence): array
    {
        $bindings = [];
        $walk = function (array $arguments, string $prefix = '', int $depth = 0) use (&$walk, &$bindings, $resolved, $completed, $evidence): void {
            if ($depth > 12 || count($bindings) >= 32) return;
            foreach ($arguments as $key => $value) {
                $argumentPath = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                if (! is_array($value)) continue;
                if (count($value) !== 2 || ! is_string($value['$from'] ?? null) || ! $this->exactPath($value['path'] ?? null)) {
                    $walk($value, $argumentPath, $depth + 1);
                    continue;
                }
                $parents = array_values(array_filter($completed, fn ($entry) => ($entry['id'] ?? null) === $value['$from']
                    && ($entry['status'] ?? null) === 'completed' && is_int($entry['execution_id'] ?? null)));
                if (count($parents) !== 1) continue;
                $parent = $this->observation($evidence, $parents[0]['execution_id']);
                if ($parent === null || ! $this->intact($parent) || $this->parentRecord($parent, $value['path']) === null) continue;
                $identifier = $this->identifier(data_get($parent['result'], $value['path']));
                if ($identifier === null || $identifier !== $this->identifier(data_get($resolved, $argumentPath))) continue;
                $bindings[] = ['parent_execution_id' => $parent['execution_id'], 'parent_evidence_hash' => $parent['evidence_hash'],
                    'source_path' => $value['path'], 'argument_path' => $argumentPath, 'value_hash' => hash('sha256', $identifier)];
            }
        };
        $walk($planned);

        return $bindings;
    }

    /** Context verifies identity only; operational values still belong to the child record. */
    public function contexts(array $evidence, array $source, string $recordPath, string $tenant = '', string $project = '', int $depth = 0, ?int &$remaining = null): array
    {
        $remaining ??= 64;
        $bindings = $source['argument_bindings'] ?? [];
        if ($remaining-- <= 0 || $depth >= 8 || ! is_array($bindings) || $bindings === []
            || ! $this->intact($source) || ! $this->inScope($source, $tenant, $project)) return [];
        $records = array_values(array_filter(EvidenceProjector::records($source), fn ($r) => $r['path'] === $recordPath));
        if (count($records) !== 1) return [];
        $identities = EvidenceProjector::identities($records[0]['record'], $source['presentation']['identity_fields'] ?? []);
        if ($identities === []) return [];
        $contexts = [];
        foreach (array_slice($bindings, 0, 32) as $binding) {
            if (count($contexts) >= 64) break;
            if (! is_array($binding) || ! is_int($binding['parent_execution_id'] ?? null)
                || $binding['parent_execution_id'] >= ($source['execution_id'] ?? 0)
                || ! is_string($binding['value_hash'] ?? null)
                || ! $this->exactPath($binding['source_path'] ?? null) || ! $this->exactPath($binding['argument_path'] ?? null)) continue;
            $parent = $this->observation($evidence, $binding['parent_execution_id']);
            if ($parent === null || ! $this->inScope($parent, $tenant, $project) || ! $this->intact($parent)
                || ($binding['parent_evidence_hash'] ?? null) !== $parent['evidence_hash']) continue;
            $entry = $this->parentRecord($parent, $binding['source_path']);
            $identifier = $this->identifier(data_get($parent['result'], $binding['source_path']));
            if ($entry === null || $identifier === null || ! in_array($identifier, $identities, true)
                || $identifier !== $this->identifier(data_get($source['arguments'] ?? [], $binding['argument_path']))
                || ! hash_equals(hash('sha256', $identifier), (string) ($binding['value_hash'] ?? ''))) continue;
            $relative = $entry['path'] === '$' ? $binding['source_path'] : substr($binding['source_path'], strlen($entry['path']) + 1);
            $values = $this->pathValues($entry['record'], $relative);
            $contexts[] = ['execution_id' => $parent['execution_id'], 'evidence_hash' => $parent['evidence_hash'],
                'record_path' => $entry['path'], 'source_path' => $binding['source_path'], 'argument_path' => $binding['argument_path'],
                'identifiers' => $values, 'root_identifiers' => EvidenceProjector::identities($entry['record'], $parent['presentation']['identity_fields'] ?? []),
                'quote' => json_encode($entry['record'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'record' => $entry['record']];
            $contexts = array_slice([...$contexts, ...$this->contexts($evidence, $parent, $entry['path'], $tenant, $project, $depth + 1, $remaining)], 0, 64);
        }

        return $contexts;
    }

    public function matches(array $contexts, array $identifiers): bool
    {
        $anchored = false;
        foreach ($contexts as $context) {
            // A parent identity alone cannot authorize the wrong requested item.
            // A multi-item focus may include siblings; this record must still
            // follow at least one of the requested item paths.
            $all = [];
            array_walk_recursive($context['record'], function ($value) use (&$all): void {
                if (($id = $this->identifier($value)) !== null) $all[] = $id;
            });
            $specific = array_diff(array_intersect($identifiers, $all), $context['root_identifiers']);
            if ($specific !== [] && array_intersect($specific, $context['identifiers']) === []) return false;
            $anchored = $anchored || array_intersect($identifiers, $context['root_identifiers']) !== [];
        }

        return $anchored;
    }

    private function observation(array $evidence, int $execution): ?array
    {
        $matches = array_values(array_filter($evidence['api_tools'] ?? [], fn ($t) => ($t['execution_id'] ?? null) === $execution));
        return count($matches) === 1 ? $matches[0] : null;
    }

    private function parentRecord(array $source, string $path): ?array
    {
        $records = array_values(array_filter(EvidenceProjector::records($source), fn ($r) => $r['path'] === '$'
            || str_starts_with($path, $r['path'].'.')));
        return count($records) === 1 ? $records[0] : null;
    }

    private function pathValues(array $result, string $path): array
    {
        $values = [];
        $cursor = $result;
        foreach (explode('.', $path) as $part) {
            if (! is_array($cursor) || ! array_key_exists($part, $cursor)) return [];
            if (! array_is_list($cursor)) {
                foreach ($cursor as $value) if (($id = $this->identifier($value)) !== null) $values[] = $id;
            }
            $cursor = $cursor[$part];
        }
        if (($id = $this->identifier($cursor)) !== null) $values[] = $id;
        return array_values(array_unique($values));
    }

    private function intact(array $source): bool
    {
        $encoded = json_encode($source['result'] ?? null, JSON_UNESCAPED_UNICODE);
        return is_int($source['execution_id'] ?? null) && is_array($source['result'] ?? null)
            && is_string($source['evidence_hash'] ?? null) && is_string($encoded)
            && hash_equals($source['evidence_hash'], hash('sha256', $encoded));
    }

    private function inScope(array $source, string $tenant, string $project): bool
    {
        return (! isset($source['tenant_id']) || $source['tenant_id'] === $tenant)
            && (! isset($source['project_key']) || $source['project_key'] === $project)
            && (! isset($source['result']['companyKey']) || $source['result']['companyKey'] === $project);
    }

    private function exactPath(mixed $path): bool
    {
        return is_string($path) && strlen($path) <= 256 && preg_match('/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/', $path) === 1;
    }

    private function identifier(mixed $value): ?string
    {
        if (! is_int($value) && ! is_string($value)) return null;
        $value = (string) $value;
        return $value !== '' && strlen($value) <= 256 && ! preg_match('/\[[A-Z_]+\]/', $value) ? $value : null;
    }
}
