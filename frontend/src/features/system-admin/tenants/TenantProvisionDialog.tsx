import { useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { toAdminError } from '../../admin/shared/errors';
import {
    tenantControlApi,
    type ProvisionTenantResult,
    type TenantAvailability,
} from './tenant-control.api';

interface TenantProvisionDialogProps {
    onClose: () => void;
    onProvisioned: (result: ProvisionTenantResult) => void;
}

function slugify(value: string): string {
    return value
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[^a-z0-9_-]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 50);
}

function generatePassword(): string {
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    const bytes = new Uint8Array(18);
    if (!globalThis.crypto?.getRandomValues) {
        throw new Error('Secure password generation is unavailable in this browser.');
    }
    globalThis.crypto.getRandomValues(bytes);
    return Array.from(bytes, (byte) => alphabet[byte % alphabet.length]).join('');
}

function initialPassword(): { value: string; error: string | null } {
    try {
        return { value: generatePassword(), error: null };
    } catch (error) {
        return {
            value: '',
            error: error instanceof Error ? error.message : 'Secure password generation is unavailable.',
        };
    }
}

export function TenantProvisionDialog({
    onClose,
    onProvisioned,
}: TenantProvisionDialogProps): ReactNode {
    const dialogRef = useRef<HTMLFormElement>(null);
    const previousFocusRef = useRef<HTMLElement | null>(null);
    const [tenantName, setTenantName] = useState('');
    const [tenantSlug, setTenantSlug] = useState('');
    const [slugTouched, setSlugTouched] = useState(false);
    const [email, setEmail] = useState('');
    const [userName, setUserName] = useState('');
    const [nameTouched, setNameTouched] = useState(false);
    const [initialPasswordState] = useState(initialPassword);
    const [password, setPassword] = useState(initialPasswordState.value);
    const [passwordGenerationError, setPasswordGenerationError] = useState<string | null>(initialPasswordState.error);
    const [showPassword, setShowPassword] = useState(false);
    const [role, setRole] = useState<'super-admin' | 'admin' | 'editor' | 'viewer'>('admin');
    const [debouncedCheck, setDebouncedCheck] = useState({ tenantName: '', tenantSlug: '', email: '', role: 'admin' as typeof role });
    const [result, setResult] = useState<ProvisionTenantResult | null>(null);
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') onClose();
            if (event.key !== 'Tab' || dialogRef.current === null) return;

            const focusable = Array.from(dialogRef.current.querySelectorAll<HTMLElement>(
                'button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
            ));
            if (focusable.length === 0) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };
        previousFocusRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        document.addEventListener('keydown', onKey);
        window.setTimeout(() => dialogRef.current?.querySelector<HTMLElement>('input, button')?.focus(), 0);
        return () => {
            document.removeEventListener('keydown', onKey);
            previousFocusRef.current?.focus();
        };
    }, [onClose]);

    useEffect(() => {
        const handle = window.setTimeout(() => {
            setDebouncedCheck({
                tenantName: tenantName.trim(),
                tenantSlug: tenantSlug.trim(),
                email: email.trim().toLowerCase(),
                role,
            });
        }, 350);
        return () => window.clearTimeout(handle);
    }, [tenantName, tenantSlug, email, role]);

    const checkReady = useMemo(
        () =>
            debouncedCheck.tenantName.length > 0 &&
            /^[a-z0-9_-]{1,50}$/.test(debouncedCheck.tenantSlug) &&
            /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(debouncedCheck.email),
        [debouncedCheck],
    );

    const availabilityQuery = useQuery({
        queryKey: ['tenant-provision-availability', debouncedCheck],
        queryFn: () =>
            tenantControlApi.availability({
                tenant_name: debouncedCheck.tenantName,
                tenant_slug: debouncedCheck.tenantSlug,
                user_email: debouncedCheck.email,
                role: debouncedCheck.role,
            }),
        enabled: checkReady && result === null,
        retry: false,
    });

    const availability: TenantAvailability | undefined = availabilityQuery.data;
    const isExisting = availability?.user.status === 'existing';
    const isNew = availability?.user.status === 'new';

    const mutation = useMutation({
        mutationFn: () =>
            tenantControlApi.provision({
                tenant_name: tenantName.trim(),
                tenant_slug: tenantSlug.trim(),
                user_email: email.trim().toLowerCase(),
                user_name: isNew ? userName.trim() : undefined,
                password: isNew ? password : undefined,
                role,
                attach_existing: isExisting,
            }),
        onSuccess: (created) => {
            setResult(created);
            onProvisioned(created);
        },
    });

    const error = mutation.isError ? toAdminError(mutation.error) : null;
    const submitReady =
        availability?.can_provision === true &&
        !availabilityQuery.isFetching &&
        !mutation.isPending &&
        (isExisting || (isNew && userName.trim() !== '' && password.length >= 8));

    const setCompany = (value: string) => {
        setTenantName(value);
        if (!slugTouched) setTenantSlug(slugify(value));
    };

    const setUserEmail = (value: string) => {
        setEmail(value);
        if (!nameTouched) {
            const local = value.split('@')[0] ?? '';
            setUserName(
                local
                    .split(/[._-]+/)
                    .filter(Boolean)
                    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
                    .join(' '),
            );
        }
    };

    const copyCredentials = async () => {
        if (result === null) return;
        const lines = [
            `Tenant: ${result.tenant.name} (${result.tenant.slug})`,
            `Login: ${result.user.email}`,
            ...(result.attached_existing ? [] : [`Temporary password: ${password}`]),
            `Role: ${role}`,
        ];
        await navigator.clipboard.writeText(lines.join('\n'));
        setCopied(true);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (submitReady) mutation.mutate();
    };

    return (
        <div
            data-testid="tenant-control-provision-backdrop"
            onClick={(event) => {
                if (event.target === event.currentTarget) onClose();
            }}
            style={backdropStyle}
        >
            <form
                ref={dialogRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby="tenant-control-provision-title"
                data-testid="tenant-control-provision-dialog"
                data-state={mutation.isPending ? 'loading' : result ? 'ready' : error ? 'error' : 'idle'}
                aria-busy={mutation.isPending || availabilityQuery.isFetching}
                onSubmit={submit}
                style={dialogStyle}
            >
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
                    <div>
                        <h2 id="tenant-control-provision-title" style={{ margin: 0, fontSize: 17, color: 'var(--fg-0)' }}>
                            {result ? 'Tenant ready' : 'Provision tenant'}
                        </h2>
                        <p style={{ margin: '5px 0 0', color: 'var(--fg-3)', fontSize: 11.5, lineHeight: 1.45 }}>
                            {result
                                ? 'The tenant, initial project and user access were committed together.'
                                : 'One check, one submit: create the company and connect a new or existing user.'}
                        </p>
                    </div>
                    <span style={{ flex: 1 }} />
                    <button type="button" data-testid="tenant-control-provision-close" onClick={onClose} style={quietButton}>
                        Close
                    </button>
                </div>

                {result ? (
                    <div data-testid="tenant-control-provision-success" style={{ display: 'grid', gap: 12 }}>
                        <div style={successPanel}>
                            <strong style={{ color: 'var(--fg-0)', fontSize: 14 }}>{result.tenant.name}</strong>
                            <span style={{ color: 'var(--fg-3)', fontFamily: 'var(--font-mono)', fontSize: 11 }}>
                                {result.tenant.slug}
                            </span>
                            <div style={{ marginTop: 10, color: 'var(--fg-1)', fontSize: 12 }}>
                                {result.user.name} · {result.user.email}
                            </div>
                            <div style={{ marginTop: 4, color: 'var(--fg-2)', fontSize: 11.5 }}>
                                {result.attached_existing
                                    ? 'Existing account associated; its password was not changed.'
                                    : 'New account created. Copy the temporary credentials before closing.'}
                            </div>
                            {!result.attached_existing && (
                                <code
                                    data-testid="tenant-control-provision-created-password"
                                    style={{
                                        display: 'block',
                                        marginTop: 10,
                                        padding: '8px 10px',
                                        borderRadius: 6,
                                        background: 'var(--bg-3)',
                                        color: 'var(--fg-0)',
                                    }}
                                >
                                    {password}
                                </code>
                            )}
                        </div>
                        <button
                            type="button"
                            data-testid="tenant-control-provision-copy"
                            onClick={() => void copyCredentials()}
                            style={primaryButton}
                        >
                            {copied ? 'Copied' : 'Copy account package'}
                        </button>
                    </div>
                ) : (
                    <>
                        <section aria-label="Tenant details" style={sectionStyle}>
                            <div style={sectionHeaderStyle}>1 · Company</div>
                            <label htmlFor="tenant-control-company-name" style={labelStyle}>
                                <span>Company name</span>
                                <input
                                    id="tenant-control-company-name"
                                    data-testid="tenant-control-provision-tenant-name"
                                    required
                                    value={tenantName}
                                    maxLength={200}
                                    placeholder="Acme S.r.l."
                                    onChange={(event) => setCompany(event.target.value)}
                                    style={inputStyle}
                                />
                                {error?.fieldErrors.tenant_name && <FieldError field="tenant-name" text={error.fieldErrors.tenant_name} />}
                            </label>
                            <label htmlFor="tenant-control-company-slug" style={labelStyle}>
                                <span>Tenant slug</span>
                                <input
                                    id="tenant-control-company-slug"
                                    data-testid="tenant-control-provision-tenant-slug"
                                    required
                                    value={tenantSlug}
                                    maxLength={50}
                                    pattern="^[a-z0-9_-]{1,50}$"
                                    placeholder="acme"
                                    onChange={(event) => {
                                        setSlugTouched(true);
                                        setTenantSlug(event.target.value.toLowerCase());
                                    }}
                                    style={inputStyle}
                                />
                                {error?.fieldErrors.tenant_slug && <FieldError field="tenant-slug" text={error.fieldErrors.tenant_slug} />}
                            </label>
                        </section>

                        <section aria-label="User details" style={sectionStyle}>
                            <div style={sectionHeaderStyle}>2 · User</div>
                            <label htmlFor="tenant-control-user-email" style={labelStyle}>
                                <span>Email</span>
                                <input
                                    id="tenant-control-user-email"
                                    data-testid="tenant-control-provision-user-email"
                                    required
                                    type="email"
                                    value={email}
                                    maxLength={255}
                                    placeholder="admin@acme.it"
                                    onChange={(event) => setUserEmail(event.target.value)}
                                    style={inputStyle}
                                />
                                {error?.fieldErrors.user_email && <FieldError field="user-email" text={error.fieldErrors.user_email} />}
                            </label>

                            <AvailabilityState
                                loading={availabilityQuery.isFetching}
                                error={availabilityQuery.isError ? toAdminError(availabilityQuery.error).message : null}
                                availability={availability}
                            />

                            {isNew && (
                                <>
                                    <label htmlFor="tenant-control-user-name" style={labelStyle}>
                                        <span>User name</span>
                                        <input
                                            id="tenant-control-user-name"
                                            data-testid="tenant-control-provision-user-name"
                                            required
                                            value={userName}
                                            maxLength={255}
                                            onChange={(event) => {
                                                setNameTouched(true);
                                                setUserName(event.target.value);
                                            }}
                                            style={inputStyle}
                                        />
                                        {error?.fieldErrors.user_name && <FieldError field="user-name" text={error.fieldErrors.user_name} />}
                                    </label>
                                    <label htmlFor="tenant-control-user-password" style={labelStyle}>
                                        <span>Temporary password</span>
                                        <div style={{ display: 'flex', gap: 6 }}>
                                            <input
                                                id="tenant-control-user-password"
                                                data-testid="tenant-control-provision-password"
                                                required
                                                type={showPassword ? 'text' : 'password'}
                                                value={password}
                                                minLength={8}
                                                onChange={(event) => setPassword(event.target.value)}
                                                style={{ ...inputStyle, flex: 1 }}
                                            />
                                            <button
                                                type="button"
                                                data-testid="tenant-control-provision-password-toggle"
                                                onClick={() => setShowPassword((value) => !value)}
                                                style={quietButton}
                                            >
                                                {showPassword ? 'Hide' : 'Show'}
                                            </button>
                                            <button
                                                type="button"
                                                data-testid="tenant-control-provision-password-generate"
                                                onClick={() => {
                                                    try {
                                                        setPassword(generatePassword());
                                                        setPasswordGenerationError(null);
                                                    } catch (generationError) {
                                                        setPassword('');
                                                        setPasswordGenerationError(
                                                            generationError instanceof Error
                                                                ? generationError.message
                                                                : 'Secure password generation is unavailable.',
                                                        );
                                                    }
                                                }}
                                                style={quietButton}
                                            >
                                                Regenerate
                                            </button>
                                        </div>
                                        {passwordGenerationError && <FieldError field="password-generation" text={passwordGenerationError} />}
                                        {error?.fieldErrors.password && <FieldError field="password" text={error.fieldErrors.password} />}
                                    </label>
                                </>
                            )}

                            {(isNew || isExisting) && (
                                <label htmlFor="tenant-control-user-role" style={labelStyle}>
                                    <span>Initial role</span>
                                    <select
                                        id="tenant-control-user-role"
                                        data-testid="tenant-control-provision-role"
                                        value={role}
                                        onChange={(event) => setRole(event.target.value as typeof role)}
                                        style={inputStyle}
                                    >
                                        <option value="super-admin">Super admin · maximum tenant privilege (membership still required)</option>
                                        <option value="admin">Admin · tenant management + all projects</option>
                                        <option value="editor">Editor · content editing</option>
                                        <option value="viewer">Viewer · read access</option>
                                    </select>
                                    <span style={hintStyle}>
                                        This application role is shared across every tenant associated with the account.
                                        Existing accounts are never promoted by this workflow.
                                    </span>
                                    {error?.fieldErrors.role && <FieldError field="role" text={error.fieldErrors.role} />}
                                </label>
                            )}
                        </section>

                        {error?.message && (
                            <p data-testid="tenant-control-provision-error" role="alert" style={{ margin: 0, color: 'var(--err)', fontSize: 12 }}>
                                {error.message}
                            </p>
                        )}

                        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
                            <button
                                type="button"
                                data-testid="tenant-control-provision-cancel"
                                onClick={onClose}
                                style={quietButton}
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                data-testid="tenant-control-provision-submit"
                                disabled={!submitReady}
                                style={{ ...primaryButton, opacity: submitReady ? 1 : 0.5 }}
                            >
                                {mutation.isPending
                                    ? 'Provisioning…'
                                    : isExisting
                                      ? 'Create tenant & associate user'
                                      : 'Create tenant & user'}
                            </button>
                        </div>
                    </>
                )}
            </form>
        </div>
    );
}

