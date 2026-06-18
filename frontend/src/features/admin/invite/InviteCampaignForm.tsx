import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import type {
    CampaignStatus,
    CampaignType,
    CreateCampaignPayload,
    InviteGrant,
    InviteCampaign,
    ProjectRole,
    UpdateCampaignPayload,
} from './admin-invite.api';

export interface InviteCampaignFormProps {
    campaign: InviteCampaign | null; // null = create, object = edit
    onSubmit: (payload: CreateCampaignPayload | UpdateCampaignPayload) => void;
    onClose: () => void;
    submitError?: string | null;
    isSubmitting?: boolean;
    /** Grantable role names (super-admin already filtered out by the API). */
    roleOptions?: string[];
    /** Known tenant project keys for the access grant (R18 — from the DB). */
    projectOptions?: string[];
}

const TYPES: CampaignType[] = ['single_use', 'multi_use', 'capacity', 'referral', 'waitlist_skip'];
const STATUSES: CampaignStatus[] = ['draft', 'active', 'paused', 'ended'];
const PROJECT_ROLES: ProjectRole[] = ['member', 'admin', 'owner'];

/**
 * Fold the grant form state into the API shape, or null when nothing is
 * granted (no role and no projects) — a null grant provisions nothing.
 */
function buildGrant(role: string, projects: string[], projectRole: ProjectRole): InviteGrant | null {
    if (role === '' && projects.length === 0) return null;
    return { role: role === '' ? null : role, projects, project_role: projectRole };
}

const fieldStyle = {
    width: '100%',
    padding: '8px 10px',
    borderRadius: 6,
    border: '1px solid var(--panel-border)',
    background: 'var(--bg-3)',
    color: 'var(--fg-1)',
    fontSize: 13,
};

/**
 * Create / edit dialog for an invite campaign (R11 testids, R15 a11y dialog).
 * On edit, `key` and `type` are read-only — both are stable identifiers that a
 * later phase keys codes off, so they must not move under existing codes.
 */
