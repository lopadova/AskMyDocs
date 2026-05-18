<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Models\NotificationEvent;

/**
 * v8.0/W1.2 — fired when a KB document is created or modified.
 *
 * Publisher: `IngestDocumentJob` on successful ingestion. The
 * payload carries `doc_id`, `title`, `project_key`, `change`
 * (`'created'`|`'modified'`), and `source_path` so downstream
 * channels can render a human-readable line without re-querying.
 *
 * `eventType()` maps the `change` field to one of two distinct
 * notification event types: `'modified'` →
 * `NotificationEvent::EVENT_KB_DOC_MODIFIED`, any other value (default
 * `'created'`) → `NotificationEvent::EVENT_KB_DOC_CREATED`. Subscribers
 * can therefore opt in to creates only, modifies only, or both via two
 * independent `notification_preferences` rows.
 */
final class KbDocumentChanged extends BaseNotificationEvent
{
    public function eventType(): string
    {
        $change = $this->payload['change'] ?? 'created';

        return $change === 'modified'
            ? NotificationEvent::EVENT_KB_DOC_MODIFIED
            : NotificationEvent::EVENT_KB_DOC_CREATED;
    }
}