function AvailabilityState({
    loading,
    error,
    availability,
}: {
    loading: boolean;
    error: string | null;
    availability?: TenantAvailability;
}): ReactNode {
    if (loading) {
        return <p data-testid="tenant-control-availability-loading" style={hintStyle}>Checking tenant and account…</p>;
    }
    if (error) {
        return <p data-testid="tenant-control-availability-error" role="alert" style={{ ...hintStyle, color: 'var(--err)' }}>{error}</p>;
    }
    if (!availability) return <p style={hintStyle}>Complete company, slug and email to run the duplicate check.</p>;
    if (!availability.tenant.available) {
        return <p data-testid="tenant-control-availability-tenant-taken" role="alert" style={{ ...hintStyle, color: 'var(--err)' }}>Tenant slug already in use.</p>;
    }
    if (availability.user.status === 'existing') {
        if (!availability.user.role_compatible) {
            return (
                <p data-testid="tenant-control-availability-role-mismatch" role="alert" style={{ ...hintStyle, color: 'var(--err)' }}>
                    Existing account role {availability.user.effective_role ?? 'none'} is lower than the requested role.
                    Change the account globally in the dedicated user workflow or request a lower role.
                </p>
            );
        }
        return (
            <p data-testid="tenant-control-availability-existing-user" style={{ ...hintStyle, color: '#6ee7b7' }}>
                Existing active account found: {availability.user.name} ({availability.user.roles.join(', ') || 'no role'}). It will be associated without changing its password.
            </p>
        );
    }
    if (availability.user.status === 'inactive' || availability.user.status === 'deleted') {
        return (
            <p data-testid="tenant-control-availability-blocked-user" role="alert" style={{ ...hintStyle, color: 'var(--err)' }}>
                This account is {availability.user.status}. Reactivate or restore it from Users before associating it.
            </p>
        );
    }
    return <p data-testid="tenant-control-availability-new-user" style={{ ...hintStyle, color: '#6ee7b7' }}>Email available. A new account will be created.</p>;
}

