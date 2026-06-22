<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WaitlistEntry — a queued signup (docs/04-data-model.md). GREENFIELD.
 * `email` is direct PII, normalized and unique per tenant.
 */
class WaitlistEntry extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const STATUS_WAITING = 'waiting';
    public const STATUS_INVITED = 'invited';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_REMOVED = 'removed';

    public const STATUSES = [
        self::STATUS_WAITING,
        self::STATUS_INVITED,
        self::STATUS_CONVERTED,
        self::STATUS_REMOVED,
    ];

    protected $table = 'invite_waitlist';

    protected $fillable = [
        'tenant_id',
        'email',
        'position',
        'priority',
        'referral_count',
        'granted_code_id',
        'status',
        'invited_at',
        'converted_at',
        'metadata',
    ];

    protected $casts = [
        'position' => 'integer',
        'priority' => 'integer',
        'referral_count' => 'integer',
        'invited_at' => 'datetime',
        'converted_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function grantedCode(): BelongsTo
    {
        return $this->belongsTo(InviteCode::class, 'granted_code_id');
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_WAITING);
    }
}
