<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Models\NotificationEvent;

/**
 * v8.0/W1.2 — placeholder for v8.0/W6 Living Collections event.
 *
 * Publisher (when W6 lands): `EvaluateCollectionsJob` after a
 * `kb_collection_members` upsert with `reason ∈ {static_match,
 * semantic_match}`. Payload carries `collection_id`, `collection_name`,
 * `doc_id`, `doc_title`, `score?` (when semantic_match).
 *
 * W1.2 only registers the event class so the dispatcher map is
 * complete; publisher wiring lands in v8.0/W6.
 */
final class CollectionNewMember extends BaseNotificationEvent
{
    public function eventType(): string
    {
        return NotificationEvent::EVENT_COLLECTION_NEW_MEMBER;
    }
}
