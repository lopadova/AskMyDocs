import { useMemo, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    adminTeamsApi,
    type AdminTeam,
    type CreateTeamPayload,
    type UpdateTeamPayload,
} from './admin-teams.api';
import { TeamFormDialog } from './TeamFormDialog';
import { toAdminError } from '../shared/errors';
import { me as fetchMe } from '../../auth/auth.api';
import { useAuthStore } from '../../../lib/auth-store';

/**
 * v8.28 — Admin Teams (= tenants) list view.
 *
 * Create a team and rename a team. A team's editable display name lives on
 * the vendor `tenants` row the topbar switcher reads, so after a create or
 * rename we refetch `/api/auth/me` and push it through `useAuthStore.setMe`
 * — the single sync point that also refreshes the team switcher (list +
 * label) via `useTeamStore.syncFromMe`.
 *
 * The list is what the current user may administer from real memberships.
 * The legacy `default` slug appears only with a membership and remains
 * read-only. Only rows with `can_manage` expose a Rename action.
 *
 * R11: every interactive surface has `data-testid`. R15: dialog carries
 * `role="dialog"` + `aria-modal`, inputs are labelled. AdminShell wraps at
 * the ROUTE level so this component stays Router-context-free for Vitest.
 */
export function TeamsList(): ReactNode {
    const qc = useQueryClient();
    const [filter, setFilter] = useState('');
    const [editing, setEditing] = useState<AdminTeam | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

    const teamsQuery = useQuery({
        queryKey: ['admin-teams'],
        queryFn: () => adminTeamsApi.list(),
        staleTime: 30_000,
    });

    // Best-effort refresh of the topbar switcher after a mutation. The
    // create/rename already succeeded and the list refetch reflects it; if
    // the me() re-sync fails the switcher simply updates on the next
    // bootstrap, so this must not surface as a mutation failure.
    const syncSwitcher = async () => {
        try {
            useAuthStore.getState().setMe(await fetchMe());
        } catch {
            /* non-critical — switcher re-syncs on next app bootstrap */
        }
    };

    const resetErrors = () => {
        setSubmitError(null);
        setFieldErrors({});
    };

    const onMutationError = (err: unknown) => {
        const e = toAdminError(err);
        setSubmitError(e.message);
        setFieldErrors(e.fieldErrors);
    };

    const createMutation = useMutation({
        mutationFn: (payload: CreateTeamPayload) => adminTeamsApi.create(payload),
        onSuccess: async () => {
            qc.invalidateQueries({ queryKey: ['admin-teams'] });
            await syncSwitcher();
            setCreateOpen(false);
            resetErrors();
        },
        onError: onMutationError,
    });

    const updateMutation = useMutation({
        mutationFn: ({ slug, payload }: { slug: string; payload: UpdateTeamPayload }) =>
            adminTeamsApi.update(slug, payload),
        onSuccess: async () => {
            qc.invalidateQueries({ queryKey: ['admin-teams'] });
            await syncSwitcher();
            setEditing(null);
            resetErrors();
        },
        onError: onMutationError,
    });

    const teams = teamsQuery.data ?? [];
    const filtered = useMemo(() => {
        const needle = filter.trim().toLowerCase();
        if (needle === '') return teams;
        return teams.filter(
            (t) => t.slug.toLowerCase().includes(needle) || t.name.toLowerCase().includes(needle),
        );
    }, [teams, filter]);

    return (
        <div data-testid="admin-teams-view" style={{ padding: 24 }}>
            <header style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16 }}>
                <h1 style={{ margin: 0, fontSize: 18, color: 'var(--fg-0)' }}>Teams</h1>
                <span data-testid="admin-teams-count" style={{ color: 'var(--fg-3)', fontSize: 12 }}>
                    {teams.length} total
                </span>
                <span style={{ flex: 1 }} />
                <input
                    data-testid="admin-teams-filter"
                    type="text"
                    value={filter}
                    onChange={(e) => setFilter(e.target.value)}
                    placeholder="Filter by name / slug"
                    aria-label="Filter teams"
                    style={{
                        padding: '5px 10px',
                        borderRadius: 6,
                        border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
                        background: 'var(--bg-3, rgba(255,255,255,.04))',
                        color: 'var(--fg-0)',
                        fontSize: 12,
                        minWidth: 240,
                    }}
                />
                <button
                    type="button"
                    data-testid="admin-teams-create"
                    onClick={() => {
                        resetErrors();
                        setCreateOpen(true);
                    }}
                    style={{
                        padding: '5px 12px',
                        borderRadius: 6,
                        border: '1px solid var(--accent, #6366f1)',
                        background: 'var(--accent, #6366f1)',
                        color: 'white',
                        fontSize: 12,
                        cursor: 'pointer',
                    }}
                >
                    + New team
                </button>
            </header>

            {teamsQuery.isLoading && (
                <p data-testid="admin-teams-loading" data-state="loading" style={{ color: 'var(--fg-3)' }}>
                    Loading…
                </p>
            )}
            {teamsQuery.isError && (
                <p data-testid="admin-teams-error" data-state="error" role="alert" style={{ color: 'var(--err)' }}>
                    {toAdminError(teamsQuery.error).message}
                </p>
            )}
            {!teamsQuery.isLoading && !teamsQuery.isError && teams.length === 0 && (
                <p
                    data-testid="admin-teams-empty"
                    data-state="empty"
                    style={{
                        color: 'var(--fg-3)',
                        padding: 24,
                        textAlign: 'center',
                        border: '1px dashed var(--panel-border)',
                        borderRadius: 8,
                    }}
                >
                    No teams yet. Click <code>+ New team</code> to create one.
                </p>
            )}
            {!teamsQuery.isLoading && teams.length > 0 && filtered.length === 0 && (
                <p data-testid="admin-teams-no-match" style={{ color: 'var(--fg-3)' }}>
                    No teams match the filter.
                </p>
            )}
            {filtered.length > 0 && (
                <table
                    data-testid="admin-teams-table"
                    data-state="ready"
                    style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}
                >
                    <thead>
                        <tr
                            style={{
                                textAlign: 'left',
                                color: 'var(--fg-2)',
                                fontSize: 11,
                                textTransform: 'uppercase',
                                letterSpacing: '.04em',
                            }}
                        >
                            <th style={cellStyle}>Name</th>
                            <th style={cellStyle}>Slug</th>
                            <th style={cellStyle}>Status</th>
                            <th style={cellStyle}>Projects</th>
                            <th style={cellStyle}>Members</th>
                            <th style={{ ...cellStyle, textAlign: 'right' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {filtered.map((team) => (
                            <tr
                                key={team.slug}
                                data-testid={`admin-team-row-${team.slug}`}
                                data-team-slug={team.slug}
                                style={{ borderTop: '1px solid var(--panel-border, rgba(255,255,255,.06))' }}
                            >
                                <td style={{ ...cellStyle, color: 'var(--fg-0)' }}>
                                    {team.name}
                                    {team.is_default && (
                                        <span style={{ marginLeft: 6, fontSize: 10, color: 'var(--fg-3)' }}>(bootstrap)</span>
                                    )}
                                </td>
                                <td
                                    style={{
                                        ...cellStyle,
                                        fontFamily: 'var(--font-mono, monospace)',
                                        color: 'var(--fg-1)',
                                    }}
                                >
                                    {team.slug}
                                </td>
                                <td style={{ ...cellStyle, color: 'var(--fg-2)' }}>{team.status}</td>
                                <td
                                    data-testid={`admin-team-row-${team.slug}-projects`}
                                    style={{ ...cellStyle, color: 'var(--fg-2)' }}
                                >
                                    {team.project_count}
                                </td>
                                <td
                                    data-testid={`admin-team-row-${team.slug}-members`}
                                    style={{ ...cellStyle, color: 'var(--fg-2)' }}
                                >
                                    {team.member_count}
                                </td>
                                <td style={{ ...cellStyle, textAlign: 'right', whiteSpace: 'nowrap' }}>
                                    {team.can_manage ? (
                                        <button
                                            type="button"
                                            data-testid={`admin-team-row-${team.slug}-edit`}
                                            aria-label={`Rename team ${team.name}`}
                                            onClick={() => {
                                                resetErrors();
                                                setEditing(team);
                                            }}
                                            style={iconButtonStyle()}
                                        >
                                            Rename
                                        </button>
                                    ) : (
                                        <span style={{ color: 'var(--fg-3)', fontSize: 11 }}>—</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            {createOpen && (
                <TeamFormDialog
                    team={null}
                    onClose={() => setCreateOpen(false)}
                    onSubmit={(payload) => createMutation.mutate(payload as CreateTeamPayload)}
                    submitError={submitError}
                    fieldErrors={fieldErrors}
                    isSubmitting={createMutation.isPending}
                />
            )}
            {editing !== null && (
                <TeamFormDialog
                    team={editing}
                    onClose={() => setEditing(null)}
                    onSubmit={(payload) =>
                        updateMutation.mutate({ slug: editing.slug, payload: payload as UpdateTeamPayload })
                    }
                    submitError={submitError}
                    fieldErrors={fieldErrors}
                    isSubmitting={updateMutation.isPending}
                />
            )}
        </div>
    );
}

const cellStyle: React.CSSProperties = { padding: '8px 10px', verticalAlign: 'middle' };

function iconButtonStyle(): React.CSSProperties {
    return {
        marginLeft: 6,
        padding: '4px 10px',
        border: '1px solid var(--panel-border, rgba(255,255,255,.12))',
        borderRadius: 6,
        background: 'transparent',
        color: 'var(--fg-1)',
        fontSize: 11.5,
        cursor: 'pointer',
    };
}
