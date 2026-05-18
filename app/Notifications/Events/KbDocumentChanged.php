<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Models\NotificationEvent;

/**
 * v8.0/W1.2 — fired when a KB document is created or modified.
 *
 * Publisher: the `KnowledgeDocument::created` Eloquent hook registered
 * in `NotificationServiceProvider::wireDomainPublishers()`. The hook
 * fires for EVERY code path that ends up inserting a row (legacy
 * `ingestMarkdown()` facade, polymorphic `ingest()`,
 * `persistDrafts()` Flow step, future connectors) — no per-job /
 * per-controller wiring to forget.
 * `NotificationPublisher::publishKbDocumentChanged()` resolves the
 * recipient set (filtered by project membership + per-document ACL)
 * and constructs this event.
 *
 * Payload contract (downstream channel adapters can rely on these
 * keys being present):
 *   - `doc_id` (int) — `knowledge_documents.id`
 *   - `project_key` (string)
 *   - `source_path` (string)
 *   - `title` (string|null) — the persisted `title` column
 *   - `change` (`'created'`|`'modified'`) — `'modified'` when a prior
 *     row exists for `(tenant_id, project_key, source_path)`,
 *     otherwise `'created'`
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
