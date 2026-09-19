<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KbTextCorrectionCandidate;
use App\Services\Kb\Review\KbReviewService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.37/W3b round 5 (Copilot PR #496, H-B) — a thin dispatcher over
 * {@see KbReviewService::reconcileStuckCorrections()}, the shared core that
 * finds correction candidates stuck in `applying`
 * (`approveCorrection()`'s phase-1 claim, never flipped to a terminal state
 * because the process crashed somewhere in phase 2/3) and resolves each one
 * — finalized to `applied` when `kb_canonical_audit` proves its correction
 * genuinely committed, otherwise reverted to `pending` so a reviewer can
 * simply retry.
 *
 * R44 — a documented single-surface exception: this is a scheduler-only
 * maintenance sweep with no caller-facing read (nobody asks "reconcile my
 * stuck corrections" via HTTP/MCP; an operator or the scheduler runs this),
 * so CLI-only is the complete tri-surface story here, exactly like
 * `kb:prune-deleted` / `kb:artifacts-backfill` never needed an HTTP/MCP
 * counterpart either.
 */
final class KbReviewReconcileStuckCorrectionsCommand extends Command
{
    protected $signature = 'kb:review-reconcile-stuck-corrections
                            {--tenant= : Restrict to one tenant (default: every tenant with a stuck candidate)}
                            {--older-than= : Override kb.review.stuck_applying_minutes for this run}';

    protected $description = 'Reconcile correction candidates stuck in "applying" after a crashed approval (ADR 0031 §7, H-B)';

    public function handle(KbReviewService $service, TenantContext $tenants): int
    {
        $olderThan = $this->option('older-than');
        $olderThanMinutes = $olderThan !== null ? max(1, (int) $olderThan) : null;

        $tenantIds = $this->resolveTenantIds();
        if ($tenantIds === []) {
            $this->info('No stuck correction candidates found. Nothing to reconcile.');

            return self::SUCCESS;
        }

        $previousTenant = $tenants->current();
        $totalFinalized = 0;
        $totalReverted = 0;

        try {
            foreach ($tenantIds as $tenantId) {
                $tenants->set($tenantId);
                $result = $service->reconcileStuckCorrections($tenantId, $olderThanMinutes);
                $totalFinalized += $result['finalized'];
                $totalReverted += $result['reverted'];

                if ($result['finalized'] > 0 || $result['reverted'] > 0 || $result['skipped'] > 0) {
                    $this->info(sprintf(
                        '[%s] finalized=%d reverted=%d skipped=%d',
                        $tenantId,
                        $result['finalized'],
                        $result['reverted'],
                        $result['skipped'],
                    ));
                }
            }
        } finally {
            $tenants->set($previousTenant);
        }

        $this->info("Reconciliation complete: {$totalFinalized} finalized, {$totalReverted} reverted.");

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function resolveTenantIds(): array
    {
        $explicit = trim((string) ($this->option('tenant') ?? ''));
        if ($explicit !== '') {
            return [$explicit];
        }

        return KbTextCorrectionCandidate::query()
            ->where('status', KbTextCorrectionCandidate::STATUS_APPLYING)
            // R30: intentionally unscoped — this bootstrap query only
            // discovers the TENANT SET of candidates currently stuck, by
            // reading the tenant_id column. The actual reconciliation work
            // in reconcileStuckCorrections() is forTenant()-scoped per
            // tenant in the loop above.
            ->distinct()
            ->pluck('tenant_id')
            ->filter(static fn ($v): bool => is_string($v) && $v !== '')
            ->values()
            ->all();
    }
}
