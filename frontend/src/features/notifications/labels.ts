/**
 * v8.0/W2.3 — shared label maps for the notification preferences /
 * admin-defaults grids.
 *
 * Both `NotificationPreferencesGrid` (user-side) and
 * `AdminNotificationDefaultsGrid` (admin-side) render an event_type
 * × channel matrix with identical labels. Extracted here to avoid
 * the two-grids-drift hazard Copilot flagged (W2.3 iter-6 #L65).
 *
 * Source of truth for the keys themselves is the BE — these maps
 * just translate the kebab-case BE values into human-readable
 * strings the operator sees in the grid header / row label / aria-
 * label. An unknown key falls back to the raw value in the grid
 * (handled at the call site via `LABELS[k] ?? k`) so a new BE event
 * type ships safely with the original key visible until the FE
 * label is added.
 */
export const EVENT_TYPE_LABELS: Record<string, string> = {
    kb_doc_created: 'Doc created',
    kb_doc_modified: 'Doc modified',
    kb_canonical_promoted: 'Canonical promoted',
    kb_decision_debt_threshold: 'Decision debt threshold',
    collection_new_member: 'Collection new member',
};

export const CHANNEL_LABELS: Record<string, string> = {
    in_app: 'In-app',
    email: 'Email',
    discord: 'Discord',
    slack: 'Slack',
    teams: 'Teams',
    webhook: 'Webhook',
};
