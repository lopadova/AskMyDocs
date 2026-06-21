import { useMemo, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    adminProjectsApi,
    type AdminProject,
    type CreateProjectPayload,
    type UpdateProjectPayload,
} from './admin-projects.api';
import { ProjectFormDialog } from './ProjectFormDialog';
import { toAdminError } from '../shared/errors';

/**
 * v8.9 — Admin Projects list view.
 *
 * The first-class registry of `project_key` within the active team:
 * create / rename / describe / delete projects, with live document +
 * membership counts per row. Scoped to the active team server-side
 * (X-Tenant-Id from the topbar switcher) — switching team remounts this
 * view and refetches.
 *
 * Delete is a two-step inline confirm; the BE blocks deletion (422)
 * while documents or memberships reference the key, and that message
 * surfaces in the row's error slot.
 *
 * R11: every interactive surface has `data-testid`. R15: dialog carries
 * `role="dialog"` + `aria-modal`, inputs are labelled.
 *
 * AdminShell is wrapped at the ROUTE level (AdminProjectsRoute), not
 * here, so this component renders without a Router context — Vitest can
 * mount it bare (mirrors SynonymsList / KbInsightsView).
 */
export function ProjectsList(): ReactNode {
    const qc = useQueryClient();
    const [filter, setFilter] = useState('');
    const [editing, setEditing] = useState<AdminProject | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [confirmingDelete, setConfirmingDelete] = useState<Record<number, boolean>>({});
    const [rowError, setRowError] = useState<Record<number, string>>({});

    const projectsQuery = useQuery({
        queryKey: ['admin-projects'],
        queryFn: () => adminProjectsApi.list(),
        staleTime: 30_000,
    });

    const createMutation = useMutation({
        mutationFn: (payload: CreateProjectPayload) => adminProjectsApi.create(payload),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['admin-projects'] });
            // Project keys also feed the picker endpoints — refresh them.
            qc.invalidateQueries({ queryKey: ['admin', 'kb', 'projects'] });
            setCreateOpen(false);
            setSubmitError(null);
        },
        onError: (err: unknown) => setSubmitError(toAdminError(err).message),
    });

    const updateMutation = useMutation({
        mutationFn: ({ id, payload }: { id: number; payload: UpdateProjectPayload }) =>
            adminProjectsApi.update(id, payload),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['admin-projects'] });
            setEditing(null);
            setSubmitError(null);
        },
        onError: (err: unknown) => setSubmitError(toAdminError(err).message),
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => adminProjectsApi.delete(id),
        onSuccess: (_data, id) => {
            qc.invalidateQueries({ queryKey: ['admin-projects'] });
            qc.invalidateQueries({ queryKey: ['admin', 'kb', 'projects'] });
            setRowError((prev) => {
                const next = { ...prev };
                delete next[id];
                return next;
            });
        },
        onError: (err: unknown, id) =>
            setRowError((prev) => ({ ...prev, [id]: toAdminError(err).message })),
    });

    const projects = projectsQuery.data ?? [];
    const filtered = useMemo(() => {
        const needle = filter.trim().toLowerCase();
        if (needle === '') return projects;
        return projects.filter(
            (p) =>
                p.project_key.toLowerCase().includes(needle) ||
                p.name.toLowerCase().includes(needle) ||
                (p.description ?? '').toLowerCase().includes(needle),
        );
    }, [projects, filter]);

    return (
        <div data-testid="admin-projects-view" style={{ padding: 24 }}>
                <header style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16 }}>
                    <h1 style={{ margin: 0, fontSize: 18, color: 'var(--fg-0)' }}>Projects</h1>
                    <span data-testid="admin-projects-count" style={{ color: 'var(--fg-3)', fontSize: 12 }}>
                        {projects.length} total
                    </span>
                    <span style={{ flex: 1 }} />
                    <input
                        data-testid="admin-projects-filter"
                        type="text"
                        value={filter}
                        onChange={(e) => setFilter(e.target.value)}
                        placeholder="Filter by name / key / description"
                        aria-label="Filter projects"
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
                        data-testid="admin-projects-create"
                        onClick={() => {
                            setSubmitError(null);
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
                        + New project
                    </button>
                </header>

                {projectsQuery.isLoading && (
                    <p data-testid="admin-projects-loading" data-state="loading" style={{ color: 'var(--fg-3)' }}>
                        Loading…
                    </p>
                )}
                {projectsQuery.isError && (
                    <p data-testid="admin-projects-error" data-state="error" role="alert" style={{ color: 'var(--err)' }}>
                        {toAdminError(projectsQuery.error).message}
                    </p>
                )}
                {!projectsQuery.isLoading && !projectsQuery.isError && projects.length === 0 && (
                    <p
                        data-testid="admin-projects-empty"
                        data-state="empty"
                        style={{
                            color: 'var(--fg-3)',
                            padding: 24,
                            textAlign: 'center',
                            border: '1px dashed var(--panel-border)',
                            borderRadius: 8,
                        }}
                    >
                        No projects yet. Click <code>+ New project</code> to create one.
                    </p>
                )}
                {!projectsQuery.isLoading && projects.length > 0 && filtered.length === 0 && (
                    <p data-testid="admin-projects-no-match" style={{ color: 'var(--fg-3)' }}>
                        No projects match the filter.
                    </p>
                )}
                {filtered.length > 0 && (
                    <table
                        data-testid="admin-projects-table"
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
                                <th style={cellStyle}>Key</th>
                                <th style={cellStyle}>Docs</th>
                                <th style={cellStyle}>Members</th>
                                <th style={cellStyle}>Description</th>
                                <th style={{ ...cellStyle, textAlign: 'right' }}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {filtered.map((project) => {
                                const isConfirming = confirmingDelete[project.id] === true;
                                return (
                                    <tr
                                        key={project.id}
                                        data-testid={`admin-project-row-${project.id}`}
                                        data-project-key={project.project_key}
                                        style={{ borderTop: '1px solid var(--panel-border, rgba(255,255,255,.06))' }}
                                    >
                                        <td style={{ ...cellStyle, color: 'var(--fg-0)' }}>{project.name}</td>
                                        <td
                                            style={{
                                                ...cellStyle,
                                                fontFamily: 'var(--font-mono, monospace)',
                                                color: 'var(--fg-1)',
                                            }}
                                        >
                                            {project.project_key}
                                        </td>
                                        <td
                                            data-testid={`admin-project-row-${project.id}-docs`}
                                            style={{ ...cellStyle, color: 'var(--fg-2)' }}
                                        >
                                            {project.document_count}
                                        </td>
                                        <td
                                            data-testid={`admin-project-row-${project.id}-members`}
                                            style={{ ...cellStyle, color: 'var(--fg-2)' }}
                                        >
                                            {project.member_count}
                                        </td>
                                        <td style={{ ...cellStyle, color: 'var(--fg-3)', maxWidth: 280 }}>
                                            {project.description ?? '—'}
                                        </td>
                                        <td style={{ ...cellStyle, textAlign: 'right', whiteSpace: 'nowrap' }}>
                                            {!isConfirming && (
                                                <>
                                                    <button
                                                        type="button"
                                                        data-testid={`admin-project-row-${project.id}-edit`}
                                                        aria-label={`Edit project ${project.name}`}
                                                        onClick={() => {
                                                            setSubmitError(null);
                                                            setEditing(project);
                                                        }}
                                                        style={iconButtonStyle()}
                                                    >
                                                        Edit
                                                    </button>
                                                    <button
                                                        type="button"
                                                        data-testid={`admin-project-row-${project.id}-delete`}
                                                        aria-label={`Delete project ${project.name}`}
                                                        onClick={() =>
                                                            setConfirmingDelete((prev) => ({ ...prev, [project.id]: true }))
                                                        }
                                                        style={iconButtonStyle()}
                                                    >
                                                        Delete
                                                    </button>
                                                </>
                                            )}
                                            {isConfirming && (
                                                <>
                                                    <button
                                                        type="button"
                                                        data-testid={`admin-project-row-${project.id}-delete-confirm`}
                                                        onClick={() => {
                                                            deleteMutation.mutate(project.id);
                                                            setConfirmingDelete((prev) => ({ ...prev, [project.id]: false }));
                                                        }}
                                                        style={confirmButtonStyle('danger')}
                                                    >
                                                        Confirm
                                                    </button>
                                                    <button
                                                        type="button"
                                                        data-testid={`admin-project-row-${project.id}-delete-cancel`}
                                                        onClick={() =>
                                                            setConfirmingDelete((prev) => ({ ...prev, [project.id]: false }))
                                                        }
                                                        style={confirmButtonStyle('neutral')}
                                                    >
                                                        Cancel
                                                    </button>
                                                </>
                                            )}
                                            {rowError[project.id] && (
                                                <p
                                                    data-testid={`admin-project-row-${project.id}-error`}
                                                    role="alert"
                                                    style={{ margin: '4px 0 0', fontSize: 11, color: 'var(--err)', textAlign: 'right' }}
                                                >
                                                    {rowError[project.id]}
                                                </p>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}

                {createOpen && (
                    <ProjectFormDialog
                        project={null}
                        onClose={() => setCreateOpen(false)}
                        onSubmit={(payload) => createMutation.mutate(payload as CreateProjectPayload)}
                        submitError={submitError}
                        isSubmitting={createMutation.isPending}
                    />
                )}
                {editing !== null && (
                    <ProjectFormDialog
                        project={editing}
                        onClose={() => setEditing(null)}
                        onSubmit={(payload) =>
                            updateMutation.mutate({ id: editing.id, payload: payload as UpdateProjectPayload })
                        }
                        submitError={submitError}
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

function confirmButtonStyle(variant: 'danger' | 'neutral'): React.CSSProperties {
    const isDanger = variant === 'danger';
    return {
        marginLeft: 6,
        padding: '4px 10px',
        borderRadius: 6,
        border: '1px solid ' + (isDanger ? 'var(--err, #c4391d)' : 'var(--panel-border, rgba(255,255,255,.15))'),
        background: isDanger ? 'var(--err, #c4391d)' : 'transparent',
        color: isDanger ? 'white' : 'var(--fg-2)',
        fontSize: 11.5,
        cursor: 'pointer',
    };
}
