<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Events\KbCanonicalPromoted;
use App\Notifications\Events\KbDocumentChanged;
use Illuminate\Support\Facades\Event;

/**
 * v8.0/W1.2 — production-side publisher that converts domain mutations
 * (a freshly-created `KnowledgeDocument` row, a `kb_canonical_audit`
 * row with `event_type='promoted'`) into the matching
 * `BaseNotificationEvent` subclass and dispatches it.
 *
 * Wired from `NotificationServiceProvider::boot()` via Eloquent model
 * `created` hooks so EVERY ingestion / promotion path (HTTP, CLI,
 * Flow, future connectors) ends up firing the event without each
 * publisher having to remember the call.
 *
 * Recipient resolution is deliberately the same query both events
 * use: every `User` who has a `notification_preferences` row in the
 * event's tenant with `enabled=true` for the event_type, regardless
 * of which channel. The dispatcher then re-queries per recipient and
 * fans out only to the channels each user has actually enabled.
 */
final class NotificationPublisher
{
    /**
     * Fires `KbDocumentChanged` for a freshly-persisted
     * `KnowledgeDocument` row. `$change` is `'modified'` if any other
     * row exists in the same tenant + project + source_path (the prior
     * version was archived in the same transaction), else `'created'`.
     *
     * No-op when no subscribers exist for the resolved event_type —
     * avoids burning a dispatcher cycle that would short-circuit
     * inside `resolveEnabledChannels()` anyway.
     */
    public function publishKbDocumentChanged(
        string $tenantId,
        string $projectKey,
        int $documentId,
        string $sourcePath,
        ?string $title,
        bool $isModified,
    ): void {
        $eventType = $isModified
            ? NotificationEvent::EVENT_KB_DOC_MODIFIED
            : NotificationEvent::EVENT_KB_DOC_CREATED;

        $recipients = $this->resolveRecipients($tenantId, $eventType);
        if ($recipients === []) {
            return;
        }

        Event::dispatch(new KbDocumentChanged(
            recipients: $recipients,
            payload: [
                'doc_id' => $documentId,
                'project_key' => $projectKey,
                'source_path' => $sourcePath,
                'title' => $title,
                'change' => $isModified ? 'modified' : 'created',
            ],
            tenantId: $tenantId,
        ));
    }

    /**
     * Fires `KbCanonicalPromoted` for a `kb_canonical_audit` row with
     * `event_type='promoted'`. The audit row is the canonical seam —
     * `WriteCanonicalMarkdownStep` writes it inside the saga
     * transaction, so every promotion path (synchronous + flow-based)
     * triggers the event without per-controller wiring.
     */
    public function publishKbCanonicalPromoted(
        string $tenantId,
        string $projectKey,
        ?string $docId,
        ?string $slug,
        ?string $actor,
    ): void {
        $recipients = $this->resolveRecipients(
            $tenantId,
            NotificationEvent::EVENT_KB_CANONICAL_PROMOTED,
        );
        if ($recipients === []) {
            return;
        }

        Event::dispatch(new KbCanonicalPromoted(
            recipients: $recipients,
            payload: [
                'project_key' => $projectKey,
                'doc_id' => $docId,
                'slug' => $slug,
                'promoted_by' => $actor,
            ],
            tenantId: $tenantId,
        ));
    }

    /**
     * @return array<int, User>
     */
    private function resolveRecipients(string $tenantId, string $eventType): array
    {
        $userIds = NotificationPreference::query()
            ->where('tenant_id', $tenantId)
            ->where('event_type', $eventType)
            ->where('enabled', true)
            ->distinct()
            ->pluck('user_id')
            ->all();

        if ($userIds === []) {
            return [];
        }

        return User::query()
            ->withTrashed()
            ->whereIn('id', $userIds)
            ->get()
            ->all();
    }
}
