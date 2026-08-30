<?php

declare(strict_types=1);

namespace App\Agent\Capabilities;

use App\Agent\Tools\AgentToolDefinition;

final readonly class AgentCapabilitySnapshotBuilder
{
    public function __construct(private AgentCapabilityHint $hints) {}

    /** @param array<string,AgentToolDefinition> $tools */
    public function build(array $tools): AgentCapabilitySnapshot
    {
        $capabilities = [];
        foreach ($tools as $tool) {
            $capabilities[$tool->name] = $this->capability($tool, $tools);
        }

        ksort($capabilities);
        $encoded = json_encode(array_map(
            static fn (AgentCapabilityDefinition $item): array => $item->jsonSerialize(),
            $capabilities,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new AgentCapabilitySnapshot($capabilities, hash('sha256', $encoded), strlen($encoded));
    }

    /** @param array<string,AgentToolDefinition> $tools */
    private function capability(AgentToolDefinition $tool, array $tools): AgentCapabilityDefinition
    {
        $hint = $this->hints->sanitize($tool->metadata['agent_capability_hint'] ?? null);
        [$operation, $entity] = $this->inferIdentity($tool);
        $outputSchema = is_array($tool->metadata['output_schema'] ?? null)
            ? $tool->metadata['output_schema']
            : null;
        $collectionPath = $hint['collection_path'] ?? $this->collectionPath($tool, $outputSchema);
        $identityFields = $hint['identity_fields'] ?? $this->identityFields($outputSchema, $collectionPath);
        $nextTools = isset($hint['next_tools'])
            ? array_values(array_filter($hint['next_tools'], static fn (string $name): bool => isset($tools[$name])))
            : $this->relations($tool, $tools);
        $required = is_array($tool->inputSchema['required'] ?? null)
            ? array_values(array_filter($tool->inputSchema['required'], 'is_string'))
            : [];
        $risk = is_string($tool->metadata['risk'] ?? null)
            ? $tool->metadata['risk']
            : ($tool->readOnly ? 'read' : 'write');

        return new AgentCapabilityDefinition(
            tool: $tool->name,
            source: $tool->kind,
            entity: (string) ($hint['entity'] ?? $entity),
            operation: (string) ($hint['operation'] ?? $operation),
            requiredInputs: $hint['requires'] ?? $required,
            collectionPath: $collectionPath,
            identityFields: $identityFields,
            nextTools: $nextTools,
            intentTags: $hint['intent_tags'] ?? $this->intentTags($tool, $entity, $operation),
            readOnly: $tool->readOnly,
            idempotent: $tool->idempotent,
            confirmationRequired: (bool) ($tool->metadata['confirmation_required'] ?? false),
            risk: $risk,
            outputSchema: $outputSchema,
            inference: $hint === null ? 'standard' : 'advisory_hint',
        );
    }

    /** @return array{string,string} */
    private function inferIdentity(AgentToolDefinition $tool): array
    {
        if ($tool->kind === 'knowledge') {
            return ['search', 'knowledge'];
        }

        $name = strtolower($tool->name.' '.$tool->displayName);
        $name = preg_replace('/\b[0-9a-f]{8,}\b/i', ' ', $name) ?? $name;
        $tokens = array_values(array_filter(preg_split('/[^a-z0-9]+/', $name) ?: []));
        $operations = [
            'search' => ['search', 'find', 'lookup', 'query'],
            'list' => ['list', 'all', 'index'],
            'detail' => ['detail', 'details', 'show'],
            'get' => ['get', 'fetch', 'retrieve'],
            'summary' => ['summary', 'overview', 'aggregate', 'stats', 'statistics'],
            'count' => ['count', 'total'],
            'check' => ['check', 'exists', 'status'],
        ];
        $operation = 'get';
        $operationTokens = [];
        foreach ($operations as $candidate => $aliases) {
            $matches = array_intersect($tokens, $aliases);
            if ($matches !== []) {
                $operation = $candidate;
                $operationTokens = array_merge($operationTokens, $aliases);
                break;
            }
        }
        $noise = array_merge($operationTokens, ['api', 'mcp', 'tool', 'my', 'by', 'for', 'with']);
        $entities = array_values(array_diff(array_unique($tokens), $noise));
        $entity = $entities === [] ? 'data' : implode('_', array_slice($entities, 0, 3));

        return [$operation, $entity];
    }

    /** @param array<string,mixed>|null $schema */
    private function collectionPath(AgentToolDefinition $tool, ?array $schema): ?string
    {
        $configured = $tool->metadata['items_path'] ?? null;
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }
        if (! is_array($schema)) {
            return null;
        }
        if (($schema['type'] ?? null) === 'array') {
            return '$';
        }
        foreach (is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $key => $property) {
            if (is_string($key) && is_array($property) && ($property['type'] ?? null) === 'array') {
                return $key;
            }
        }

        return null;
    }

    /** @param array<string,mixed>|null $schema @return list<string> */
    private function identityFields(?array $schema, ?string $collectionPath): array
    {
        if (! is_array($schema)) {
            return [];
        }
        $node = $schema;
        if ($collectionPath === '$' && is_array($schema['items'] ?? null)) {
            $node = $schema['items'];
        } elseif (is_string($collectionPath) && $collectionPath !== '') {
            foreach (explode('.', $collectionPath) as $segment) {
                if ($segment === '$') {
                    continue;
                }
                $node = is_array($node['properties'][$segment] ?? null) ? $node['properties'][$segment] : [];
            }
            if (is_array($node['items'] ?? null)) {
                $node = $node['items'];
            }
        }
        $properties = is_array($node['properties'] ?? null) ? array_keys($node['properties']) : [];
        $preferred = ['id', 'public_id', 'uuid', 'code', 'number', 'slug', 'email'];

        return array_values(array_intersect($preferred, $properties));
    }

    /** @param array<string,AgentToolDefinition> $tools @return list<string> */
    private function relations(AgentToolDefinition $tool, array $tools): array
    {
        $relations = is_array($tool->metadata['relations'] ?? null) ? $tool->metadata['relations'] : [];
        $names = [];
        foreach ($relations as $relation) {
            $candidate = is_array($relation) ? ($relation['detail_tool'] ?? null) : null;
            if (is_string($candidate) && isset($tools[$candidate])) {
                $names[] = $candidate;
            }
        }

        return array_values(array_unique($names));
    }

    /** @return list<string> */
    private function intentTags(AgentToolDefinition $tool, string $entity, string $operation): array
    {
        $tokens = preg_split('/[^a-z0-9]+/', strtolower($tool->name.' '.$entity.' '.$operation)) ?: [];

        return array_values(array_slice(array_unique(array_filter($tokens)), 0, 12));
    }
}
