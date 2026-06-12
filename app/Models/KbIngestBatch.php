<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * KbIngestBatch — one admin UI drag-and-drop upload batch.
 *
 * Lifecycle: staged → committing → processing → completed |
 * completed_with_errors (plus cancelled / expired). `committed_at` is the
 * single-use commit gate (R21): set inside the commit lock so a re-commit
 * sees a non-null value and 409s.
 *
 * Tenant-aware (R30/R31): BelongsToTenant auto-fills tenant_id on create.
 * UUID primary key (HasUuids) so the id is an opaque, non-enumerable token
 * in the /api/admin/kb/uploads/{batch} routes.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $project_key
 * @property string|null $sub_path
 * @property string $status
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $committed_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 */
class KbIngestBatch extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const STATUS_STAGED = 'staged';
    public const STATUS_COMMITTING = 'committing';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    protected $table = 'kb_ingest_batches';

    protected $fillable = [
        'tenant_id',
        'project_key',
        'sub_path',
        'status',
        'created_by',
        'committed_at',
        'finished_at',
    ];

    protected $casts = [
        'committed_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * @return HasMany<KbIngestBatchItem>
     */
    public function items(): HasMany
    {
        return $this->hasMany(KbIngestBatchItem::class, 'batch_id');
    }
}
