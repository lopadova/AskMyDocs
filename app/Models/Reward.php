<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reward — accrued incentive (docs/09-rewards-engine.md). GREENFIELD.
 *
 * The `idempotency_key` UNIQUE is the double-grant guard: the database, not
 * application bookkeeping, prevents a replayed grant from crediting twice.
 */
class Reward extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const PARTY_REFERRER = 'referrer';
    public const PARTY_REFEREE = 'referee';

    public const TYPE_CREDIT = 'credit';
    public const TYPE_PERK = 'perk';
    public const TYPE_TIER_UPGRADE = 'tier_upgrade';
    public const TYPE_DISCOUNT = 'discount';

    public const TRIGGER_ON_REDEMPTION = 'on_redemption';
    public const TRIGGER_ON_ACTIVATION = 'on_activation';
    public const TRIGGER_ON_MILESTONE = 'on_milestone';

    public const STATE_PENDING = 'pending';
    public const STATE_GRANTED = 'granted';
    public const STATE_REVERSED = 'reversed';
    public const STATE_EXPIRED = 'expired';

    protected $table = 'invite_rewards';

    protected $fillable = [
        'tenant_id',
        'referral_id',
        'redemption_id',
        'beneficiary_id',
        'party',
        'type',
        'amount',
        'unit',
        'trigger',
        'state',
        'idempotency_key',
        'granted_at',
        'reversed_at',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'integer',
        'granted_at' => 'datetime',
        'reversed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class, 'referral_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_id');
    }

    public function scopeGranted(Builder $query): Builder
    {
        return $query->where('state', self::STATE_GRANTED);
    }
}
