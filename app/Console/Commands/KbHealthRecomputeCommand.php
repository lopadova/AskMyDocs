<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KbCanonicalHealthSnapshot;
use App\Models\KnowledgeDocument;
use App\Notifications\Events\KbDecisionDebtThreshold;
use App\Services\Kb\KbHealthService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

final class KbHealthRecomputeCommand extends Command
{
    protected $signature = 'kb:health-recompute
                            {--tenant= : Restrict recompute to one tenant}
                            {--emit-events : Emit kb_decision_debt_threshold when threshold exceeded}';

    protected $description = 'Recompute canonical KB health snapshots per tenant';

    public function handle(KbHealthService $health, TenantContext $tenants): int
    {
        $tenantIds = $this->resolveTenantIds();
        if ($tenantIds === []) {
            $this->info('No canonical documents found. Nothing to recompute.');

            return self::SUCCESS;
        }

        $previousTenant = $tenants->current();
        $threshold = (int) config('askmydocs.kb_health.threshold_event_score', 70);
        $emitEvents = (bool) $this->option('emit-events');

        try {
            foreach ($tenantIds as $tenantId) {
                $tenants->set($tenantId);
                $processed = 0;
                $crossed = 0;

                KnowledgeDocument::query()
                    ->forTenant($tenantId)
                    ->canonical()
                    ->chunkById(100, function ($docs) use ($health, &$processed, &$crossed, $threshold, $emitEvents): void {
                        foreach ($docs as $doc) {
                            $row = $health->score($doc);
                            KbCanonicalHealthSnapshot::query()->updateOrCreate(
                                [
                                    'tenant_id' => (string) $doc->tenant_id,
                                    'knowledge_document_id' => (int) $doc->id,
                                ],
                                [
                                    'project_key' => (string) $doc->project_key,
                                    'doc_slug' => $doc->slug,
                                    'health_score' => (int) $row['health_score'],
                                    'factors' => $row['factors'],
                                    'computed_at' => now(),
                                ],
                            );
                            $processed++;
                            if ((int) $row['health_score'] >= $threshold) {
                                $crossed++;
                                if ($emitEvents) {
                                    event(new KbDecisionDebtThreshold(
                                        recipients: [null],
                                        payload: [
                                            'title' => 'KB decision debt threshold exceeded',
                                            'body' => sprintf(
                                                'Document %s in project %s scored %d (threshold %d).',
                                                (string) ($doc->slug ?: $doc->id),
                                                (string) $doc->project_key,
                                                (int) $row['health_score'],
                                                $threshold,
                                            ),
                                            'document_id' => (int) $doc->id,
                                            'project_key' => (string) $doc->project_key,
                                            'slug' => $doc->slug,
                                            'health_score' => (int) $row['health_score'],
                                            'threshold' => $threshold,
                                        ],
                                        tenantId: (string) $doc->tenant_id,
                                    ));
                                }
                            }
                        }
                    });

                $this->info("[{$tenantId}] recomputed={$processed} threshold_crossed={$crossed}");
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

        return KnowledgeDocument::query()
            ->canonical()
            ->distinct()
            ->pluck('tenant_id')
            ->filter(static fn ($v): bool => is_string($v) && $v !== '')
            ->values()
            ->all();
    }
}
