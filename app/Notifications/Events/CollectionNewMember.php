<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Models\NotificationEvent;

/**
 * v8.0 Living Collections membership event.
 *
 * Publisher: dispatched via `NotificationPublisher` after a
 * `kb_collection_members` upsert with `reason ∈ {static_match,
 * semantic_match}`. Payload carries `collection_id`, `collection_name`,
 * `doc_id`, `doc_title`, `score?` (when semantic_match). The publisher
 * wiring is LIVE (see NotificationPublisher) — this is no longer a
 * placeholder.
 */
final class CollectionNewMember extends BaseNotificationEvent
{
    public function eventType(): string
    {
        return NotificationEvent::EVENT_COLLECTION_NEW_MEMBER;
    }
}
