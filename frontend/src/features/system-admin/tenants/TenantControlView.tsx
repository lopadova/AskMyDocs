import { useEffect, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient, type UseQueryResult } from '@tanstack/react-query';
import { toAdminError } from '../../admin/shared/errors';
import { TenantProvisionDialog } from './TenantProvisionDialog';
import {
    tenantControlApi,
    type PlatformTenant,
    type TenantDetail,
    type TenantStatus,
    type TenantUserAccess,
} from './tenant-control.api';

export function TenantControlView(): ReactNode {
    const queryClient = useQueryClient();
    const [searchDraft, setSearchDraft] = useState('');
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<TenantStatus | ''>('');
    const [page, setPage] = useState(1);
    const [selectedSlug, setSelectedSlug] = useState<string | null>(null);
    const [userPage, setUserPage] = useState(1);
    const [provisionOpen, setProvisionOpen] = useState(false);

    const tenantsQuery = useQuery({
        queryKey: ['tenant-control', 'list', search, status, page],
        queryFn: () => tenantControlApi.list({ search, status, page, per_page: 25 }),
        staleTime: 15_000,
    });

    useEffect(() => {
        const rows = tenantsQuery.data?.data ?? [];
        if (selectedSlug === null && rows.length > 0) setSelectedSlug(rows[0].slug);
    }, [tenantsQuery.data, selectedSlug]);

    const detailQuery = useQuery({
        queryKey: ['tenant-control', 'detail', selectedSlug, userPage],
        queryFn: () => tenantControlApi.detail(selectedSlug as string, userPage),
        enabled: selectedSlug !== null,
        staleTime: 10_000,
    });

    const selectTenant = (slug: string) => {
        setSelectedSlug(slug);
        setUserPage(1);
    };

    return (
        <div data-testid="tenant-control-view" style={{ padding: 24, width: '100%', boxSizing: 'border-box' }}>
            <header style={{ display: 'flex', alignItems: 'flex-start', gap: 12, marginBottom: 18 }}>
                <div>
                    <h1 style={{ margin: 0, fontSize: 19, color: 'var(--fg-0)' }}>Tenant control</h1>
                    <p style={{ margin: '5px 0 0', color: 'var(--fg-3)', fontSize: 11.5 }}>
                        System administration across every tenant. Platform permission required.
                    </p>
                </div>
                <span style={{ flex: 1 }} />
                <button
                    type="button"
                    data-testid="tenant-control-open-provision"
                    onClick={() => setProvisionOpen(true)}
                    style={primaryButton}
                >
                    + Provision tenant
                </button>
            </header>

            <form
                data-testid="tenant-control-filters"
                onSubmit={(event) => {
                    event.preventDefault();
                    setPage(1);
                    setSearch(searchDraft.trim());
                }}
                style={{ display: 'flex', gap: 8, marginBottom: 14 }}
            >
                <label htmlFor="tenant-control-search" style={visuallyLabelled}>
                    <span style={srOnly}>Search tenants</span>
                    <input
                        id="tenant-control-search"
                        data-testid="tenant-control-search"
                        value={searchDraft}
                        onChange={(event) => setSearchDraft(event.target.value)}
                        placeholder="Search name or slug"
                        style={{ ...inputStyle, minWidth: 250 }}
                    />
                </label>
                <label htmlFor="tenant-control-status" style={visuallyLabelled}>
                    <span style={srOnly}>Tenant status</span>
                    <select
                        id="tenant-control-status"
                        data-testid="tenant-control-status"
                        value={status}
                        onChange={(event) => {
                            setStatus(event.target.value as TenantStatus | '');
                            setPage(1);
                        }}
                        style={inputStyle}
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                        <option value="archived">Archived</option>
                    </select>
                </label>
                <button type="submit" data-testid="tenant-control-search-submit" style={secondaryButton}>Search</button>
                {tenantsQuery.data && (
                    <span data-testid="tenant-control-total" style={{ alignSelf: 'center', color: 'var(--fg-3)', fontSize: 11.5 }}>
                        {tenantsQuery.data.meta.total} tenants
                    </span>
                )}
            </form>

            <div style={{ display: 'grid', gridTemplateColumns: 'minmax(520px, 1.15fr) minmax(420px, .85fr)', gap: 14, alignItems: 'start' }}>
                <TenantTable
                    query={tenantsQuery}
                    selectedSlug={selectedSlug}
                    onSelect={selectTenant}
                    page={page}
                    onPage={setPage}
                />
                <TenantDetailPanel
                    query={detailQuery}
                    selectedSlug={selectedSlug}
                    userPage={userPage}
                    onUserPage={setUserPage}
                    onUpdated={() => {
                        void queryClient.invalidateQueries({ queryKey: ['tenant-control'] });
                    }}
                />
            </div>

            {provisionOpen && (
                <TenantProvisionDialog
                    onClose={() => setProvisionOpen(false)}
                    onProvisioned={(created) => {
                        setSelectedSlug(created.tenant.slug);
                        setUserPage(1);
                        void queryClient.invalidateQueries({ queryKey: ['tenant-control'] });
                    }}
                />
            )}
        </div>
    );
}

