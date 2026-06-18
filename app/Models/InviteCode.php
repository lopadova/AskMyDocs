<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * InviteCode — the redeemable token (docs/04-data-model.md).
 *
 * `state`, `max_uses`, and `current_uses` drive the atomic redemption
 * contract (docs/07-redemption-flow.md). The model deliberately exposes NO
 * `recordUsage()`-style increment helper: the only sanctioned path to bump
 * `current_uses` is the atomic conditional UPDATE in
 * App\Services\Invite\RedemptionService.
 */
class InviteCode extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const KIND_RANDOM = 'random';
    public const KIND_VANITY = 'vanity';
    public const KIND_SIGNED = 'signed';

    public const STATE_ACTIVE = 'active';
    public const STATE_REDEEMED = 'redeemed';
    public const STATE_EXHAUSTED = 'exhausted';
    public const STATE_EXPIRED = 'expired';
    public const STATE_REVOKED = 'revoked';

    public const STATES = [
        self::STATE_ACTIVE,
        self::STATE_REDEEMED,
        self::STATE_EXHAUSTED,
        self::STATE_EXPIRED,
        self::STATE_REVOKED,
    ];

    protected $table = 'invite_codes';

    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'code',
        'code_kind',
        'state',
        'max_uses',
        'current_uses',
        'issuer_id',
        'expires_at',
        'payload',
        'signature',
        'metadata',
    ];

    protected $casts = [
        'max_uses' => 'integer',
        'current_uses' => 'integer',
        'expires_at' => 'datetime',
        'payload' => 'array',
        'metadata' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(InviteCampaign::class, 'campaign_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issuer_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class, 'code_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('state', self::STATE_ACTIVE);
    }

    public function isExpired(?\DateTimeInterface $now = null): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return ($now ?? now()) >= $this->expires_at;
    }

    public function hasFreeSeat(): bool
    {
        return $this->current_uses < $this->max_uses;
    }
}
