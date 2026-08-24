import { useEffect, useState, type CSSProperties, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { toAdminError } from '../../admin/shared/errors';
import {
    superAdminsApi,
    type GlobalSuperAdmin,
    type PageMeta,
    type SuperAdminStatus,
    type SuperAdminTenant,
} from './super-admins.api';

export function SuperAdminsView(): ReactNode {
    const [searchDraft, setSearchDraft] = useState('');
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<SuperAdminStatus | ''>('');
    const [page, setPage] = useState(1);
    const [selectedUserId, setSelectedUserId] = useState<number | null>(null);
    const [tenantPage, setTenantPage] = useState(1);

    const roster = useQuery({
        queryKey: ['system-super-admins', 'list', search, status, page],
        queryFn: () => superAdminsApi.list({ search, status, page, per_page: 25 }),
        staleTime: 15_000,
    });

    useEffect(() => {
        const rows = roster.data?.data ?? [];
        if (selectedUserId !== null && !rows.some((row) => row.id === selectedUserId)) {
            setSelectedUserId(rows[0]?.id ?? null);
            setTenantPage(1);
        } else if (selectedUserId === null && rows.length > 0) {
            setSelectedUserId(rows[0].id);
        }
    }, [roster.data, selectedUserId]);

    const tenantAssociations = useQuery({
        queryKey: ['system-super-admins', 'tenants', selectedUserId, tenantPage],
        queryFn: () => superAdminsApi.tenants(selectedUserId as number, tenantPage),
        enabled: selectedUserId !== null,
        staleTime: 10_000,
    });

    return (
        <div
            data-testid="system-super-admins-view"
            style={{ boxSizing: 'border-box', padding: 24, width: '100%' }}
        >
            <header style={{ marginBottom: 18 }}>
                <h1 style={{ color: 'var(--fg-0)', fontSize: 19, margin: 0 }}>Super Admins</h1>
                <p style={{ color: 'var(--fg-3)', fontSize: 11.5, margin: '5px 0 0' }}>
                    Read-only global roster and operational tenant associations.
                </p>
            </header>

            <form
                data-testid="system-super-admins-filters"
                onSubmit={(event) => {
                    event.preventDefault();
                    setPage(1);
                    setSearch(searchDraft.trim());
                }}
                style={{ display: 'flex', gap: 8, marginBottom: 14 }}
            >
                <label>
                    <span style={srOnly}>Search Super Admins</span>
                    <input
                        aria-label="Search Super Admins"
                        data-testid="system-super-admins-search"
                        onChange={(event) => setSearchDraft(event.target.value)}
                        placeholder="Search name or email"
                        style={{ ...inputStyle, minWidth: 250 }}
                        value={searchDraft}
                    />
                </label>
                <label>
                    <span style={srOnly}>Identity status</span>
                    <select
                        aria-label="Identity status"
                        data-testid="system-super-admins-status"
                        onChange={(event) => {
                            setStatus(event.target.value as SuperAdminStatus | '');
                            setPage(1);
                        }}
                        style={inputStyle}
                        value={status}
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="deleted">Deleted</option>
                    </select>
                </label>
                <button
                    data-testid="system-super-admins-search-submit"
                    style={buttonStyle}
                    type="submit"
                >
                    Search
                </button>
                {roster.data && (
                    <span
                        data-testid="system-super-admins-total"
                        style={{ alignSelf: 'center', color: 'var(--fg-3)', fontSize: 11.5 }}
                    >
                        {roster.data.meta.total} Super Admins
                    </span>
                )}
            </form>

            <div
                style={{
                    alignItems: 'start',
                    display: 'grid',
                    gap: 14,
                    gridTemplateColumns: 'minmax(560px, 1.15fr) minmax(360px, .85fr)',
                }}
            >
                <RosterPanel
                    error={roster.isError ? toAdminError(roster.error).message : null}
                    loading={roster.isLoading}
                    meta={roster.data?.meta}
                    onPage={setPage}
                    onSelect={(id) => {
                        setSelectedUserId(id);
                        setTenantPage(1);
                    }}
                    rows={roster.data?.data ?? []}
                    selectedUserId={selectedUserId}
                />
                <TenantPanel
                    error={tenantAssociations.isError
                        ? toAdminError(tenantAssociations.error).message
                        : null}
                    loading={tenantAssociations.isLoading}
                    meta={tenantAssociations.data?.meta}
                    onPage={setTenantPage}
                    rows={tenantAssociations.data?.data ?? []}
                    selected={selectedUserId !== null}
                    user={tenantAssociations.data?.user}
                />
            </div>
        </div>
    );
}

function RosterPanel({
    rows,
    meta,
    loading,
    error,
    selectedUserId,
    onSelect,
    onPage,
}: {
    rows: GlobalSuperAdmin[];
    meta?: PageMeta;
    loading: boolean;
    error: string | null;
    selectedUserId: number | null;
    onSelect: (id: number) => void;
    onPage: (page: number) => void;
}): ReactNode {
    if (loading) return <PanelState state="loading" text="Loading Super Admins…" />;
    if (error !== null) return <PanelState state="error" text={error} />;
    if (rows.length === 0) return <PanelState state="empty" text="No Super Admins match the filters." />;

    return (
        <section aria-label="Global Super Admin roster" data-state="ready" style={panelStyle}>
            <div style={{ overflowX: 'auto' }}>
                <table data-testid="system-super-admins-table" style={tableStyle}>
                    <thead>
                        <tr>
                            <th style={cellStyle}>Identity</th>
                            <th style={cellStyle}>Status</th>
                            <th style={cellStyle}>System Admin</th>
                            <th style={cellStyle}>Tenants</th>
                            <th style={cellStyle}><span style={srOnly}>Open</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((user) => (
                            <tr
                                data-selected={selectedUserId === user.id ? 'true' : 'false'}
                                data-testid={`system-super-admin-row-${user.id}`}
                                key={user.id}
                                style={{
                                    background: selectedUserId === user.id ? 'rgba(99,102,241,.09)' : 'transparent',
                                    borderTop: '1px solid var(--panel-border)',
                                }}
                            >
                                <td style={cellStyle}>
                                    <strong style={{ color: 'var(--fg-0)', display: 'block' }}>{user.name}</strong>
                                    <span style={{ color: 'var(--fg-3)', fontSize: 10.5 }}>{user.email}</span>
                                </td>
                                <td style={cellStyle}>{user.deleted_at ? 'Deleted' : user.is_active ? 'Active' : 'Inactive'}</td>
                                <td style={cellStyle}>{user.is_system_admin ? 'Yes' : 'No'}</td>
                                <td style={cellStyle}>{user.tenant_count}</td>
                                <td style={cellStyle}>
                                    <button
                                        aria-label={`Open tenant associations for ${user.name}`}
                                        data-testid={`system-super-admin-row-${user.id}-open`}
                                        onClick={() => onSelect(user.id)}
                                        style={buttonStyle}
                                        type="button"
                                    >
                                        Open
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {meta && <Pagination meta={meta} onPage={onPage} testId="system-super-admins-list" />}
        </section>
    );
}

function TenantPanel({
    selected,
    user,
    rows,
    meta,
    loading,
    error,
    onPage,
}: {
    selected: boolean;
    user?: Omit<GlobalSuperAdmin, 'tenant_count'>;
    rows: SuperAdminTenant[];
    meta?: PageMeta;
    loading: boolean;
    error: string | null;
    onPage: (page: number) => void;
}): ReactNode {
    if (!selected) return <PanelState state="empty" text="Select a Super Admin." />;
    if (loading) return <PanelState state="loading" text="Loading tenant associations…" />;
    if (error !== null) return <PanelState state="error" text={error} />;

    return (
        <section aria-label="Super Admin tenant associations" data-state="ready" style={panelStyle}>
            <div style={{ borderBottom: '1px solid var(--panel-border)', padding: 14 }}>
                <strong data-testid="system-super-admins-selected-name">{user?.name}</strong>
                <div style={{ color: 'var(--fg-3)', fontSize: 11 }}>{user?.email}</div>
            </div>
            {rows.length === 0 ? (
                <p data-testid="system-super-admins-tenants-empty" style={{ color: 'var(--fg-3)', padding: 14 }}>
                    No tenant assigned.
                </p>
            ) : (
                <ul data-testid="system-super-admins-tenants" style={{ listStyle: 'none', margin: 0, padding: 0 }}>
                    {rows.map((tenant) => (
                        <li
                            data-testid={`system-super-admin-tenant-${tenant.slug}`}
                            key={tenant.slug}
                            style={{ borderBottom: '1px solid var(--panel-border)', padding: 14 }}
                        >
                            <strong style={{ color: 'var(--fg-0)' }}>{tenant.name}</strong>
                            <div style={{ color: 'var(--fg-3)', fontFamily: 'var(--font-mono)', fontSize: 10.5 }}>
                                {tenant.slug} · {tenant.status} · {tenant.project_count} projects
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            {meta && <Pagination meta={meta} onPage={onPage} testId="system-super-admins-tenants" />}
        </section>
    );
}

function PanelState({ state, text }: { state: 'loading' | 'empty' | 'error'; text: string }): ReactNode {
    return (
        <section
            aria-busy={state === 'loading'}
            data-state={state}
            data-testid={`system-super-admins-${state}`}
            style={{ ...panelStyle, color: state === 'error' ? 'var(--err)' : 'var(--fg-3)', padding: 24 }}
        >
            {text}
        </section>
    );
}

function Pagination({ meta, onPage, testId }: { meta: PageMeta; onPage: (page: number) => void; testId: string }): ReactNode {
    return (
        <nav aria-label="Pagination" style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', padding: 12 }}>
            <button
                aria-label="Previous page"
                data-testid={`${testId}-previous`}
                disabled={meta.current_page <= 1}
                onClick={() => onPage(meta.current_page - 1)}
                style={buttonStyle}
                type="button"
            >
                Previous
            </button>
            <span style={{ alignSelf: 'center', color: 'var(--fg-3)', fontSize: 11 }}>
                {meta.current_page} / {meta.last_page}
            </span>
            <button
                aria-label="Next page"
                data-testid={`${testId}-next`}
                disabled={meta.current_page >= meta.last_page}
                onClick={() => onPage(meta.current_page + 1)}
                style={buttonStyle}
                type="button"
            >
                Next
            </button>
        </nav>
    );
}

const panelStyle: CSSProperties = {
    background: 'var(--bg-1)',
    border: '1px solid var(--panel-border)',
    borderRadius: 10,
    overflow: 'hidden',
};

const tableStyle: CSSProperties = {
    borderCollapse: 'collapse',
    fontSize: 12,
    width: '100%',
};

const cellStyle: CSSProperties = {
    padding: '10px 12px',
    textAlign: 'left',
};

const inputStyle: CSSProperties = {
    background: 'var(--bg-1)',
    border: '1px solid var(--panel-border)',
    borderRadius: 8,
    color: 'var(--fg-1)',
    padding: '8px 10px',
};

const buttonStyle: CSSProperties = {
    background: 'var(--bg-2)',
    border: '1px solid var(--panel-border)',
    borderRadius: 7,
    color: 'var(--fg-1)',
    cursor: 'pointer',
    padding: '7px 10px',
};

const srOnly: CSSProperties = {
    height: 1,
    margin: -1,
    overflow: 'hidden',
    padding: 0,
    position: 'absolute',
    width: 1,
};
