<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * v8.15/W1 — daily per-tenant engagement snapshot.
 *
 * Written by {@see \App\Console\Commands\EngagementComputeCommand} once per
 * calendar day; read by the admin/user dashboards and the digest. No heavy
 * aggregation in a request hot path.
 *
 * @property int $id
 * @property string $tenant_id
 * @property \Illuminate\Support\Carbon $snapshot_date
 * @property array|null $metrics
 * @property \Illuminate\Support\Carbon|null $computed_at
 * @property int|null $computed_duration_ms
 */
class KbEngagementSnapshot extends Model
{
    use BelongsToTenant;

    protected $table = 'kb_engagement_snapshots';

    /** `computed_at` is the write clock; no created_at/updated_at. */
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'snapshot_date' => 'date',
        'metrics' => 'array',
        'computed_at' => 'datetime',
        'computed_duration_ms' => 'integer',
    ];

    /** Most recent snapshot for the scoped tenant, or null if none computed. */
    public function scopeLatestSnapshot(Builder $query): Builder
    {
        return $query->orderByDesc('snapshot_date')->limit(1);
    }
}
