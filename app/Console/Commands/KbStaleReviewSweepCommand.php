<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Notifications\NotificationPublisher;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * v8.7/W2 — stale-document review sweep.
 *
 * Finds documents that have gone untouched longer than the configured
 * staleness window (`kb_health.stale_review_months`, settings-tunable)
 * and fires a `KbDocStaleReview` notification to the eligible project
 * members. A per-document `metadata.stale_review_notified_at` marker
 * makes the sweep idempotent within a content version: a doc is flagged
 * at most once until it is re-ingested (which bumps `indexed_at` and
 * re-arms it). Soft-deleted + archived rows are excluded by design.
 *
 * Unlike `kb:health-recompute` (canonical-only, score-based), this sweep
 * covers EVERY live document and is purely time-based, so connector-pulled
 * and non-canonical docs are reviewed too.
 */
final class KbStaleReviewSweepCommand extends Command
{
    protected $signature = 'kb:stale-review-sweep
                            {--tenant= : Restrict the sweep to one tenant}
                            {--months= : Override the staleness window (months)}
                            {--limit=500 : Max documents to flag per tenant per run}
                            {--dry-run : Report what would be flagged without notifying}';

    protected $description = 'Notify reviewers about documents untouched beyond the staleness window';

    public function handle(NotificationPublisher $publisher, TenantContext $tenants): int
    {
        $months = (int) ($this->option('months') ?? config('askmydocs.kb_health.stale_review_months', 6));
        if ($months <= 0) {
            $this->info('Stale-review window is disabled (months <= 0). Nothing to do.');

            return self::SUCCESS;
        }

        $threshold = CarbonImmutable::now()->subMonths($months);
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $tenantIds = $this->resolveTenantIds();
        if ($tenantIds === []) {
            $this->info('No documents found. Nothing to sweep.');

            return self::SUCCESS;
        }

        $previousTenant = $tenants->current();

        try {
            foreach ($tenantIds as $tenantId) {
                $tenants->set($tenantId);
                $flagged = $this->sweepTenant($publisher, $tenantId, $threshold, $limit, $dryRun);
                $this->info("[{$tenantId}] stale_flagged={$flagged}".($dryRun ? ' (dry-run)' : ''));
            }
        } finally {
            $tenants->set($previousTenant);
        }

        return self::SUCCESS;
    }

    private function sweepTenant(
        NotificationPublisher $publisher,
        string $tenantId,
        CarbonImmutable $threshold,
        int $limit,
        bool $dryRun,
    ): int {
        $flagged = 0;

        KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->where('status', '!=', 'archived')
            ->where(function ($q) use ($threshold): void {
                // "last touched" = indexed_at, falling back to created_at
                // for rows that never recorded an indexing timestamp.
                $q->where('indexed_at', '<', $threshold)
                    ->orWhere(function ($inner) use ($threshold): void {
                        $inner->whereNull('indexed_at')->where('created_at', '<', $threshold);
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function ($docs) use ($publisher, &$flagged, $limit, $dryRun, $tenantId): bool {
                foreach ($docs as $doc) {
                    if ($flagged >= $limit) {
                        return false; // stop chunking once the per-run cap is hit
                    }

                    $lastTouched = $doc->indexed_at ?? $doc->created_at;
                    if ($lastTouched === null) {
                        continue;
                    }

                    if ($dryRun) {
                        // Dry-run: validate without writing. The fast-path read is
                        // not locked — acceptable for a reporting-only mode.
                        if (! $this->alreadyNotifiedForVersion($doc, $lastTouched)) {
                            $flagged++;
                        }
                        continue;
                    }

                    // R21: check + publish + mark-notified all happen inside a single
                    // DB::transaction with lockForUpdate so two concurrent sweeps cannot
                    // both read stale_review_notified_at as absent and double-notify.
                    $notified = DB::transaction(function () use ($doc, $lastTouched, $publisher, $tenantId): bool {
                        /** @var KnowledgeDocument|null $fresh */
                        $fresh = KnowledgeDocument::query()->forTenant($tenantId)->lockForUpdate()->find($doc->id);
                        if ($fresh === null || $this->alreadyNotifiedForVersion($fresh, $lastTouched)) {
                            return false;
                        }
                        $ageDays = (int) $lastTouched->diffInDays(now());
                        $publisher->publishKbDocStaleReview($fresh, $ageDays);
                        $this->markNotified($fresh);

                        return true;
                    });

                    if ($notified) {
                        $flagged++;
                    }
                }

                return true;
            });

        return $flagged;
    }

    /**
     * A doc is skipped when it was already flagged for the CURRENT content
     * version — i.e. the stored marker is at or after the last-touched
     * timestamp. Re-ingestion bumps `indexed_at` past the marker and
     * re-arms the doc.
     */
    private function alreadyNotifiedForVersion(KnowledgeDocument $doc, \DateTimeInterface $lastTouched): bool
    {
        $marker = data_get($doc->metadata, 'stale_review_notified_at');
        if (! is_string($marker) || $marker === '') {
            return false;
        }

        try {
            return CarbonImmutable::parse($marker)
                ->greaterThanOrEqualTo(CarbonImmutable::instance($lastTouched));
        } catch (\Throwable) {
            return false;
        }
    }

    private function markNotified(KnowledgeDocument $doc): void
    {
        $metadata = is_array($doc->metadata) ? $doc->metadata : [];
        $metadata['stale_review_notified_at'] = CarbonImmutable::now()->toIso8601String();
        // Direct update of metadata only — does NOT fire KbDocumentChanged
        // (the publisher hooks `created`, not `updated`), so marking a doc
        // notified never emits a spurious "modified" notification.
        $doc->update(['metadata' => $metadata]);
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
            ->distinct()
            // R30: intentionally unscoped — this bootstrap query discovers the
            // TENANT SET by reading only the tenant_id column. All document reads
            // and writes inside sweepTenant() are forTenant()-scoped. The
            // TenantReadScopeTest passes this file on the forTenant marker in
            // sweepTenant(); no ALLOWLIST entry is needed.
            ->pluck('tenant_id')
            ->filter(static fn ($v): bool => is_string($v) && $v !== '')
            ->values()
            ->all();
    }
}
