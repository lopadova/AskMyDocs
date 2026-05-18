<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Models\NotificationEvent;

/**
 * v8.0/W1.2 — fired when a doc is promoted to canonical.
 *
 * Publisher: the `KbCanonicalAudit::created` Eloquent hook (filtered
 * to `event_type='promoted'`) registered in
 * `NotificationServiceProvider::wireDomainPublishers()`. The audit
 * row is the canonical seam because `WriteCanonicalMarkdownStep`
 * writes it inside the saga transaction — synchronous and
 * flow-based promotions both end up there, so this event covers
 * every promotion path without per-controller wiring.
 * `NotificationPublisher::publishKbCanonicalPromoted()` resolves the
 * recipient set (filtered by project membership).
 *
 * Payload contract (downstream channel adapters can rely on these
 * keys being present):
 *   - `project_key` (string)
 *   - `doc_id` (string|null) — the canonical `doc_id` of the
 *     promoted document, copied off the audit row
 *   - `slug` (string|null)
 *   - `promoted_by` (string|null) — the `kb_canonical_audit.actor`
 *     identifier (`flow:kb.promote:write-markdown`, a user id,
 *     `system`, etc.)
 *
 * `canonical_type` is NOT carried in the payload — the audit row
 * does not store it, and the v8.0 baseline channel adapters do not
 * need it for the rendered notification body.
 */
final class KbCanonicalPromoted extends BaseNotificationEvent
{
    public function eventType(): string
    {
        return NotificationEvent::EVENT_KB_CANONICAL_PROMOTED;
    }
}
