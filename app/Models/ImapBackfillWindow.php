<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class ImapBackfillWindow extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id', 'imap_backfill_id', 'connector_installation_id', 'mailbox',
        'window_start', 'window_end', 'status', 'snapshot_uid_validity', 'snapshot_max_uid',
        'last_uid', 'expected_messages',
        'processed_messages', 'dispatched_documents', 'attempts', 'started_at',
        'finished_at', 'heartbeat_at', 'next_attempt_at', 'error_json',
    ];

    protected $casts = [
        'window_start' => 'date',
        'window_end' => 'date',
        'last_uid' => 'integer',
        'snapshot_uid_validity' => 'integer',
        'snapshot_max_uid' => 'integer',
        'expected_messages' => 'integer',
        'processed_messages' => 'integer',
        'dispatched_documents' => 'integer',
        'attempts' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'error_json' => 'array',
    ];
}
