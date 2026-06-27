import { Icon } from '../../../components/Icons';
import type { CampaignGrant, CampaignTenantGrant, ProjectRole, Tenant } from './invitations.api';

/*
 * Editor for a campaign's provisioning grant — what an accepted code actually
 * gives the redeemer: a Spatie role + KB project memberships, optionally across
 * several tenants ("teams") at once. Projects are free-form keys (the package
 * validates them as plain strings, max 120), so they're a comma-separated
 * input, not a fixed dropdown. `super-admin` is rejected by the package in every
 * grant slot; the drawer guards it client-side before submit.
 *
 * UI-friendly draft shape: `projects` is a comma string here and is parsed to a
 * string[] only at submit (buildGrant). The component is fully controlled
 * (value + onChange) so its state lifts into the campaign form (R29).
 */

const PROJECT_ROLES: ProjectRole[] = ['member', 'admin', 'owner'];

export interface GrantRowDraft {
    tenant_id: string;
    role: string;
    projects: string;
    project_role: string;
}

export interface GrantDraft {
    role: string;
    projects: string;
    project_role: string;
    tenants: GrantRowDraft[];
}

export const EMPTY_GRANT_DRAFT: GrantDraft = { role: '', projects: '', project_role: '', tenants: [] };

const splitProjects = (raw: string): string[] =>
    raw
        .split(',')
        .map((p) => p.trim())
        .filter((p) => p !== '');

/** Hydrate a draft from a server grant (for the edit flow). */
export function grantToDraft(grant: CampaignGrant | null | undefined): GrantDraft {
    if (!grant) return { ...EMPTY_GRANT_DRAFT, tenants: [] };
    return {
        role: grant.role ?? '',
        projects: (grant.projects ?? []).join(', '),
        project_role: grant.project_role ?? '',
        tenants: (grant.tenants ?? []).map((t) => ({
            tenant_id: t.tenant_id,
            role: t.role ?? '',
            projects: (t.projects ?? []).join(', '),
            project_role: t.project_role ?? '',
        })),
    };
}

/**
 * Convert a draft to a `CampaignGrant`, or `undefined` when the admin left the
 * whole editor empty (so we omit `grant` rather than POST an empty object).
 */
export function buildGrant(draft: GrantDraft): CampaignGrant | undefined {
    const role = draft.role.trim();
    const projects = splitProjects(draft.projects);
    const projectRole = draft.project_role.trim();

    const tenants = draft.tenants
        .filter((t) => t.tenant_id.trim() !== '')
        .map((t) => {
            const row: CampaignTenantGrant = { tenant_id: t.tenant_id.trim() };
            if (t.role.trim()) row.role = t.role.trim();
            const tp = splitProjects(t.projects);
            if (tp.length) row.projects = tp;
            if (t.project_role.trim()) row.project_role = t.project_role.trim() as ProjectRole;
            return row;
        });

    const hasPrimary = role !== '' || projects.length > 0 || projectRole !== '';
    if (!hasPrimary && tenants.length === 0) return undefined;

    const grant: CampaignGrant = {};
    if (role) grant.role = role;
    if (projects.length) grant.projects = projects;
    if (projectRole) grant.project_role = projectRole as ProjectRole;
    if (tenants.length) grant.tenants = tenants;
    return grant;
}

/** True if any role slot is the forbidden super-admin (package rejects it). */
export function grantHasSuperAdmin(draft: GrantDraft): boolean {
    if (draft.role.trim() === 'super-admin') return true;
    return draft.tenants.some((t) => t.role.trim() === 'super-admin');
}

export interface GrantEditorProps {
    value: GrantDraft;
    onChange: (next: GrantDraft) => void;
    tenants: Tenant[];
}

