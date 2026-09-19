<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KbCanonicalAudit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v8.37/W3b round 7 (Copilot PR #496 must-fix, escalated from round 4's
 * MEDIUM to HIGH) — the durable fallback for
 * {@see \App\Services\Kb\Review\KbReviewService::approveCorrection()}'s
 * `kb_canonical_audit` write when the SYNCHRONOUS attempt in phase 3 fails.
 *
 * Round 4/5 accepted "log critical and move on" for that failure on the
 * reasoning that the correction had already genuinely applied (a new
 * document version is committed) and propagating the failure to the caller
 * would be a lie ("approval failed" when it didn't) with no way to safely
 * roll back a side effect that lives in an entirely separate transaction.
 * That reasoning about NOT failing the caller still holds — round 7 does
 * not revisit it. What round 7 correctly escalates is that a single
 * `Log::critical()` with no further attempt is not durable recovery for
 * `kb_canonical_audit`, which CLAUDE.md documents as the one IMMUTABLE
 * FORENSIC/COMPLIANCE trail in this app (survives hard deletes by design)
 * — unlike `chat_logs`, losing a row here is a genuine compliance gap, not
 * a merely-inconvenient one, and a log line only helps if a human later
 * greps for it.
 *
 * This job is the SAME kind of durability tool every other resilience-
 * sensitive write in this app already uses (`IngestDocumentJob`
 * $tries=3/backoff=[10,30,60], `SendExternalNotificationJob`
 * $tries=4/backoff=[5,30,120]): Laravel's queue retry machinery gives a
 * transient failure (a momentary DB blip, the ORIGINAL scenario the round
 * 4/5 comment named) real wall-clock time to recover, which an inline
 * retry inside the same HTTP/MCP request cannot — the request has already
 * returned by the time this runs. `approveCorrection()` still tries the
 * write SYNCHRONOUSLY first (the >99% common case: correct, immediately
 * consistent, zero added latency for the happy path); this job only ever
 * runs when that attempt has already thrown.
 *
 * `handle()` checks for an existing row by `metadata_json->candidate_id`
 * first (under a per-candidate lock, round 8 — see below), because the
 * sync attempt inside `approveCorrection()` can fail AFTER the INSERT
 * actually committed (e.g. the connection was cut acknowledging success),
 * and a retry-happy write here must not double the audit trail for the
 * same correction. A duplicate here would be a nuisance a human can
 * de-duplicate by candidate_id; a lost row cannot be recovered without
 * this exact log line — the asymmetry is why the check exists, not the
 * reverse.
 */
final class WriteKbTextCorrectionAuditJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * @param  array<string, mixed>  $beforeJson
     * @param  array<string, mixed>  $afterJson
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly int $candidateId,
        public readonly string $actor,
        public readonly ?string $projectKey,
        public readonly ?string $docId,
        public readonly ?string $slug,
        public readonly array $beforeJson,
        public readonly array $afterJson,
    ) {
    }

    public function handle(): void
    {
        // v8.37/W3b round 8 (Copilot PR #496 must-fix) — the exists-check
        // and the create() below are NOT atomic on their own: two
        // concurrent deliveries of this job for the SAME candidate (a
        // retried attempt racing a fresh one, or two queue workers both
        // picking up redelivered copies) could both observe "no row yet"
        // and both insert, duplicating the compliance trail — exactly the
        // failure mode this job exists to prevent. Serialize the
        // check-then-act with a per-tenant+candidate lock, the same
        // pattern `KbReviewService::proposeCorrection()` already uses for
        // its own check-then-act sequence (ADR 0031 §6).
        Cache::lock("kb-review-audit-retry:{$this->tenantId}:{$this->candidateId}", 30)->block(10, function (): void {
            $alreadyWritten = KbCanonicalAudit::query()
                ->forTenant($this->tenantId)
                ->where('event_type', 'updated')
                ->where('metadata_json->candidate_id', $this->candidateId)
                ->exists();
            if ($alreadyWritten) {
                return;
            }

            KbCanonicalAudit::query()->create([
                'tenant_id' => $this->tenantId,
                'project_key' => $this->projectKey,
                'doc_id' => $this->docId,
                'slug' => $this->slug,
                'event_type' => 'updated',
                'actor' => $this->actor,
                'before_json' => $this->beforeJson,
                'after_json' => $this->afterJson,
                'metadata_json' => ['source' => 'kb_review_correction_candidate', 'candidate_id' => $this->candidateId, 'via' => 'audit_retry_job'],
            ]);
        });
    }

    /**
     * Laravel calls this once `$tries` is exhausted — the true last resort,
     * after the sync attempt AND 3 queued retries spanning up to 100s of
     * real wall-clock time have all failed. Everything needed to hand-
     * reconstruct the row by hand is in this one log line (mirrors the
     * synchronous fallback's own critical log in `approveCorrection()`).
     */
    public function failed(?Throwable $exception): void
    {
        Log::critical('WriteKbTextCorrectionAuditJob — exhausted retries; the correction applied successfully but its audit row was never written, reconstruct manually from these fields', [
            'candidate_id' => $this->candidateId,
            'tenant_id' => $this->tenantId,
            'actor' => $this->actor,
            'before_json' => $this->beforeJson,
            'after_json' => $this->afterJson,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
