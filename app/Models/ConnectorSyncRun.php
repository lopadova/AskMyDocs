<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * v8.21 (Ciclo 2) — one observability row per `ConnectorSyncJob` execution.
 *
 * Recorded host-side by {@see \App\Connectors\ConnectorSyncRunRecorder} off the
 * Laravel queue lifecycle (the package job emits no events). Powers the admin
 * "Ingestion & Sync" per-account history.
 *
 * R30/R31: tenant-scoped via `BelongsToTenant`; every read query must call
 * `->forTenant($ctx->current())` or include an explicit `tenant_id` predicate.
 */
class ConnectorSyncRun extends Model
{
    use BelongsToTenant;

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';

    protected $table = 'connector_sync_runs';

    protected $fillable = [
        'tenant_id',
        'connector_installation_id',
        'connector_name',
        'label',
        'queue',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'items_discovered',
        'items_failed',
        'error_json',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'items_discovered' => 'integer',
        'items_failed' => 'integer',
        'error_json' => 'array',
    ];
}
