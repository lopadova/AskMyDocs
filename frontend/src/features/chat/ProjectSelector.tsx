import type { ReactNode } from 'react';

/**
 * Project scope selector for the chat header.
 *
 * WHY this exists: the BE binds a conversation to ONE `project_key` at
 * creation time (`conversations.project_key`) and the retrieval pipeline
 * scopes every turn of that conversation to it — a per-turn project filter
 * can only NARROW within the conversation's project, never switch to a
 * different one (the two `where project_key` clauses AND together in
 * `KbSearchService`). So choosing the project is a property of the
 * *conversation*, and switching project means starting a fresh chat.
 *
 * This control surfaces the projects the user can actually reach in the
 * ACTIVE TEAM (derived from `/api/auth/me` memberships — R18, never a
 * literal list). Before it existed the chat was hard-pinned to
 * `activeTeam.projects[0]` with no way to reach any other project, so
 * content ingested under a different project (e.g. a connector's
 * `connector-imap`) was unreachable from the chat UI even though it was
 * indexed correctly.
 *
 * Stateless / controlled (R29): the parent (ChatView) owns the selection
 * and decides what switching means (reset to a new conversation when the
 * chosen project differs from the current conversation's).
 *
 * Single-project deployments (≤ 1 reachable project) render a read-only
 * label so they look exactly like the pre-selector chat (v3 parity).
 *
 * R11: stable `data-testid`. R15: the `<select>` carries an accessible
 * name via `aria-label` (placeholder/visual text is NOT a label).
 */

export interface ProjectSelectorProps {
    /** Currently effective project_key, or null for a project-less scope. */
    value: string | null;
    /** Reachable project keys in the active team (the real domain — R18). */
    projects: string[];
    /** Fired with the newly chosen project_key. */
    onChange: (next: string) => void;
}

export function ProjectSelector({ value, projects, onChange }: ProjectSelectorProps): ReactNode {
    // Always represent the effective value, even if it is not in the
    // reachable list (e.g. a conversation bound to a project the user no
    // longer has membership in) — otherwise the native <select> would
    // render blank and silently mis-report the scope.
    const options =
        value !== null && !projects.includes(value) ? [value, ...projects] : projects;

    // Single (or zero) reachable option → nothing to choose. Keep the
    // read-only label so single-tenant deployments are unchanged.
    if (options.length <= 1) {
        return (
            <span data-testid="chat-project-label">{value ?? 'default'}</span>
        );
    }

    return (
        <select
            data-testid="chat-project-selector"
            aria-label="Project scope"
            value={value ?? ''}
            onChange={(e) => onChange(e.target.value)}
            style={{
                background: 'transparent',
                color: 'var(--fg-3)',
                border: '1px solid var(--panel-border, rgba(255,255,255,.18))',
                borderRadius: 6,
                fontFamily: 'var(--font-mono)',
                fontSize: 11,
                padding: '0 4px',
                cursor: 'pointer',
            }}
        >
            {/* When the effective project is null (no membership resolved
                yet) keep an explicit placeholder option so the control is
                never in an out-of-range state. */}
            {value === null && (
                <option value="" disabled>
                    default
                </option>
            )}
            {options.map((key) => (
                <option key={key} value={key}>
                    {key}
                </option>
            ))}
        </select>
    );
}
