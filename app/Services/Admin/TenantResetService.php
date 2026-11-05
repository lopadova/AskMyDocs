<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentDeleter;
use App\Support\SystemTenantRegistry;
use App\Support\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;

/**
 * Permanently clear one operational tenant and recreate only its registry row.
 *
 * Users are global identities in AskMyDocs, so this service deliberately never
 * deletes from `users`, roles, permissions or personal access tokens. Every
 * table carrying the tenant isolation key (`tenant_id`) is discovered from the
 * live schema instead of a hand-maintained list, making the reset include new
 * host and package tables automatically.
 */
final class TenantResetService
{
    public function __construct(
        private readonly DocumentDeleter $documents,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return array{tenant: Tenant, table_counts: array<string, int>, total_rows: int}
     */
    public function inspect(string $slug): array
    {
        $this->assertSlugIsResettable($slug);
        $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
        $this->assertTenantIsResettable($tenant);
        $counts = [];

        foreach ($this->tenantTablesInDeleteOrder() as $table) {
            $count = DB::table($table)->where('tenant_id', $slug)->count();
            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        return [
            'tenant' => $tenant,
            'table_counts' => $counts,
            'total_rows' => array_sum($counts),
        ];
    }

    /**
     * @return array{
     *   tenant: Tenant,
     *   deleted_rows: array<string, int>,
     *   total_deleted: int,
     *   documents_deleted: int,
     *   files_deleted: int
     * }
     */
    public function reset(string $slug, bool $deleteFiles = true): array
    {
        $this->assertSlugIsResettable($slug);

        // Resolve and validate the dependency graph before deleting any files.
        // A schema cycle must fail without creating a partial filesystem reset.
        $tables = $this->tenantTablesInDeleteOrder();
        $previousTenant = $this->tenantContext->current();
        $this->tenantContext->set($slug);

        try {
            $result = DB::transaction(function () use ($slug, $deleteFiles, $tables): array {
                $tenant = Tenant::query()
                    ->where('slug', $slug)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertTenantIsResettable($tenant);
                $replacementAttributes = $this->replacementAttributes($tenant);
                $deletedRows = [];

                foreach ($tables as $table) {
                    $count = DB::table($table)->where('tenant_id', $slug)->count();
                    if ($count > 0) {
                        $deletedRows[$table] = $count;
                    }
                }

                $documentsDeleted = KnowledgeDocument::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $slug)
                    ->count();
                $filesToDelete = [];

                if ($deleteFiles) {
                    KnowledgeDocument::query()
                        ->withoutGlobalScopes()
                        ->where('tenant_id', $slug)
                        ->orderBy('id')
                        ->chunkById(100, function ($documents) use (&$filesToDelete): void {
                            foreach ($documents as $document) {
                                $deleted = $this->documents->deleteRowsOnly($document);
                                $filesToDelete[] = [
                                    'disk' => $deleted['disk'],
                                    'full_path' => $deleted['full_path'],
                                    'document_id' => $deleted['document_id'],
                                    'source_path' => $deleted['source_path'],
                                ];
                            }
                        });
                }

                foreach ($tables as $table) {
                    DB::table($table)->where('tenant_id', $slug)->delete();
                }

                $tenant->delete();
                $replacement = Tenant::query()->create($replacementAttributes);

                return [
                    'tenant' => $replacement,
                    'deleted_rows' => $deletedRows,
                    'total_deleted' => array_sum($deletedRows),
                    'documents_deleted' => $documentsDeleted,
                    'files_to_delete' => $filesToDelete,
                ];
            });

            // Storage cannot participate in the database transaction. Remove
            // files only after the tenant reset commits so a later FK/DB
            // failure can never leave restored rows pointing at lost bytes.
            $filesDeleted = 0;
            foreach ($result['files_to_delete'] as $file) {
                if (
                    $file['full_path'] !== ''
                    && $this->documents->removeFileFor(
                        $file['disk'],
                        $file['full_path'],
                        $file['document_id'],
                        $file['source_path'],
                    )
                ) {
                    $filesDeleted++;
                }
            }

            unset($result['files_to_delete']);
            $result['files_deleted'] = $filesDeleted;

            return $result;
        } finally {
            $this->tenantContext->set($previousTenant);
        }
    }

    /**
     * Preserve the tenant's control-plane profile while allocating a fresh
     * registry id and timestamps. Associated projects/config/data are stored
     * in tenant-aware tables and are intentionally not recreated.
     *
     * @return array<string, mixed>
     */
    private function replacementAttributes(Tenant $tenant): array
    {
        $attributes = ['slug' => $tenant->slug, 'name' => $tenant->name];

        foreach ([
            'subscription_tier',
            'status',
            'dpo_email',
            'contact_email',
            'config_overrides_json',
            'suspended_at',
            'archived_at',
        ] as $column) {
            if (Schema::hasColumn('tenants', $column)) {
                $attributes[$column] = $tenant->getAttribute($column);
            }
        }

        if (Schema::hasColumn('tenants', 'is_system')) {
            $attributes['is_system'] = false;
        }

        return $attributes;
    }

    private function assertSlugIsResettable(string $slug): void
    {
        if (SystemTenantRegistry::isReserved($slug)) {
            throw new LogicException("Refusing to reset reserved tenant '{$slug}'.");
        }
    }

    private function assertTenantIsResettable(Tenant $tenant): void
    {
        if (Schema::hasColumn('tenants', 'is_system') && (bool) $tenant->getAttribute('is_system')) {
            throw new LogicException("Refusing to reset system tenant '{$tenant->slug}'.");
        }
    }

    /**
     * Return every tenant-aware table with children before their FK parents.
     * Query-builder deletes intentionally bypass model/global scopes: the
     * tenant_id predicate is the complete and explicit deletion boundary.
     *
     * @return list<string>
     */
    private function tenantTablesInDeleteOrder(): array
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $tablesByName = [];

        foreach ($schema->getTables($schema->getCurrentSchemaListing()) as $definition) {
            $name = (string) ($definition['name'] ?? '');
            $qualified = (string) ($definition['schema_qualified_name'] ?? $name);

            if ($name === '' || $name === 'tenants' || ! $schema->hasColumn($qualified, 'tenant_id')) {
                continue;
            }

            $tablesByName[$name] = $qualified;
        }

        return $this->sortChildrenBeforeParents($connection, $tablesByName);
    }

