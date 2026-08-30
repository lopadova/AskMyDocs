<?php

declare(strict_types=1);

namespace App\Agent\Tools;

/**
 * Builds the user-facing live-source catalog and applies a per-run allowlist.
 *
 * The client can only narrow the already authorized registry. Unknown source
 * keys never add capabilities, and an omitted kind preserves the historical
 * "all enabled" behaviour.
 */
final readonly class AgentLiveSourceSelection
{
    /**
     * @param  array<string,AgentToolDefinition>  $tools
     * @return array<string,AgentToolDefinition>
     */
    public function apply(array $tools, mixed $selection): array
    {
        if (! is_array($selection)) {
            return $tools;
        }

        $allowlists = [];
        foreach (['api', 'mcp'] as $kind) {
            if (! array_key_exists($kind, $selection) || ! is_array($selection[$kind])) {
                continue;
            }
            $allowlists[$kind] = array_fill_keys(array_values(array_filter(
                $selection[$kind],
                static fn (mixed $key): bool => is_string($key) && $key !== '',
            )), true);
        }

        return array_filter(
            $tools,
            static function (AgentToolDefinition $tool) use ($allowlists): bool {
                if (! in_array($tool->kind, ['api', 'mcp'], true)) {
                    return true;
                }
                if (! array_key_exists($tool->kind, $allowlists)) {
                    return true;
                }

                $sourceKey = $tool->metadata['source_key'] ?? null;

                return is_string($sourceKey) && isset($allowlists[$tool->kind][$sourceKey]);
            },
        );
    }

    /**
     * @param  array<string,AgentToolDefinition>  $tools
     * @return array{api:list<array<string,mixed>>,mcp:list<array<string,mixed>>}
     */
    public function catalog(array $tools): array
    {
        $sources = ['api' => [], 'mcp' => []];

        foreach ($tools as $tool) {
            if (! in_array($tool->kind, ['api', 'mcp'], true)) {
                continue;
            }
            $sourceKey = $tool->metadata['source_key'] ?? null;
            if (! is_string($sourceKey) || $sourceKey === '') {
                continue;
            }

            $current = $sources[$tool->kind][$sourceKey] ?? null;
            $sources[$tool->kind][$sourceKey] = [
                'key' => $sourceKey,
                'kind' => $tool->kind,
                'name' => is_string($tool->metadata['source_name'] ?? null)
                    ? $tool->metadata['source_name']
                    : $tool->displayName,
                'description' => is_string($tool->metadata['source_description'] ?? null)
                    ? $tool->metadata['source_description']
                    : null,
                'project_key' => is_string($tool->metadata['source_project_key'] ?? null)
                    ? $tool->metadata['source_project_key']
                    : null,
                'tool_count' => (int) ($current['tool_count'] ?? 0) + 1,
            ];
        }

        foreach (['api', 'mcp'] as $kind) {
            $sources[$kind] = array_values($sources[$kind]);
            usort(
                $sources[$kind],
                static fn (array $left, array $right): int => strnatcasecmp(
                    (string) $left['name'],
                    (string) $right['name'],
                ),
            );
        }

        return $sources;
    }
}
