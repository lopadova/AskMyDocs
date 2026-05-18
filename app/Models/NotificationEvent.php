<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v8.0/W1.1 — Notification event row (ADR 0012).
 *
 * Two contracts coexist on this table (schema reflects both):
 *
 *  - **Per-user fan-out** (`user_id != null`): the dispatcher emits ONE
 *    row per recipient so the in-app bell renders per-user state
 *    (`read_at` / `dismissed_at`) without join gymnastics. This is the
 *    main path for `kb_doc_created` / `kb_doc_modified` /
 *    `kb_canonical_promoted` / `collection_new_member` /
 *    `kb_decision_debt_threshold`.
 *  - **System-level / tenant-wide** (`user_id == null`): used for
 *    operator-facing events whose read state is not bound to a single
 *    user (e.g. a one-shot system advisory the tenant admin
 *    acknowledges once on behalf of the org). The aggregated weekly
 *    digest is NOT stored here — it lives in `notification_digests`.
 *
 * `payload` carries event-specific data; `channel_dispatch_log`
 * records per-channel delivery outcomes for forensics.
 *
 * The `BelongsToTenant` trait auto-fills `tenant_id` on creating
 * from the active `TenantContext` (R31).
 *
 * The `EVENT_*` constants below are the source of truth for the
 * event-type vocabulary in v8.0/W1. The W2 dispatcher will read a
 * default-policy map from `config/askmydocs.php` once that config
 * is introduced; until then the constants are authoritative.
 */
class NotificationEvent extends Model
{
    use BelongsToTenant;

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
