<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invitation — a targeted single-recipient delivery of a code/token
 * (docs/04-data-model.md). `recipient` is direct PII governed by the
 * retention + erasure rules (docs/15-security-privacy.md).
 */
class Invitation extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_IN_APP = 'in_app';
    public const CHANNEL_LINK = 'link';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_BOUNCED = 'bounced';

    public const CHANNELS = [
        self::CHANNEL_EMAIL,
        self::CHANNEL_SMS,
        self::CHANNEL_IN_APP,
        self::CHANNEL_LINK,
    ];

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
        self::STATUS_BOUNCED,
    ];

    protected $table = 'invitations';

    protected $fillable = [
        'tenant_id',
        'code_id',
        'token',
        'channel',
        'recipient',
        'inviter_id',
        'context_ref',
        'role',
        'status',
        'expires_at',
        'sent_at',
        'accepted_at',
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'sent_at' => 'datetime',
        'accepted_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $hidden = [
        // Link token is a bearer secret; never serialize it to API responses.
        'token',
    ];

    public function code(): BelongsTo
    {
        return $this->belongsTo(InviteCode::class, 'code_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isExpired(?\DateTimeInterface $now = null): bool
    {
        return ($now ?? now()) >= $this->expires_at;
    }
}
