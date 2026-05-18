<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v8.0/W1.1 — Per-user-per-event-per-channel preference row (ADR 0012).
 *
 * The dispatcher consults this table before delivering. Rows are
 * upserted on the composite unique `(tenant_id, user_id,
 * event_type, channel)` so toggles are idempotent.
 *
 * W1 channels: `in_app`, `email`.
 * W2 channels (extension): `discord`, `slack`, `teams`, `webhook`.
 */
class NotificationPreference extends Model
{
    use BelongsToTenant;

    public const CHANNEL_IN_APP = 'in_app';
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_DISCORD = 'discord';
    public const CHANNEL_SLACK = 'slack';
    public const CHANNEL_TEAMS = 'teams';
    public const CHANNEL_WEBHOOK = 'webhook';

    protected $table = 'notification_preferences';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'event_type',
        'channel',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'bool',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