function FieldError({ field, text }: { field: string; text: string }): ReactNode {
    return <span data-testid={`${field}-error`} role="alert" style={{ color: 'var(--err)', fontSize: 10.5 }}>{text}</span>;
}

const backdropStyle: React.CSSProperties = {
    position: 'fixed',
    inset: 0,
    zIndex: 200,
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    padding: 20,
    background: 'rgba(0,0,0,.56)',
    backdropFilter: 'blur(5px)',
};

const dialogStyle: React.CSSProperties = {
    width: 'min(680px, 96vw)',
    maxHeight: '92vh',
    overflow: 'auto',
    display: 'grid',
    gap: 16,
    padding: 20,
    borderRadius: 14,
    border: '1px solid var(--panel-border-strong)',
    background: 'var(--panel-solid, #17171f)',
    boxShadow: 'var(--shadow-lg)',
};

const sectionStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'minmax(0, 1fr) minmax(0, 1fr)',
    gap: 12,
    padding: 14,
    borderRadius: 10,
    border: '1px solid var(--panel-border)',
    background: 'var(--bg-2)',
};

const sectionHeaderStyle: React.CSSProperties = {
    gridColumn: '1 / -1',
    color: 'var(--fg-3)',
    fontSize: 10.5,
    fontFamily: 'var(--font-mono)',
    textTransform: 'uppercase',
    letterSpacing: '.06em',
};

const labelStyle: React.CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    gap: 5,
    color: 'var(--fg-2)',
    fontSize: 11,
};

const inputStyle: React.CSSProperties = {
    minWidth: 0,
    padding: '7px 9px',
    borderRadius: 7,
    border: '1px solid var(--panel-border-strong)',
    background: 'var(--bg-3)',
    color: 'var(--fg-0)',
    fontSize: 12,
};

const hintStyle: React.CSSProperties = {
    gridColumn: '1 / -1',
    margin: 0,
    color: 'var(--fg-3)',
    fontSize: 11,
    lineHeight: 1.45,
};

const quietButton: React.CSSProperties = {
    padding: '6px 10px',
    borderRadius: 7,
    border: '1px solid var(--panel-border-strong)',
    background: 'transparent',
    color: 'var(--fg-1)',
    cursor: 'pointer',
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

const successPanel: React.CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    padding: 14,
    borderRadius: 10,
    border: '1px solid rgba(34,197,94,.35)',
    background: 'rgba(34,197,94,.08)',
};
