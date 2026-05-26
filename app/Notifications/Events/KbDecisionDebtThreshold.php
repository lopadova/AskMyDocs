<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Models\NotificationEvent;

/**
 * v8.0 decision-debt heatmap event.
 *
 * Dual-mode: depending on tenant policy the dispatcher can either
 * (a) fan out one row per DPO / editor recipient, or
 * (b) insert a single tenant-wide system row (`user_id == null`).
 * See ADR 0012 + `NotificationEvent::EVENT_KB_DECISION_DEBT_THRESHOLD`
 * docblock for the dual-mode contract.
 *
 * Publisher is LIVE: `KbHealthRecomputeCommand` fires this event when a
 * canonical doc crosses the decision-debt threshold (the `kb:health-recompute`
 * cron, `--emit-events`). No longer a placeholder.
 */
final class KbDecisionDebtThreshold extends BaseNotificationEvent
{
    public function eventType(): string
    {
        return NotificationEvent::EVENT_KB_DECISION_DEBT_THRESHOLD;
    }
}
