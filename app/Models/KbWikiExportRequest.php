<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * KbWikiExportRequest — one async export bundle (v8.38/W4b, ADR 0032 §5/§11/§12).
 *
 * Lifecycle: queued → processing → completed | failed (plus expired, set by
 * the retention sweep before the row is deleted). See the migration's
 * docblock for the idempotency-key and download-time re-authorization
 * contracts this row exists to support.
 *
 * Tenant-aware (R30/R31): BelongsToTenant auto-fills tenant_id on create.
 * UUID primary key (HasUuids) so the id is an opaque, non-enumerable token
 * in `/api/admin/kb/exports/{id}`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $project_key
 * @property int|null $requested_by
 * @property string $status
 * @property array<string, mixed> $options_json
 * @property string $idempotency_key
 * @property list<int>|null $document_ids_json
 * @property string|null $storage_disk
 * @property string|null $storage_path
 * @property int|null $document_count
 * @property bool $partial
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 */
class KbWikiExportRequest extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $table = 'kb_wiki_export_requests';

    protected $fillable = [
        'tenant_id',
        'project_key',
        'requested_by',
        'status',
        'options_json',
        'idempotency_key',
        'document_ids_json',
        'storage_disk',
        'storage_path',
        'document_count',
        'partial',
        'error_message',
        'expires_at',
        'completed_at',
    ];

    protected $casts = [
        'options_json' => 'array',
        'document_ids_json' => 'array',
        'document_count' => 'integer',
        'partial' => 'boolean',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && $this->storage_path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
