<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Referral — directed referrer→referee edge (docs/08-referral-graph.md).
 * GREENFIELD: extends beyond the WearFrame reference.
 *
 *   UNIQUE(tenant_id, referee_id)     — one referrer per referee (first-wins)
 *   CHECK(referrer_id <> referee_id)  — no self-referral
 */
class Referral extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_QUALIFIED = 'qualified';
    public const STATUS_REWARDED = 'rewarded';
    public const STATUS_REVERSED = 'reversed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_QUALIFIED,
        self::STATUS_REWARDED,
        self::STATUS_REVERSED,
    ];

    protected $table = 'invite_referrals';

    protected $fillable = [
        'tenant_id',
        'referrer_id',
        'referee_id',
        'code_id',
        'redemption_id',
        'campaign_id',
        'status',
        'depth',
        'attributed_at',
        'qualified_at',
    ];

    protected $casts = [
        'depth' => 'integer',
        'attributed_at' => 'datetime',
        'qualified_at' => 'datetime',
    ];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referee_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(InviteCampaign::class, 'campaign_id');
    }

    public function scopeQualified(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_QUALIFIED);
    }
}
