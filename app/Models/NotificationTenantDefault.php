<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * v8.0/W2.3 — Per-tenant baseline preferences for new users (ADR 0012).
 *
 * Super-admins edit this via `/api/admin/notifications/defaults`; the
 * `NotificationPreferencesSeeder` reads it when creating a new user
 * so the dispatcher has rows to consult on the user's very first
 * event. When a tenant never edits its defaults the seeder falls back
 * to `config('askmydocs.notifications.default_channel_preferences')`
 * — so the platform default still applies cleanly.
 *
 * Composite unique `(tenant_id, event_type, channel)` keeps upserts
 * idempotent. Scoped per-tenant; never cross-tenant.
 */
class NotificationTenantDefault extends Model
{
    use BelongsToTenant;

    protected $table = 'notification_tenant_defaults';

    protected $fillable = [
        'tenant_id',
        'event_type',
        'channel',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'bool',
    ];
}
