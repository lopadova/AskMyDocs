<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\KnowledgeDocument;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\WidgetKey;
use App\Support\TenantContext;

/**
 * Canonical catalogue of project keys that already exist in the active tenant.
 *
 * Projects pre-date the first-class registry, so a key may legitimately live
 * in any of the historical reference tables below. Keeping that union here
 * gives every picker and validator the same tenant boundary and prevents a
 * newly-created widget from being bound to an arbitrary free-form key.
 */
final class ProjectCatalogService
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Return the active tenant's non-empty project keys, strictly unique and sorted.
     *
     * Soft-deleted documents intentionally remain a source: an operator must
     * still be able to select their project while restoring or governing it.
     * Revoked widget keys are also intentionally included.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $tenantId = $this->tenant->current();

        return Project::query()
            ->forTenant($tenantId)
            ->pluck('project_key')
            ->concat(
                KnowledgeDocument::query()
                    ->forTenant($tenantId)
                    ->withTrashed()
                    ->whereNotNull('project_key')
                    ->distinct()
                    ->pluck('project_key'),
            )
            ->concat(
                ProjectMembership::query()
                    ->forTenant($tenantId)
                    ->whereNotNull('project_key')
                    ->distinct()
                    ->pluck('project_key'),
            )
            ->concat(
                WidgetKey::query()
                    ->forTenant($tenantId)
                    ->whereNotNull('project_key')
                    ->distinct()
                    ->pluck('project_key'),
            )
            ->filter(static fn (mixed $key): bool => is_string($key) && trim($key) !== '')
            ->uniqueStrict()
            ->sort()
            ->values()
            ->all();
    }

    /** Whether the exact key is already represented in the active tenant. */
    public function contains(string $projectKey): bool
    {
        return trim($projectKey) !== '' && in_array($projectKey, $this->keys(), true);
    }
}
