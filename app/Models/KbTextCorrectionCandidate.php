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
 * `pending -> applied | rejected` only under a `lockForUpdate()` transaction
 * (R21), and `idempotency_key` (a DB `UNIQUE`) is the replay/dedup mechanism
 * for `KbProposeTextCorrectionTool`.
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

    public static function idempotencyKeyFor(
        string $tenantId,
        string $userIdentity,
        int $documentId,
        string $versionHash,
        int $pageNumber,
        string $oldText,
        string $newText,
    ): string {
        return hash('sha256', implode('.', [
            $tenantId,
            $userIdentity,
            (string) $documentId,
            $versionHash,
            (string) $pageNumber,
            $oldText,
            $newText,
        ]));
    }
}