export function GrantEditor({ value, onChange, tenants }: GrantEditorProps) {
    const patch = (p: Partial<GrantDraft>) => onChange({ ...value, ...p });

    const addTenant = () =>
        patch({ tenants: [...value.tenants, { tenant_id: '', role: '', projects: '', project_role: '' }] });
    const removeTenant = (i: number) => patch({ tenants: value.tenants.filter((_, idx) => idx !== i) });
    const patchTenant = (i: number, p: Partial<GrantRowDraft>) =>
        patch({ tenants: value.tenants.map((t, idx) => (idx === i ? { ...t, ...p } : t)) });

    return (
        <fieldset
            data-testid="admin-invitations-campaign-grant"
            style={{ border: '1px solid var(--panel-border, rgba(255,255,255,.12))', borderRadius: 8, padding: 12, display: 'flex', flexDirection: 'column', gap: 10 }}
        >
            <legend style={{ fontSize: 12, color: 'var(--fg-2)', padding: '0 6px' }}>Grant on redemption</legend>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                <label htmlFor="grant-role" style={labelStyle}>Role</label>
                <input
                    id="grant-role"
                    data-testid="admin-invitations-campaign-grant-role"
                    value={value.role}
                    onChange={(e) => patch({ role: e.target.value })}
                    placeholder="e.g. member (never super-admin)"
                    style={inputStyle}
                />
            </div>

            <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 4, flex: 1, minWidth: 160 }}>
                    <label htmlFor="grant-projects" style={labelStyle}>Projects (comma-separated)</label>
                    <input
                        id="grant-projects"
                        data-testid="admin-invitations-campaign-grant-projects"
                        value={value.projects}
                        onChange={(e) => patch({ projects: e.target.value })}
                        placeholder="hr-portal, engineering"
                        style={inputStyle}
                    />
                </div>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                    <label htmlFor="grant-project-role" style={labelStyle}>Project role</label>
                    <select
                        id="grant-project-role"
                        data-testid="admin-invitations-campaign-grant-project-role"
                        value={value.project_role}
                        onChange={(e) => patch({ project_role: e.target.value })}
                        style={inputStyle}
                    >
                        <option value="">—</option>
                        {PROJECT_ROLES.map((r) => (
                            <option key={r} value={r}>{r}</option>
                        ))}
                    </select>
                </div>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <span style={{ ...labelStyle, flex: 1 }}>Per-tenant grants (optional)</span>
                    <button type="button" data-testid="admin-invitations-campaign-grant-add-tenant" onClick={addTenant} style={addBtn}>
                        <Icon.Plus size={12} /> Add tenant
                    </button>
                </div>

                {value.tenants.map((row, i) => (
                    <div
                        key={i}
                        data-testid={`admin-invitations-campaign-grant-tenant-${i}`}
                        style={{ display: 'flex', gap: 6, alignItems: 'flex-end', flexWrap: 'wrap', border: '1px dashed var(--panel-border, rgba(255,255,255,.1))', borderRadius: 6, padding: 8 }}
                    >
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
                            <label htmlFor={`grant-tenant-${i}-id`} style={labelStyle}>Tenant</label>
                            <select
                                id={`grant-tenant-${i}-id`}
                                data-testid={`admin-invitations-campaign-grant-tenant-${i}-id`}
                                value={row.tenant_id}
                                onChange={(e) => patchTenant(i, { tenant_id: e.target.value })}
                                style={inputStyle}
                            >
                                <option value="">Select…</option>
                                {tenants.map((t) => (
                                    <option key={t.id} value={t.id}>{t.name}</option>
                                ))}
                            </select>
                        </div>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
                            <label htmlFor={`grant-tenant-${i}-role`} style={labelStyle}>Role</label>
                            <input
                                id={`grant-tenant-${i}-role`}
                                data-testid={`admin-invitations-campaign-grant-tenant-${i}-role`}
                                value={row.role}
                                onChange={(e) => patchTenant(i, { role: e.target.value })}
                                style={{ ...inputStyle, width: 110 }}
                            />
                        </div>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 3, flex: 1, minWidth: 140 }}>
                            <label htmlFor={`grant-tenant-${i}-projects`} style={labelStyle}>Projects</label>
                            <input
                                id={`grant-tenant-${i}-projects`}
                                data-testid={`admin-invitations-campaign-grant-tenant-${i}-projects`}
                                value={row.projects}
                                onChange={(e) => patchTenant(i, { projects: e.target.value })}
                                placeholder="proj-a, proj-b"
                                style={inputStyle}
                            />
                        </div>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
                            <label htmlFor={`grant-tenant-${i}-prole`} style={labelStyle}>Project role</label>
                            <select
                                id={`grant-tenant-${i}-prole`}
                                data-testid={`admin-invitations-campaign-grant-tenant-${i}-project-role`}
                                value={row.project_role}
                                onChange={(e) => patchTenant(i, { project_role: e.target.value })}
                                style={inputStyle}
                            >
                                <option value="">—</option>
                                {PROJECT_ROLES.map((r) => (
                                    <option key={r} value={r}>{r}</option>
                                ))}
                            </select>
                        </div>
                        <button
                            type="button"
                            data-testid={`admin-invitations-campaign-grant-tenant-${i}-remove`}
                            aria-label={`Remove tenant grant ${i + 1}`}
                            onClick={() => removeTenant(i)}
                            style={removeBtn}
                        >
                            <Icon.Trash size={13} />
                        </button>
                    </div>
                ))}
            </div>
        </fieldset>
    );
}

const labelStyle: React.CSSProperties = { fontSize: 11, color: 'var(--fg-3)', textTransform: 'uppercase', letterSpacing: '0.04em' };

const inputStyle: React.CSSProperties = {
    padding: '6px 9px',
    borderRadius: 6,
    border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
    background: 'var(--bg-3, rgba(255,255,255,.04))',
    color: 'var(--fg-0)',
    fontSize: 12.5,
};

const addBtn: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    gap: 5,
    padding: '4px 10px',
    borderRadius: 6,
    border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
    background: 'transparent',
    color: 'var(--fg-1)',
    fontSize: 11.5,
    cursor: 'pointer',
};

const removeBtn: React.CSSProperties = {
    display: 'inline-flex',
    padding: 6,
    borderRadius: 6,
    border: '1px solid var(--panel-border, rgba(255,255,255,.12))',
    background: 'transparent',
    color: 'var(--fg-2)',
    cursor: 'pointer',
};
