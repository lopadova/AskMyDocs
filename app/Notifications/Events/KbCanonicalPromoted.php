<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Models\NotificationEvent;

/**
 * v8.0/W1.2 — fired when a doc is promoted to canonical.
 *
 * Publisher: `CanonicalWriter::write()` on successful promote +
 * `KbPromotionController` on `POST /promotion/promote`. Payload
 * carries `doc_id`, `slug`, `canonical_type`, `promoted_by` (user
 * id of the editor / operator who triggered the promotion).
 */
final class KbCanonicalPromoted extends BaseNotificationEvent
{
    public function eventType(): string
    {
        return NotificationEvent::EVENT_KB_CANONICAL_PROMOTED;
    }
}
