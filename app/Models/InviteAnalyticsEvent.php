<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only funnel event (docs/11-analytics.md). Insert-only; only
 * `occurred_at` is managed. UNIQUE(tenant_id, event_id) makes ingestion
 * idempotent.
 */
class InviteAnalyticsEvent extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const TYPE_INVITE_CREATED = 'invite_created';
    public const TYPE_INVITE_SENT = 'invite_sent';
    public const TYPE_INVITE_OPENED = 'invite_opened';
    public const TYPE_CODE_REDEEMED = 'code_redeemed';
    public const TYPE_ACCOUNT_ACTIVATED = 'account_activated';
    public const TYPE_REWARD_GRANTED = 'reward_granted';
    public const TYPE_REFERRAL_QUALIFIED = 'referral_qualified';

    public const UPDATED_AT = null;
    public const CREATED_AT = null;

    protected $table = 'invite_analytics_events';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'event_type',
        'actor_hash',
        'campaign_id',
        'code_id',
        'referral_id',
        'context',
        'occurred_at',
    ];

    protected $casts = [
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected $hidden = [
        'actor_hash',
    ];
}
