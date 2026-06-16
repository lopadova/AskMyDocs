<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * v8.15/W3 — one generated rich digest, browsable in the SPA feed.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $frequency
 * @property \Illuminate\Support\Carbon $period_start
 * @property \Illuminate\Support\Carbon $period_end
 * @property array $payload
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class EngagementDigestFeedEntry extends Model
{
    use BelongsToTenant;

    protected $table = 'engagement_digest_feed';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'frequency',
        'period_start',
        'period_end',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function scopeLatestEntry(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id')->limit(1);
    }
}
