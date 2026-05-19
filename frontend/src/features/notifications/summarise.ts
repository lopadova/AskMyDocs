import type { NotificationRow } from './notifications.api';

/**
 * v8.0/W1.4 — shared event-summary renderer used by both
 * NotificationBell (top-bar dropdown) and NotificationPanel (full
 * /app/admin/notifications page). Extracted in Copilot iter-8 #2
 * (round 8) — the two components previously kept slightly-divergent
 * copies, which would have drifted further every time a new
 * event_type shipped. The single helper is the only place where
 * event_type → human-readable string lives on the FE.
 */
export function summariseNotificationEvent(row: NotificationRow): string {
    const payload = row.payload ?? {};
    switch (row.event_type) {
        case 'kb_doc_created':
            return `New document: ${String(payload.title ?? payload.source_path ?? 'untitled')}`;
        case 'kb_doc_modified':
            return `Document updated: ${String(payload.title ?? payload.source_path ?? 'untitled')}`;
        case 'kb_canonical_promoted':
            return `Canonical promoted: ${String(payload.slug ?? '?')}`;
        case 'kb_decision_debt_threshold':
            return 'Decision debt threshold reached';
        case 'collection_new_member':
            return 'New member added to a collection';
        default:
            return String(row.event_type);
    }
}
