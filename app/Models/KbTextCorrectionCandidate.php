<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v8.37 / ADR 0031 §6-7 — an agent-proposed text correction CANDIDATE for one
 * page of a converted document. Never applied content: `status` transitions
 * `pending -> applying -> applied | rejected` (or `pending -> rejected`
 * directly) only under a `lockForUpdate()` transaction (R21), and
 * `idempotency_key` (a DB `UNIQUE`) is the replay/dedup mechanism for
 * `KbProposeTextCorrectionTool`.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $knowledge_document_id
 * @property int $page_number
 * @property string $version_hash
 * @property string $old_text
 * @property string $new_text
 * @property string|null $rationale
 * @property string $idempotency_key
 * @property string $status
 * @property string $proposed_by
 * @property \Illuminate\Support\Carbon|null $consumed_at
 * @property int|null $consumed_by
 */
final class KbTextCorrectionCandidate extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    /**
     * v8.37/W3b round 5 (Copilot PR #496, H-B) — the phase-1 claim state:
     * the candidate is committed out of `pending` and its owning reviewer
     * recorded, but the actual document-mutating work
     * (`DocumentIngestor::reembedFromMarkdown()`, run OUTSIDE any ambient
     * transaction) has not yet been confirmed to have finished. A row
     * stuck here (the process that claimed it crashed before flipping it
     * to `applied`) is found and reconciled by
     * `kb:review-reconcile-stuck-corrections`, never silently resumed by
     * an ordinary {@see \App\Services\Kb\Review\KbReviewService::approveCorrection()}
     * call — which treats ANY non-`pending` status, including this one, as
     * `already_consumed`.
     */
    public const STATUS_APPLYING = 'applying';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_REJECTED = 'rejected';

    protected $table = 'kb_text_correction_candidates';

    protected $fillable = [
        'tenant_id',
        'knowledge_document_id',
        'page_number',
        'version_hash',
        'old_text',
        'new_text',
        'rationale',
        'idempotency_key',
        'status',
        'proposed_by',
        'consumed_at',
        'consumed_by',
    ];

    protected $casts = [
        'page_number' => 'integer',
        'consumed_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consumed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Each field is hashed to a FIXED-length (64 hex char) digest before
     * concatenation, so the final digest is injective over the 7-tuple: no
     * field boundary can shift between two DIFFERENT tuples and collide.
     * A plain delimiter-joined string is NOT injective when old_text/
     * new_text are arbitrary OCR text — moving a delimiter character from
     * the end of one field to the start of the next can reproduce the
     * same preimage for a legitimately different proposal, which would
     * make the idempotency_key UNIQUE constraint reject it as a spurious
     * replay (Copilot PR #494).
     */
    public static function idempotencyKeyFor(
        string $tenantId,
        string $userIdentity,
        int $documentId,
        string $versionHash,
        int $pageNumber,
        string $oldText,
        string $newText,
    ): string {
        $digests = array_map(
            static fn (string $part): string => hash('sha256', $part),
            [
                $tenantId,
                $userIdentity,
                (string) $documentId,
                $versionHash,
                (string) $pageNumber,
                $oldText,
                $newText,
            ],
        );

        return hash('sha256', implode('', $digests));
    }
}
