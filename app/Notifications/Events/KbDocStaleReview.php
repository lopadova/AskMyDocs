<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Models\NotificationEvent;

/**
 * v8.7/W2 — a document has gone untouched longer than the configured
 * staleness window and should be reviewed.
 *
 * Per-user fan-out: the `KbStaleReviewSweepCommand` cron resolves the
 * eligible project-member recipients (same ACL pipeline as
 * `KbDocumentChanged`) and constructs one event carrying them. The weekly
 * digest later rolls these up, so a user can keep the per-event channel
 * off and still see stale docs in the Monday digest.
 */
final class KbDocStaleReview extends BaseNotificationEvent
{
    public function eventType(): string
    {
        return NotificationEvent::EVENT_KB_DOC_STALE_REVIEW;
    }
}
