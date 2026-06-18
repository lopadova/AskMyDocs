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
 * Campaign — the issuing-policy envelope a code is minted under
 * (docs/04-data-model.md). Tenant-aware (R30/R31): `key` is unique per tenant.
 */
class InviteCampaign extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const TYPE_SINGLE_USE = 'single_use';
    public const TYPE_MULTI_USE = 'multi_use';
    public const TYPE_CAPACITY = 'capacity';
    public const TYPE_REFERRAL = 'referral';
    public const TYPE_WAITLIST_SKIP = 'waitlist_skip';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ENDED = 'ended';

    public const TYPES = [
        self::TYPE_SINGLE_USE,
        self::TYPE_MULTI_USE,
        self::TYPE_CAPACITY,
        self::TYPE_REFERRAL,
        self::TYPE_WAITLIST_SKIP,
    ];

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_ENDED,
    ];

    protected $table = 'invite_campaigns';

    protected $fillable = [
        'tenant_id',
        'key',
        'name',
        'description',
        'type',
        'status',
        'max_redemptions_total',
        'per_user_limit',
        'starts_at',
        'ends_at',
        'reward_policy',
        'grant',
        'created_by',
    ];

    protected $casts = [
        'max_redemptions_total' => 'integer',
        'per_user_limit' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'reward_policy' => 'array',
        'grant' => 'array',
    ];

    public function codes(): HasMany
    {
        return $this->hasMany(InviteCode::class, 'campaign_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Whether the campaign is currently issuing/redeemable: status active and
     * `now` inside the [starts_at, ends_at] window when those bounds are set.
     */
    public function isOpen(?\DateTimeInterface $now = null): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $now ??= now();

        if ($this->starts_at !== null && $now < $this->starts_at) {
            return false;
        }

        if ($this->ends_at !== null && $now > $this->ends_at) {
            return false;
        }

        return true;
    }
}