    /**
     * @param  array<string, string>  $tablesByName
     * @return list<string>
     */
    private function sortChildrenBeforeParents(ConnectionInterface $connection, array $tablesByName): array
    {
        $schema = $connection->getSchemaBuilder();
        $edges = array_fill_keys(array_keys($tablesByName), []);
        $incoming = array_fill_keys(array_keys($tablesByName), 0);

        foreach ($tablesByName as $childName => $qualified) {
            foreach ($schema->getForeignKeys($qualified) as $foreignKey) {
                $parentName = (string) ($foreignKey['foreign_table'] ?? '');
                if ($parentName === $childName || ! array_key_exists($parentName, $tablesByName)) {
                    continue;
                }

                if (! in_array($parentName, $edges[$childName], true)) {
                    $edges[$childName][] = $parentName;
                    $incoming[$parentName]++;
                }
            }
        }

        $ready = array_keys(array_filter($incoming, static fn (int $count): bool => $count === 0));
        sort($ready);
        $orderedNames = [];

        while ($ready !== []) {
            $child = array_shift($ready);
            $orderedNames[] = $child;

            foreach ($edges[$child] as $parent) {
                $incoming[$parent]--;
                if ($incoming[$parent] === 0) {
                    $ready[] = $parent;
                    sort($ready);
                }
            }
        }

        if (count($orderedNames) !== count($tablesByName)) {
            $cyclic = array_keys(array_filter($incoming, static fn (int $count): bool => $count > 0));
            sort($cyclic);

            throw new LogicException(
                'Cannot safely reset the tenant because tenant-aware tables contain an FK cycle: '.implode(', ', $cyclic),
            );
        }

        return array_values(array_map(
            static fn (string $name): string => $tablesByName[$name],
            $orderedNames,
        ));
    }
}
