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
 * literal list). It mirrors the admin Knowledge picker's presentation
 * (alphabetical order + an optional "All projects" entry) so the two
 * surfaces read consistently, while keeping the chat membership-scoped
 * (the admin picker is intentionally tenant-wide; the chat is RBAC-gated
 * to what the user can access).
 *
 * The "All projects" entry (value `''`) means "search across ALL of MY
 * reachable projects at once" — the parent turns it into a project-less
 * conversation whose turns carry `project_keys = <my projects>`, so it is
 * never a cross-tenant / cross-membership leak.
 *
 * Stateless / controlled (R29): the parent (ChatView) owns the selection
 * and decides what switching means (reset to a new conversation when the
 * chosen scope differs from the current conversation's).
 *
 * Single-project deployments (≤ 1 reachable project, no "All") render a
 * read-only label so they look exactly like the pre-selector chat.
 *
 * R11: stable `data-testid`. R15: the `<select>` carries an accessible
 * name via `aria-label` (placeholder/visual text is NOT a label).
 */

export interface ProjectSelectorProps {
    /**
     * Currently effective scope: a `project_key`, the empty string `''`
     * for "All projects", or `null` when no scope is resolved yet.
     */
    value: string | null;
    /** Reachable project keys in the active team (the real domain — R18). */
    projects: string[];
    /** When true, offer an "All projects" entry (value `''`). */
    allowAll?: boolean;
    /** Fired with the chosen value: a `project_key` or `''` for All. */
    onChange: (next: string) => void;
}

export function ProjectSelector({
    value,
    projects,
    allowAll = false,
    onChange,
}: ProjectSelectorProps): ReactNode {
    // Always represent a concrete effective value, even if it is not in the
    // reachable list (e.g. a conversation bound to a project the user no
    // longer has membership in) — otherwise the native <select> would
    // render blank and silently mis-report the scope. The empty string is
    // the "All projects" sentinel and is handled by `allowAll`, not here.
    const options =
        value !== null && value !== '' && !projects.includes(value)
            ? [value, ...projects]
            : projects;

    // Nothing to choose (single project, no "All") → read-only label,
    // preserving single-tenant parity.
    if (!allowAll && options.length <= 1) {
        return <span data-testid="chat-project-label">{value ?? 'default'}</span>;
    }

    return (
        <select
            data-testid="chat-project-selector"
            aria-label="Project scope"
            value={value ?? '__unset__'}
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
            {value === null && (
                <option value="__unset__" disabled>
                    default
                </option>
            )}
            {allowAll && <option value="">All projects</option>}
            {options.map((key) => (
                <option key={key} value={key}>
                    {key}
                </option>
            ))}
        </select>
    );
}
