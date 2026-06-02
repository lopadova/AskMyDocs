<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\NotificationEvent;

/**
 * v8.0/W2.1 — Shared event-type → human-readable subject map.
 *
 * Used by every channel adapter that needs to render a one-line
 * subject for the event: EmailChannel (via NotificationMail),
 * DiscordChannel, SlackChannel, TeamsChannel, WebhookChannel. The
 * map MUST stay in lockstep with `NotificationEvent::EVENT_*`
 * constants — adding a new event type in W4/W6 requires extending
 * the `match` arm here, and the per-channel renderer picks up the
 * new subject automatically (R18 — derive from the shared canonical
 * map, never re-encode the literal subset per channel).
 *
 * The pre-W2.1 implementation lived inline in
 * `NotificationMail::renderSubject()`; W2.1 lifts it here and
 * `NotificationMail` now delegates so the email + the 4 external
 * channels can never drift apart on what the subject of a given
 * event is.
 */
final class NotificationSubjects
{
    public static function forEventType(string $eventType): string
    {
        return match ($eventType) {
            NotificationEvent::EVENT_KB_DOC_CREATED => 'New document published in your knowledge base',
            NotificationEvent::EVENT_KB_DOC_MODIFIED => 'A document you follow was updated',
            NotificationEvent::EVENT_KB_CANONICAL_PROMOTED => 'A decision was promoted to canonical',
            NotificationEvent::EVENT_KB_DECISION_DEBT_THRESHOLD => 'Decision debt threshold reached',
            NotificationEvent::EVENT_KB_DOC_STALE_REVIEW => 'A document may need review (untouched for a while)',
            NotificationEvent::EVENT_COLLECTION_NEW_MEMBER => 'A new document joined a collection you follow',
            default => 'AskMyDocs notification',
        };
    }
}
