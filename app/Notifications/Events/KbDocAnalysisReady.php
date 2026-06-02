<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Models\NotificationEvent;

/**
 * v8.7/W3–W4 — the async AI deep-analysis for a document change is ready.
 *
 * Per-user fan-out: `AnalyzeDocumentChangeJob` resolves the eligible
 * project-member recipients (same ACL pipeline as `KbDocumentChanged`)
 * and constructs one event carrying them. The payload surfaces the
 * suggestion + impacted-doc counts so the bell summary is useful without
 * loading the full analysis.
 */
final class KbDocAnalysisReady extends BaseNotificationEvent
{
    public function eventType(): string
    {
        return NotificationEvent::EVENT_KB_DOC_ANALYSIS_READY;
    }
}
