<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v8.0/W1.1 — Notification event row (ADR 0012).
 *
 * One row per (user, event) emission. `payload` carries
 * event-specific data; `channel_dispatch_log` records per-channel
 * delivery outcomes for forensics.
 *
 * The `BelongsToTenant` trait auto-fills `tenant_id` on creating
 * from the active `TenantContext` (R31).
 */
class NotificationEvent extends Model
{
    use BelongsToTenant;

    /** @see config/askmydocs.php notifications.event_types — extend in lockstep. */
    public const EVENT_KB_DOC_CREATED = 'kb_doc_created';
    public const EVENT_KB_DOC_MODIFIED = 'kb_doc_modified';
    public const EVENT_KB_CANONICAL_PROMOTED = 'kb_canonical_promoted';
    public const EVENT_KB_DECISION_DEBT_THRESHOLD = 'kb_decision_debt_threshold';
    public const EVENT_WEEKLY_DIGEST = 'weekly_digest';
    public const EVENT_COLLECTION_NEW_MEMBER = 'collection_new_member';

    protected $table = 'notification_events';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'event_type',
        'payload',
        'channel_dispatch_log',
        'read_at',
        'dismissed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'channel_dispatch_log' => 'array',
        'read_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
