<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * v4.5/W1 — Connector installation row.
 *
 * Represents one (tenant_id, connector_name) tuple — e.g. "tenant 'acme'
 * has installed the google-drive connector". The companion
 * {@see ConnectorCredential} carries the encrypted OAuth tokens; this
 * model carries the operational state (status, last_sync_at, error_json,
 * per-connector config).
 *
 * R31 — `tenant_id` auto-fills from {@see \App\Support\TenantContext} on
 *       creating via the {@see BelongsToTenant} trait.
 * R30 — every read query MUST scope by tenant via `forTenant($id)` (the
 *       trait scope) or an explicit `where('tenant_id', ...)`.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $connector_name
 * @property array<string,mixed>|null $config_json
 * @property string $status            One of pending|active|disabled|errored
 * @property \Illuminate\Support\Carbon|null $last_sync_at
 * @property array<string,mixed>|null $error_json
 * @property int $created_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ConnectorInstallation extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_ERRORED = 'errored';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_DISABLED,
        self::STATUS_ERRORED,
    ];

    protected $table = 'connector_installations';

    protected $fillable = [
        'tenant_id',
        'connector_name',
        'config_json',
        'status',
        'last_sync_at',
        'error_json',
        'created_by',
    ];

    protected $casts = [
        'config_json' => 'array',
        'error_json' => 'array',
        'last_sync_at' => 'datetime',
    ];

    public function credential(): HasOne
    {
        return $this->hasOne(ConnectorCredential::class, 'connector_installation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
