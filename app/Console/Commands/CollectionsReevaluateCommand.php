<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\EvaluateCollectionsJob;
use App\Models\KbCollection;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Console\Command;

final class CollectionsReevaluateCommand extends Command
{
    protected $signature = 'collections:reevaluate
                            {--tenant= : Restrict reevaluation to one tenant}
                            {--collection= : Restrict reevaluation to one collection id}';

    protected $description = 'Reevaluate document membership for KB collections';

    public function handle(TenantContext $tenants): int
    {
        $tenantIds = $this->resolveTenantIds();
        if ($tenantIds === []) {
            $this->info('No matching collections/documents found. Nothing to reevaluate.');

            return self::SUCCESS;
        }

        $collectionFilter = $this->collectionFilterId();
        $previousTenant = $tenants->current();

        try {
            foreach ($tenantIds as $tenantId) {
                $tenants->set($tenantId);

                $docsQuery = KnowledgeDocument::query()->forTenant($tenantId);
                if ($collectionFilter !== null) {
                    $collection = KbCollection::query()
                        ->forTenant($tenantId)
                        ->find($collectionFilter);
                    if ($collection === null) {
                        $this->warn("[{$tenantId}] collection {$collectionFilter} not found; skipping.");
                        continue;
                    }
                }

                $processed = 0;
                $docsQuery->chunkById(100, function ($docs) use (&$processed, $tenantId, $collectionFilter): void {
                    foreach ($docs as $doc) {
                        EvaluateCollectionsJob::dispatchSync((int) $doc->id, $tenantId, $collectionFilter);
                        $processed++;
                    }
                });

                $this->info("[{$tenantId}] reevaluated_documents={$processed}");
            }
        } finally {
            $tenants->set($previousTenant);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveTenantIds(): array
    {
        $explicit = (string) ($this->option('tenant') ?? '');
        if ($explicit !== '') {
            return [$explicit];
        }

        return KbCollection::query()
            ->distinct()
            ->pluck('tenant_id')
            ->filter(static fn ($v): bool => is_string($v) && $v !== '')
            ->values()
            ->all();
    }

    private function collectionFilterId(): ?int
    {
        $raw = $this->option('collection');
        if ($raw === null || $raw === '') {
            return null;
        }

        return max(1, (int) $raw);
    }
}
