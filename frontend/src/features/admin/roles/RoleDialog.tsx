import { useEffect, useMemo, useState } from 'react';
import { Icon } from '../../../components/Icons';
import type { AdminPermissionCatalogue, AdminRole } from '../admin.api';
import { toAdminError } from '../shared/errors';
import { useToast } from '../shared/Toast';
import { useCreateRole, useUpdateRole } from './roles.api';

export interface RoleDialogProps {
    mode: 'create' | 'edit';
    open: boolean;
    role?: AdminRole | null;
    catalogue: AdminPermissionCatalogue | undefined;
    onClose: () => void;
}

const PROTECTED_ROLE_NAMES = new Set(['super-admin', 'admin']);

/*
 * Role create/edit dialog with a two-axis permission matrix (domain rows
 * x permission-name cells). Each domain has a `toggle-all` affordance
 * plus a per-permission checkbox. System roles (`super-admin`, `admin`)
 * are editable for permissions only — the name field is disabled to
 * mirror the backend's 409 guard.
 */
export function RoleDialog({ mode, open, role, catalogue, onClose }: RoleDialogProps) {
    const [name, setName] = useState('');
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [nameError, setNameError] = useState<string | undefined>(undefined);
    const toast = useToast();

    const createM = useCreateRole();
    const updateM = useUpdateRole();

    useEffect(() => {
        if (!open) return;
        setName(role?.name ?? '');
        setSelected(new Set(role?.permissions ?? []));
        setNameError(undefined);
    }, [open, role?.id, mode]);

    const grouped = catalogue?.grouped ?? {};
    const domains = useMemo(() => Object.keys(grouped).sort(), [grouped]);

    if (!open) return null;

    const isProtectedName = role && PROTECTED_ROLE_NAMES.has(role.name);
    const submitting = createM.isPending || updateM.isPending;

    function togglePermission(permName: string) {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(permName)) {
                next.delete(permName);
            } else {
                next.add(permName);
            }
            return next;
        });
    }

    function toggleDomain(domain: string) {
        const permsInDomain = (grouped[domain] ?? []).map((p) => p.name);
        const everySelected = permsInDomain.every((p) => selected.has(p));
        setSelected((prev) => {
            const next = new Set(prev);
            if (everySelected) {
                permsInDomain.forEach((p) => next.delete(p));
            } else {
                permsInDomain.forEach((p) => next.add(p));
            }
            return next;
        });
    }

    async function handleSubmit() {
        setNameError(undefined);
        try {
            if (mode === 'create') {
                const created = await createM.mutateAsync({
                    name: name.trim(),
                    permissions: [...selected],
                });
                toast.success(`Role ${created.name} created`, 'toast-role-created');
                onClose();
                return;
            }
            if (!role) return;
            await updateM.mutateAsync({
                id: role.id,
                input: { permissions: [...selected] },
            });
            toast.success(`Role ${role.name} updated`, 'toast-role-updated');
            onClose();
        } catch (e) {
            const err = toAdminError(e);
            if (err.fieldErrors.name) {
                setNameError(err.fieldErrors.name);
            }
            toast.error(err.message, 'toast-role-error');
        }
    }

    const title = mode === 'create' ? 'New role' : `Edit role: ${role?.name ?? ''}`;

    return (
        <div
            data-testid="role-dialog"
            data-mode={mode}
            style={{
                position: 'fixed',
                inset: 0,
                zIndex: 50,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
            }}
        >
            <button
                type="button"
                aria-label="Close dialog"
                data-testid="role-dialog-backdrop"
                onClick={onClose}
                style={{
                    position: 'absolute',
                    inset: 0,
                    background: 'rgba(0,0,0,0.45)',
                    border: 'none',
                    cursor: 'pointer',
                    padding: 0,
                }}
            />
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="role-dialog-title"
                style={{
                    position: 'relative',
                    width: 'min(640px, 92vw)',
                    maxHeight: '90vh',
                    overflowY: 'auto',
                    padding: 22,
                    background: 'var(--bg-1)',
                    border: '1px solid var(--hairline)',
                    borderRadius: 12,
                    color: 'var(--fg-1)',
                    fontFamily: 'var(--font-sans)',
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 14,
                    boxShadow: '0 24px 60px rgba(0,0,0,0.4)',
                }}
            >
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <h2
                        id="role-dialog-title"
                        data-testid="role-dialog-title"
                        style={{ fontSize: 17, margin: 0, flex: 1, color: 'var(--fg-0)' }}
                    >
                        {title}
                    </h2>
                    <button
                        type="button"
                        className="focus-ring"
                        data-testid="role-dialog-close"
                        onClick={onClose}
                        aria-label="Close"
                        style={iconCloseStyle}
                    >
                        <Icon.Close size={14} />
                    </button>
                </div>

                <div>
                    <div style={labelStyle}>Name</div>
                    <input
                        data-testid="role-dialog-name"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        disabled={isProtectedName || submitting}
                        style={{
                            ...inputStyle,
                            opacity: isProtectedName ? 0.55 : 1,
                        }}
                    />
                    {isProtectedName ? (
                        <div
                            data-testid="role-dialog-name-protected"
                            style={{ marginTop: 4, fontSize: 12, color: 'var(--fg-3)' }}
                        >
                            System roles cannot be renamed. Permission edits still persist.
                        </div>
                    ) : null}
                    {nameError ? (
                        <div data-testid="role-dialog-name-error" style={errorStyle}>
                            {nameError}
                        </div>
                    ) : null}
                </div>

                <div>
                    <div style={labelStyle}>Permissions</div>
                    {!catalogue ? (
                        <div style={mutedStyle}>Loading permission catalogue…</div>
                    ) : domains.length === 0 ? (
                        <div style={mutedStyle}>No permissions defined.</div>
                    ) : (
                        <div
                            data-testid="role-dialog-matrix"
                            style={{ display: 'flex', flexDirection: 'column', gap: 10 }}
                        >
                            {domains.map((domain) => {
                                const perms = grouped[domain] ?? [];
                                const all = perms.every((p) => selected.has(p.name));
                                return (
                                    <div
                                        key={domain}
                                        data-testid={`role-perm-domain-${domain}`}
                                        style={{
                                            padding: 10,
                                            border: '1px solid var(--hairline)',
                                            borderRadius: 8,
                                            background: 'var(--bg-0)',
                                        }}
                                    >
                                        <div
                                            style={{
                                                display: 'flex',
                                                alignItems: 'center',
                                                gap: 8,
                                                marginBottom: 6,
                                            }}
                                        >
                                            <strong
                                                style={{
                                                    fontFamily: 'var(--font-mono)',
                                                    fontSize: 12,
                                                    color: 'var(--fg-1)',
                                                }}
                                            >
                                                {domain}.*
                                            </strong>
                                            <div style={{ flex: 1 }} />
                                            <button
                                                type="button"
                                                className="focus-ring"
                                                data-testid={`role-perm-${domain}-toggle-all`}
                                                data-active={all ? 'true' : 'false'}
                                                onClick={() => toggleDomain(domain)}
                                                style={{
                                                    padding: '4px 10px',
                                                    fontSize: 12,
                                                    border: '1px solid var(--hairline)',
                                                    background: all
                                                        ? 'var(--grad-accent-soft)'
                                                        : 'transparent',
                                                    color: 'var(--fg-1)',
                                                    borderRadius: 6,
                                                    cursor: 'pointer',
                                                }}
                                            >
                                                {all ? 'Unselect all' : 'Select all'}
                                            </button>
                                        </div>
                                        <div
                                            style={{
                                                display: 'flex',
                                                flexWrap: 'wrap',
                                                gap: 6,
                                            }}
                                        >
                                            {perms.map((p) => {
                                                const active = selected.has(p.name);
                                                return (
                                                    <label
                                                        key={p.id}
                                                        data-testid={`role-perm-${p.name}`}
                                                        data-active={active ? 'true' : 'false'}
                                                        style={{
                                                            display: 'inline-flex',
                                                            alignItems: 'center',
                                                            gap: 6,
                                                            padding: '4px 10px',
                                                            fontSize: 12,
                                                            cursor: 'pointer',
                                                            border: '1px solid ' + (active ? 'transparent' : 'var(--hairline)'),
                                                            background: active
                                                                ? 'var(--grad-accent-soft)'
                                                                : 'transparent',
                                                            color: active ? 'var(--fg-0)' : 'var(--fg-2)',
                                                            borderRadius: 999,
                                                        }}
                                                    >
                                                        {/*
                                                          Copilot #5 a11y fix: `display: none`
                                                          removes the input from the
                                                          accessibility tree, so screen
                                                          readers never perceive it as a
                                                          checkbox. Visually-hide instead
                                                          — the input stays focusable and
                                                          discoverable, while the label
                                                          chip provides the visual state.
                                                        */}
                                                        <input
                                                            type="checkbox"
                                                            checked={active}
                                                            onChange={() => togglePermission(p.name)}
                                                            style={{
                                                                position: 'absolute',
                                                                width: 1,
                                                                height: 1,
                                                                padding: 0,
                                                                margin: -1,
                                                                overflow: 'hidden',
                                                                clip: 'rect(0, 0, 0, 0)',
                                                                whiteSpace: 'nowrap',
                                                                border: 0,
                                                            }}
                                                        />
                                                        {p.name}
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>

                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
                    <button
                        type="button"
                        className="focus-ring"
                        data-testid="role-dialog-cancel"
                        onClick={onClose}
                        style={secondaryButtonStyle}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        className="focus-ring"
                        data-testid="role-dialog-save"
                        onClick={handleSubmit}
                        disabled={submitting}
                        style={{
                            ...primaryButtonStyle,
                            opacity: submitting ? 0.6 : 1,
                        }}
                    >
                        {submitting ? 'Saving…' : mode === 'create' ? 'Create role' : 'Save changes'}
                    </button>
                </div>
            </div>
        </div>
    );
}

const labelStyle = {
    fontSize: 11,
    color: 'var(--fg-3)',
    fontFamily: 'var(--font-mono)',
    textTransform: 'uppercase' as const,
    letterSpacing: '0.05em',
    marginBottom: 4,
};

const inputStyle = {
    width: '100%',
    padding: '7px 10px',
    fontSize: 13,
    color: 'var(--fg-0)',
    background: 'var(--bg-0)',
    border: '1px solid var(--hairline)',
    borderRadius: 7,
};

const errorStyle = {
    marginTop: 4,
    fontSize: 12,
    color: '#fca5a5',
};

const mutedStyle = {
    fontSize: 12,
    color: 'var(--fg-3)',
    padding: '12px 6px',
};

const iconCloseStyle = {
    width: 30,
    height: 30,
    padding: 0,
    borderRadius: 7,
    background: 'transparent',
    border: '1px solid var(--hairline)',
    color: 'var(--fg-2)',
    display: 'inline-flex',
    alignItems: 'center',
    justifyContent: 'center',
    cursor: 'pointer',
};

const primaryButtonStyle = {
    padding: '7px 14px',
    fontSize: 13,
    border: '1px solid transparent',
    background: 'var(--grad-accent)',
    color: '#fff',
    borderRadius: 7,
    cursor: 'pointer',
};

const secondaryButtonStyle = {
    padding: '7px 14px',
    fontSize: 13,
    border: '1px solid var(--hairline)',
    background: 'transparent',
    color: 'var(--fg-1)',
    borderRadius: 7,
    cursor: 'pointer',
};
