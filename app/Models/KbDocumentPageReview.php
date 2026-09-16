<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v8.37 / ADR 0031 §2 — per-page review progress on a converted document.
 * One row per (tenant, document, page); `markPageReviewed()` upserts on the
 * unique key rather than accumulating rows.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $knowledge_document_id
 * @property int $page_number
 * @property string $status
 * @property int|null $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 */
final class KbDocumentPageReview extends Model
{
    use BelongsToTenant;

    public const STATUS_UNREVIEWED = 'unreviewed';

    public const STATUS_REVIEWED = 'reviewed';

    protected $table = 'kb_document_page_reviews';

    protected $fillable = [
        'tenant_id',
        'knowledge_document_id',
        'page_number',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'page_number' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeReviewed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REVIEWED);
    }

    public function scopeUnreviewed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_UNREVIEWED);
    }
}
