<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\AgentRun;
use App\Models\User;
use App\Services\Kb\Retrieval\RetrievalFilters;

/** Rebuilds persisted filters inside the queue and narrows them to current ACLs. */
final class AgentRetrievalFiltersFactory
{
    public function forRun(AgentRun $run, AgentExecutionContext $context): RetrievalFilters
    {
        $raw = data_get($run->input_json, 'filters', []);
        $raw = is_array($raw) ? $raw : [];

        return new RetrievalFilters(
            projectKeys: $this->projects($run, $context, $raw),
            tagSlugs: $this->strings($raw['tag_slugs'] ?? []),
            sourceTypes: $this->strings($raw['source_types'] ?? []),
            canonicalTypes: $this->strings($raw['canonical_types'] ?? []),
            connectorTypes: $this->strings($raw['connector_types'] ?? []),
            docIds: array_values(array_filter(array_map(
                'intval',
                is_array($raw['doc_ids'] ?? null) ? $raw['doc_ids'] : [],
            ), static fn (int $id): bool => $id > 0)),
            collectionId: isset($raw['collection_id']) && is_numeric($raw['collection_id'])
                ? max(1, (int) $raw['collection_id'])
                : null,
            folderGlobs: $this->strings($raw['folder_globs'] ?? []),
            dateFrom: $this->date($raw['date_from'] ?? null),
            dateTo: $this->date($raw['date_to'] ?? null),
            languages: array_map('strtolower', $this->strings($raw['languages'] ?? [])),
        );
    }

    /** @param array<string,mixed> $raw @return list<string> */
    private function projects(AgentRun $run, AgentExecutionContext $context, array $raw): array
    {
        if ($context->projectKey !== null) {
            return [$context->projectKey];
        }

        $requested = $this->strings($raw['project_keys'] ?? []);
        $user = $run->user;
        if (! $user instanceof User) {
            // Widgets are always single-project. A project-less non-user run
            // has no safe knowledge scope and must match nothing.
            return ['__agent_no_project_access__'];
        }

        $allowed = $user->allowedProjects();
        if (in_array(User::PROJECT_WILDCARD, $allowed, true)) {
            return $requested;
        }
        if ($allowed === []) {
            return ['__agent_no_project_access__'];
        }
        if ($requested === []) {
            return array_values($allowed);
        }

        $intersection = array_values(array_intersect($requested, $allowed));

        return $intersection === [] ? ['__agent_no_project_access__'] : $intersection;
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $value,
        ), static fn (string $item): bool => $item !== '')));
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