function TenantTable({
    query,
    selectedSlug,
    onSelect,
    page,
    onPage,
}: {
    query: UseQueryResult<{ data: PlatformTenant[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }>;
    selectedSlug: string | null;
    onSelect: (slug: string) => void;
    page: number;
    onPage: (page: number) => void;
}): ReactNode {
    if (query.isLoading) return <PanelState testId="tenant-control-list-loading" text="Loading tenants…" />;
    if (query.isError) return <PanelState testId="tenant-control-list-error" text={toAdminError(query.error).message} error />;
    const response = query.data;
    if (!response || response.data.length === 0) {
        return <PanelState testId="tenant-control-list-empty" text="No tenants match the current filters." />;
    }

    return (
        <section style={panelStyle} aria-label="Tenant registry">
            <div style={{ overflowX: 'auto' }}>
                <table data-testid="tenant-control-table" style={tableStyle}>
                    <thead>
                        <tr style={headRowStyle}>
                            <th style={cellStyle}>Tenant</th>
                            <th style={cellStyle}>Status</th>
                            <th style={cellStyle}>Projects</th>
                            <th style={cellStyle}>Users</th>
                            <th style={cellStyle}><span style={srOnly}>Open</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        {response.data.map((tenant) => {
                            const selected = tenant.slug === selectedSlug;
                            return (
                                <tr
                                    key={tenant.slug}
                                    data-testid={`tenant-control-row-${tenant.slug}`}
                                    data-selected={selected ? 'true' : 'false'}
                                    style={{
                                        borderTop: '1px solid var(--panel-border)',
                                        background: selected ? 'rgba(99,102,241,.09)' : 'transparent',
                                    }}
                                >
                                    <td style={cellStyle}>
                                        <strong style={{ display: 'block', color: 'var(--fg-0)', fontWeight: 550 }}>{tenant.name}</strong>
                                        <span style={{ color: 'var(--fg-3)', fontFamily: 'var(--font-mono)', fontSize: 10.5 }}>{tenant.slug}</span>
                                    </td>
                                    <td style={cellStyle}><StatusPill status={tenant.status} /></td>
                                    <td style={cellStyle}>{tenant.project_count}</td>
                                    <td style={cellStyle}>{tenant.member_count}</td>
                                    <td style={{ ...cellStyle, textAlign: 'right' }}>
                                        <button
                                            type="button"
                                            data-testid={`tenant-control-row-${tenant.slug}-open`}
                                            aria-label={`Open tenant ${tenant.name}`}
                                            onClick={() => onSelect(tenant.slug)}
                                            style={secondaryButton}
                                        >
                                            Open
                                        </button>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            <Pagination
                current={response.meta.current_page}
                last={response.meta.last_page}
                onPage={onPage}
                testId="tenant-control-list"
                fallbackCurrent={page}
            />
        </section>
    );
}

function TenantDetailPanel({
    query,
    selectedSlug,
    userPage,
    onUserPage,
    onUpdated,
}: {
    query: UseQueryResult<TenantDetail>;
    selectedSlug: string | null;
    userPage: number;
    onUserPage: (page: number) => void;
    onUpdated: () => void;
}): ReactNode {
    if (selectedSlug === null) return <PanelState testId="tenant-control-detail-empty" text="Select a tenant to inspect its users and access." />;
    if (query.isLoading) return <PanelState testId="tenant-control-detail-loading" text="Loading tenant access…" />;
    if (query.isError) return <PanelState testId="tenant-control-detail-error" text={toAdminError(query.error).message} error />;
    if (!query.data) return null;

    return (
        <TenantDetailContent
            detail={query.data}
            userPage={userPage}
            onUserPage={onUserPage}
            onUpdated={onUpdated}
        />
    );
}

function TenantDetailContent({
    detail,
    userPage,
    onUserPage,
    onUpdated,
}: {
    detail: TenantDetail;
    userPage: number;
    onUserPage: (page: number) => void;
    onUpdated: () => void;
}): ReactNode {
    const [name, setName] = useState(detail.tenant.name);
    const [status, setStatus] = useState<TenantStatus>(detail.tenant.status);
    const [preview, setPreview] = useState<Awaited<ReturnType<typeof tenantControlApi.lifecyclePreview>> | null>(null);

    useEffect(() => {
        setName(detail.tenant.name);
        setStatus(detail.tenant.status);
        setPreview(null);
    }, [detail.tenant.name, detail.tenant.status, detail.tenant.slug]);

    const mutation = useMutation({
        mutationFn: (confirmToken?: string) => tenantControlApi.update(detail.tenant.slug, {
            name: name.trim(),
            status,
            confirm_token: confirmToken,
        }),
        onSuccess: () => {
            setPreview(null);
            onUpdated();
        },
    });
    const previewMutation = useMutation({
        mutationFn: () => tenantControlApi.lifecyclePreview(detail.tenant.slug, status),
        onSuccess: setPreview,
    });
    const updateError = mutation.isError
        ? toAdminError(mutation.error)
        : previewMutation.isError
          ? toAdminError(previewMutation.error)
          : null;
    const busy = mutation.isPending || previewMutation.isPending;
    const save = () => {
        if (status !== detail.tenant.status) {
            previewMutation.mutate();
        } else {
            mutation.mutate(undefined);
        }
    };

    return (
        <section
            data-testid="tenant-control-detail"
            data-state={updateError ? 'error' : busy ? 'loading' : 'ready'}
            aria-busy={busy}
            style={{ ...panelStyle, padding: 14 }}
        >
            <div style={{ display: 'flex', alignItems: 'flex-start', gap: 8 }}>
                <div>
                    <h2 style={{ margin: 0, color: 'var(--fg-0)', fontSize: 15 }}>{detail.tenant.name}</h2>
                    <span style={{ color: 'var(--fg-3)', fontFamily: 'var(--font-mono)', fontSize: 10.5 }}>{detail.tenant.slug}</span>
                </div>
                <span style={{ flex: 1 }} />
                <StatusPill status={detail.tenant.status} testId="tenant-control-detail-current-status" />
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 140px auto', gap: 7, marginTop: 14 }}>
                <label htmlFor="tenant-control-detail-name" style={labelStyle}>
                    <span>Name</span>
                    <input
                        id="tenant-control-detail-name"
                        data-testid="tenant-control-detail-name"
                        value={name}
                        maxLength={200}
                        onChange={(event) => setName(event.target.value)}
                        style={inputStyle}
                    />
                </label>
                <label htmlFor="tenant-control-detail-status" style={labelStyle}>
                    <span>Status</span>
                    <select
                        id="tenant-control-detail-status"
                        data-testid="tenant-control-detail-status"
                        value={status}
                        onChange={(event) => setStatus(event.target.value as TenantStatus)}
                        style={inputStyle}
                    >
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                        <option value="archived">Archived</option>
                    </select>
                </label>
                <button
                    type="button"
                    data-testid="tenant-control-detail-save"
                    disabled={busy || name.trim() === ''}
                    onClick={save}
                    style={{ ...secondaryButton, alignSelf: 'end' }}
                >
                    {busy ? 'Saving…' : 'Save'}
                </button>
            </div>
            <p style={{ margin: '7px 0 0', color: 'var(--fg-3)', fontSize: 10.5, lineHeight: 1.4 }}>
                Suspended and archived tenants disappear from team switching and their tenant-scoped requests are blocked.
            </p>
            {updateError && <p role="alert" style={{ color: 'var(--err)', fontSize: 11 }}>{updateError.message}</p>}

            {preview && (
                <div
                    role="alertdialog"
                    aria-labelledby="tenant-lifecycle-confirm-title"
                    aria-describedby="tenant-lifecycle-confirm-description"
                    data-testid="tenant-control-lifecycle-confirm"
                    style={{ ...userCardStyle, marginTop: 10, borderColor: 'rgba(245,158,11,.5)' }}
                >
                    <strong id="tenant-lifecycle-confirm-title" style={{ color: 'var(--fg-0)' }}>
                        Confirm {preview.transition.from} → {preview.transition.to}
                    </strong>
                    <p id="tenant-lifecycle-confirm-description" style={{ color: 'var(--fg-2)', fontSize: 11 }}>
                        This changes access for {preview.tenant.member_count} users across {preview.tenant.project_count} projects.
                        The confirmation is single-use and expires shortly.
                    </p>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <button
                            type="button"
                            data-testid="tenant-control-lifecycle-cancel"
                            onClick={() => setPreview(null)}
                            style={secondaryButton}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            data-testid="tenant-control-lifecycle-confirm-submit"
                            onClick={() => mutation.mutate(preview.confirm_token)}
                            style={primaryButton}
                        >
                            Confirm lifecycle change
                        </button>
                    </div>
                </div>
            )}

            <div style={{ display: 'flex', gap: 8, margin: '16px 0 8px', color: 'var(--fg-2)', fontSize: 11 }}>
                <span>{detail.tenant.project_count} projects</span>
                <span>·</span>
                <span>{detail.users.meta.total} users</span>
            </div>

            {detail.users.data.length === 0 ? (
                <p data-testid="tenant-control-users-empty" style={{ color: 'var(--fg-3)', fontSize: 11.5 }}>No associated users.</p>
            ) : (
                <div data-testid="tenant-control-users" style={{ display: 'grid', gap: 7 }}>
                    {detail.users.data.map((user) => <UserAccessCard key={user.id} user={user} />)}
                </div>
            )}

            <Pagination
                current={detail.users.meta.current_page}
                last={detail.users.meta.last_page}
                onPage={onUserPage}
                testId="tenant-control-users"
                fallbackCurrent={userPage}
            />
        </section>
    );
}

function UserAccessCard({ user }: { user: TenantUserAccess }): ReactNode {
    const state = user.deleted_at ? 'deleted' : user.is_active ? 'active' : 'inactive';
    return (
        <article data-testid={`tenant-control-user-${user.id}`} style={userCardStyle}>
            <div style={{ display: 'flex', gap: 8, alignItems: 'flex-start' }}>
                <div style={{ minWidth: 0 }}>
                    <strong style={{ display: 'block', color: 'var(--fg-0)', fontSize: 12.5 }}>{user.name}</strong>
                    <span style={{ display: 'block', color: 'var(--fg-3)', fontSize: 10.5, overflow: 'hidden', textOverflow: 'ellipsis' }}>{user.email}</span>
                </div>
                <span style={{ flex: 1 }} />
                <span style={{ ...miniPill, color: state === 'active' ? '#6ee7b7' : 'var(--err)' }}>{state}</span>
            </div>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 5, marginTop: 8 }}>
                {user.roles.map((role) => <span key={role} style={miniPill}>{role}</span>)}
                {user.roles.length === 0 && <span style={{ color: 'var(--fg-3)', fontSize: 10.5 }}>No global role</span>}
            </div>
            <div style={{ marginTop: 8, color: 'var(--fg-2)', fontSize: 10.5, lineHeight: 1.5 }}>
                {user.all_projects ? (
                    <strong style={{ color: 'var(--fg-1)' }}>Access: all tenant projects</strong>
                ) : user.memberships.length > 0 ? (
                    <>
                        Access:{' '}
                        {user.memberships.map((membership) => (
                            <span key={membership.id} style={{ marginRight: 6 }}>
                                {membership.project_key} ({membership.role})
                            </span>
                        ))}
                    </>
                ) : (
                    <span>No project access</span>
                )}
            </div>
            <details style={{ marginTop: 7 }}>
                <summary
                    data-testid={`tenant-control-user-${user.id}-permissions`}
                    style={{ cursor: 'pointer', color: 'var(--fg-3)', fontSize: 10.5 }}
                >
                    {user.permissions.length} effective permissions
                </summary>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4, marginTop: 6 }}>
                    {user.permissions.map((permission) => (
                        <code key={permission} style={{ ...miniPill, fontSize: 9.5 }}>{permission}</code>
                    ))}
                </div>
            </details>
        </article>
    );
}

function StatusPill({ status, testId }: { status: TenantStatus; testId?: string }): ReactNode {
    const color = status === 'active' ? '#6ee7b7' : status === 'suspended' ? '#fbbf24' : '#fca5a5';
    return <span data-testid={testId} style={{ ...miniPill, color }}>{status}</span>;
}

function Pagination({
    current,
    last,
    onPage,
    testId,
    fallbackCurrent,
}: {
    current: number;
    last: number;
    onPage: (page: number) => void;
    testId: string;
    fallbackCurrent: number;
}): ReactNode {
    if (last <= 1) return null;
    return (
        <div style={{ display: 'flex', justifyContent: 'flex-end', alignItems: 'center', gap: 8, padding: '10px 4px 2px' }}>
            <button
                type="button"
                data-testid={`${testId}-previous`}
                disabled={current <= 1}
                onClick={() => onPage(Math.max(1, (current || fallbackCurrent) - 1))}
                style={secondaryButton}
            >
                Previous
            </button>
            <span style={{ color: 'var(--fg-3)', fontSize: 10.5 }}>{current} / {last}</span>
            <button
                type="button"
                data-testid={`${testId}-next`}
                disabled={current >= last}
                onClick={() => onPage(Math.min(last, (current || fallbackCurrent) + 1))}
                style={secondaryButton}
            >
                Next
            </button>
        </div>
    );
}

function PanelState({ testId, text, error = false }: { testId: string; text: string; error?: boolean }): ReactNode {
    const state = error ? 'error' : testId.endsWith('-loading') ? 'loading' : testId.endsWith('-empty') ? 'empty' : 'idle';
    return (
        <section
            data-testid={testId}
            data-state={state}
            aria-busy={state === 'loading'}
            role={error ? 'alert' : undefined}
            style={{ ...panelStyle, padding: 24, color: error ? 'var(--err)' : 'var(--fg-3)', fontSize: 12 }}
        >
            {text}
        </section>
    );
}

const panelStyle: React.CSSProperties = {
    border: '1px solid var(--panel-border)',
    borderRadius: 11,
    background: 'var(--bg-1)',
    overflow: 'hidden',
};

const tableStyle: React.CSSProperties = {
    width: '100%',
    borderCollapse: 'collapse',
    fontSize: 11.5,
};

const headRowStyle: React.CSSProperties = {
    color: 'var(--fg-3)',
    textAlign: 'left',
    textTransform: 'uppercase',
    letterSpacing: '.05em',
    fontSize: 9.5,
};

const cellStyle: React.CSSProperties = {
    padding: '9px 10px',
    verticalAlign: 'middle',
    color: 'var(--fg-2)',
};

const inputStyle: React.CSSProperties = {
    padding: '6px 9px',
    borderRadius: 7,
    border: '1px solid var(--panel-border-strong)',
    background: 'var(--bg-3)',
    color: 'var(--fg-0)',
    fontSize: 11.5,
};

const primaryButton: React.CSSProperties = {
    padding: '7px 12px',
    borderRadius: 7,
    border: '1px solid var(--accent, #6366f1)',
    background: 'var(--accent, #6366f1)',
    color: 'white',
    cursor: 'pointer',
    fontSize: 12,
};

const secondaryButton: React.CSSProperties = {
    padding: '5px 9px',
    borderRadius: 6,
    border: '1px solid var(--panel-border-strong)',
    background: 'transparent',
    color: 'var(--fg-1)',
    cursor: 'pointer',
    fontSize: 10.5,
};

const labelStyle: React.CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    gap: 4,
    color: 'var(--fg-3)',
    fontSize: 10,
};

const miniPill: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    padding: '2px 6px',
    borderRadius: 999,
    border: '1px solid var(--panel-border-strong)',
    color: 'var(--fg-2)',
    fontSize: 9.5,
};

const userCardStyle: React.CSSProperties = {
    padding: 10,
    borderRadius: 8,
    border: '1px solid var(--panel-border)',
    background: 'var(--bg-2)',
};

const visuallyLabelled: React.CSSProperties = { display: 'contents' };

const srOnly: React.CSSProperties = {
    position: 'absolute',
    width: 1,
    height: 1,
    padding: 0,
    margin: -1,
    overflow: 'hidden',
    clip: 'rect(0, 0, 0, 0)',
    whiteSpace: 'nowrap',
    border: 0,
};
