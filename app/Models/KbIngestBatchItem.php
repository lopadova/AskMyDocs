<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * KbIngestBatchItem — one file inside a {@see KbIngestBatch}.
 *
 * Status is driven through {@see \App\Services\Kb\Upload\KbUploadStagingService::transitionItem()}
 * (the single mutation point + realtime seam): staged → moving → queued →
 * processing → succeeded | failed. The first three transitions are owned by
 * the commit path; the last three by the queue-event listener
 * {@see \App\Listeners\KbUploadBatchItemProgress}.
 *
 * Tenant-aware (R30/R31). UUID primary key (HasUuids).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $batch_id
 * @property string $original_filename
 * @property string $staging_path
 * @property string $destination_path
 * @property string $mime_type
 * @property string $source_type
 * @property int $size_bytes
 * @property string $status
 * @property bool $is_canonical
 * @property string|null $canonical_warning
 * @property string|null $error
 * @property int|null $knowledge_document_id
 * @property string|null $flow_run_id
 */
class KbIngestBatchItem extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const STATUS_STAGED = 'staged';
    public const STATUS_MOVING = 'moving';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    /** Statuses past which an item is done and no longer polled. */
    public const TERMINAL = [self::STATUS_SUCCEEDED, self::STATUS_FAILED];

    protected $table = 'kb_ingest_batch_items';

    protected $fillable = [
        'tenant_id',
        'batch_id',
        'original_filename',
        'staging_path',
        'destination_path',
        'mime_type',
        'source_type',
        'size_bytes',
        'status',
        'is_canonical',
        'canonical_warning',
        'error',
        'knowledge_document_id',
        'flow_run_id',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'is_canonical' => 'boolean',
        'knowledge_document_id' => 'integer',
    ];

    /**
     * @return BelongsTo<KbIngestBatch, KbIngestBatchItem>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(KbIngestBatch::class, 'batch_id');
    }
}