export function InviteCampaignForm({ campaign, onSubmit, onClose, submitError, isSubmitting, roleOptions = [], projectOptions = [] }: InviteCampaignFormProps): ReactNode {
    const isEdit = campaign !== null;
    const [key, setKey] = useState(campaign?.key ?? '');
    const [name, setName] = useState(campaign?.name ?? '');
    const [description, setDescription] = useState(campaign?.description ?? '');
    const [type, setType] = useState<CampaignType>(campaign?.type ?? 'multi_use');
    const [status, setStatus] = useState<CampaignStatus>(campaign?.status ?? 'draft');
    const [perUserLimit, setPerUserLimit] = useState(String(campaign?.per_user_limit ?? 1));

    // Provisioning grant: what the redeemer's account becomes.
    const [grantRole, setGrantRole] = useState(campaign?.grant?.role ?? '');
    const [grantProjects, setGrantProjects] = useState<string[]>(campaign?.grant?.projects ?? []);
    const [grantProjectRole, setGrantProjectRole] = useState<ProjectRole>(campaign?.grant?.project_role ?? 'member');

    const toggleProject = (projectKey: string) => {
        setGrantProjects((prev) =>
            prev.includes(projectKey) ? prev.filter((p) => p !== projectKey) : [...prev, projectKey],
        );
    };

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        const limit = Number.parseInt(perUserLimit, 10);

        const grant = buildGrant(grantRole, grantProjects, grantProjectRole);

        if (isEdit) {
            const payload: UpdateCampaignPayload = {};
            if (name !== campaign.name) payload.name = name;
            const normalizedDesc = description === '' ? null : description;
            if (normalizedDesc !== campaign.description) payload.description = normalizedDesc;
            if (status !== campaign.status) payload.status = status;
            if (Number.isFinite(limit) && limit !== campaign.per_user_limit) payload.per_user_limit = limit;
            // Only send grant when it actually changed — a JSON compare against
            // the original avoids clobbering it on an unrelated edit.
            if (JSON.stringify(grant) !== JSON.stringify(campaign.grant ?? null)) payload.grant = grant;
            onSubmit(payload);
            return;
        }

        const payload: CreateCampaignPayload = {
            key,
            name,
            description: description === '' ? null : description,
            type,
            status,
            per_user_limit: Number.isFinite(limit) ? limit : 1,
            grant,
        };
        onSubmit(payload);
    };

    return (
        <div
            data-testid="admin-invite-form-backdrop"
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
            style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.4)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 100 }}
        >
            <form
                role="dialog"
                aria-modal="true"
                aria-labelledby="admin-invite-form-title"
                data-testid="admin-invite-form"
                data-mode={isEdit ? 'edit' : 'create'}
                onSubmit={handleSubmit}
                style={{ width: 460, maxWidth: '92vw', background: 'var(--panel-solid)', border: '1px solid var(--panel-border)', borderRadius: 10, padding: 20, display: 'flex', flexDirection: 'column', gap: 12 }}
            >
                <h2 id="admin-invite-form-title" style={{ margin: 0, fontSize: 16, color: 'var(--fg-0)' }}>
                    {isEdit ? `Edit campaign: ${campaign.name}` : 'Create campaign'}
                </h2>

                <label htmlFor="admin-invite-form-key" style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--fg-2)' }}>
                    <span>Key (stable identifier)</span>
                    <input
                        id="admin-invite-form-key"
                        data-testid="admin-invite-form-key"
                        type="text"
                        required
                        readOnly={isEdit}
                        value={key}
                        onChange={(e) => setKey(e.target.value)}
                        placeholder="spring-beta"
                        pattern="[a-z0-9\-]+"
                        title="lowercase letters, digits and hyphens only"
                        style={fieldStyle}
                    />
                </label>

                <label htmlFor="admin-invite-form-name" style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--fg-2)' }}>
                    <span>Name</span>
                    <input id="admin-invite-form-name" data-testid="admin-invite-form-name" type="text" required value={name} onChange={(e) => setName(e.target.value)} placeholder="Spring Beta" style={fieldStyle} />
                </label>

                <label htmlFor="admin-invite-form-description" style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--fg-2)' }}>
                    <span>Description</span>
                    <textarea id="admin-invite-form-description" data-testid="admin-invite-form-description" value={description ?? ''} onChange={(e) => setDescription(e.target.value)} rows={2} style={{ ...fieldStyle, resize: 'vertical' }} />
                </label>

                <div style={{ display: 'flex', gap: 12 }}>
                    <label htmlFor="admin-invite-form-type" style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--fg-2)' }}>
                        <span>Type</span>
                        <select id="admin-invite-form-type" data-testid="admin-invite-form-type" disabled={isEdit} value={type} onChange={(e) => setType(e.target.value as CampaignType)} style={fieldStyle}>
                            {TYPES.map((t) => (
                                <option key={t} value={t}>{t}</option>
                            ))}
                        </select>
                    </label>

                    <label htmlFor="admin-invite-form-status" style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--fg-2)' }}>
                        <span>Status</span>
                        <select id="admin-invite-form-status" data-testid="admin-invite-form-status" value={status} onChange={(e) => setStatus(e.target.value as CampaignStatus)} style={fieldStyle}>
                            {STATUSES.map((s) => (
                                <option key={s} value={s}>{s}</option>
                            ))}
                        </select>
                    </label>

                    <label htmlFor="admin-invite-form-per-user" style={{ width: 120, display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--fg-2)' }}>
                        <span>Per-user limit</span>
                        <input id="admin-invite-form-per-user" data-testid="admin-invite-form-per-user" type="number" min={1} value={perUserLimit} onChange={(e) => setPerUserLimit(e.target.value)} style={fieldStyle} />
                    </label>
                </div>

                <fieldset
                    data-testid="admin-invite-form-grant"
                    style={{ border: '1px solid var(--panel-border)', borderRadius: 8, padding: '10px 12px', margin: 0, display: 'flex', flexDirection: 'column', gap: 10 }}
                >
                    <legend style={{ fontSize: 12, color: 'var(--fg-2)', padding: '0 6px' }}>Grant on redemption</legend>

                    <div style={{ display: 'flex', gap: 12 }}>
                        <label htmlFor="admin-invite-form-grant-role" style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--fg-2)' }}>
                            <span>Role granted to the user</span>
                            <select
                                id="admin-invite-form-grant-role"
                                data-testid="admin-invite-form-grant-role"
                                value={grantRole}
                                onChange={(e) => setGrantRole(e.target.value)}
                                style={fieldStyle}
                            >
                                <option value="">— no role —</option>
                                {roleOptions.map((r) => (
                                    <option key={r} value={r}>{r}</option>
                                ))}
                            </select>
                        </label>

                        <label htmlFor="admin-invite-form-grant-project-role" style={{ width: 140, display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--fg-2)' }}>
                            <span>Project role</span>
                            <select
                                id="admin-invite-form-grant-project-role"
                                data-testid="admin-invite-form-grant-project-role"
                                value={grantProjectRole}
                                onChange={(e) => setGrantProjectRole(e.target.value as ProjectRole)}
                                style={fieldStyle}
                            >
                                {PROJECT_ROLES.map((r) => (
                                    <option key={r} value={r}>{r}</option>
                                ))}
                            </select>
                        </label>
                    </div>

                    <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                        <span id="admin-invite-form-grant-projects-label" style={{ fontSize: 12, color: 'var(--fg-2)' }}>
                            Projects enabled in this tenant
                        </span>
                        {projectOptions.length === 0 ? (
                            <p data-testid="admin-invite-form-grant-projects-empty" style={{ fontSize: 12, color: 'var(--fg-3)', margin: 0 }}>
                                No projects yet — codes will grant the role only.
                            </p>
                        ) : (
                            <div
                                role="group"
                                aria-labelledby="admin-invite-form-grant-projects-label"
                                data-testid="admin-invite-form-grant-projects"
                                style={{ display: 'flex', flexWrap: 'wrap', gap: 8, maxHeight: 120, overflowY: 'auto' }}
                            >
                                {projectOptions.map((p) => (
                                    <label key={p} style={{ display: 'flex', alignItems: 'center', gap: 4, fontSize: 12, color: 'var(--fg-1)' }}>
                                        <input
                                            type="checkbox"
                                            data-testid={`admin-invite-form-grant-project-${p}`}
                                            checked={grantProjects.includes(p)}
                                            onChange={() => toggleProject(p)}
                                        />
                                        <span>{p}</span>
                                    </label>
                                ))}
                            </div>
                        )}
                    </div>
                </fieldset>

                {submitError && (
                    <p data-testid="admin-invite-form-error" role="alert" style={{ color: 'var(--err)', fontSize: 12, margin: 0 }}>
                        {submitError}
                    </p>
                )}

                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 4 }}>
                    <button type="button" data-testid="admin-invite-form-cancel" onClick={onClose} style={{ padding: '8px 14px', borderRadius: 6, border: '1px solid var(--panel-border)', background: 'transparent', color: 'var(--fg-1)', cursor: 'pointer' }}>
                        Cancel
                    </button>
                    <button type="submit" data-testid="admin-invite-form-submit" disabled={isSubmitting} style={{ padding: '8px 14px', borderRadius: 6, border: 'none', background: 'var(--accent)', color: '#fff', cursor: 'pointer', opacity: isSubmitting ? 0.6 : 1 }}>
                        {isSubmitting ? 'Saving…' : isEdit ? 'Save' : 'Create'}
                    </button>
                </div>
            </form>
        </div>
    );
}
