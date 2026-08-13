<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ImapBackfill extends Model
{
    use BelongsToTenant;

    public const STATUS_DISCOVERING = 'discovering';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PAUSED = 'paused';

    public const ACTIVE_STATUSES = [self::STATUS_DISCOVERING, self::STATUS_RUNNING];

    protected $fillable = [
        'tenant_id', 'connector_installation_id', 'status', 'settings_json',
        'batch_size', 'total_messages', 'processed_messages', 'dispatched_documents',
        'total_windows', 'completed_windows', 'cutoff_at', 'started_at',
        'completed_at', 'heartbeat_at', 'error_json',
    ];

    protected $casts = [
        'settings_json' => 'array',
        'error_json' => 'array',
        'batch_size' => 'integer',
        'total_messages' => 'integer',
        'processed_messages' => 'integer',
        'dispatched_documents' => 'integer',
        'total_windows' => 'integer',
        'completed_windows' => 'integer',
        'cutoff_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'heartbeat_at' => 'datetime',
    ];

    public function windows(): HasMany
    {
        return $this->hasMany(ImapBackfillWindow::class, 'imap_backfill_id');
    }
}
