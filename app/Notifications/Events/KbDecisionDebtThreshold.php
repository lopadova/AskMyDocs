<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Models\NotificationEvent;

/**
 * v8.0/W1.2 — placeholder for v8.0/W4 decision-debt heatmap event.
 *
 * Dual-mode: depending on tenant policy the dispatcher can either
 * (a) fan out one row per DPO / editor recipient, or
 * (b) insert a single tenant-wide system row (`user_id == null`).
 * See ADR 0012 + `NotificationEvent::EVENT_KB_DECISION_DEBT_THRESHOLD`
 * docblock for the dual-mode contract.
 *
 * W1.2 only registers the event class so the dispatcher map is
 * complete; the publisher wiring lands in v8.0/W4 alongside the
 * `kb:health-recompute` cron.
 */
final class KbDecisionDebtThreshold extends BaseNotificationEvent
{
    public function eventType(): string
    {
        return NotificationEvent::EVENT_KB_DECISION_DEBT_THRESHOLD;
    }
}
