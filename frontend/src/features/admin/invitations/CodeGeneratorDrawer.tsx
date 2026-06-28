import { useEffect, useState } from 'react';
import { Icon } from '../../../components/Icons';
import { toAdminError } from '../shared/errors';
import { useToast } from '../shared/Toast';
import { useGenerateCodes } from './use-invitations';
import type { Campaign, InviteCode } from './invitations.api';

/*
 * Slide-over drawer to mint a batch of invite codes (POST /codes). On success
 * it keeps the generated codes on screen with Copy-all + CSV export so the
 * operator can hand them out before closing. 422 validation errors surface
 * inline next to the offending field (toAdminError flattens Laravel errors).
 *
 * R15: role="dialog" + aria-modal, every field has a bound <label htmlFor>,
 * Escape closes. R11/R29: testids under admin-invitations-codes-generate-*.
 */

interface CodeGeneratorDrawerProps {
    campaigns: Campaign[];
    onClose: () => void;
}

function csvCell(value: string | number | null | undefined): string {
    const s = value === null || value === undefined ? '' : String(value);
    // Quote when the cell contains a comma, quote or newline (RFC-4180-ish).
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

function buildCsv(codes: InviteCode[]): string {
    const header = 'code,state,max_uses,expires_at';
    const lines = codes.map((c) =>
        [c.code, c.state, c.max_uses, c.expires_at ?? ''].map(csvCell).join(','),
    );
    return [header, ...lines].join('\n');
}

export function CodeGeneratorDrawer({ campaigns, onClose }: CodeGeneratorDrawerProps) {
    const toast = useToast();
    const generate = useGenerateCodes();

    const [campaignId, setCampaignId] = useState('');
    const [count, setCount] = useState('10');
    const [maxUses, setMaxUses] = useState('');
    const [length, setLength] = useState('');
    const [expiresAt, setExpiresAt] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [generated, setGenerated] = useState<InviteCode[] | null>(null);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        setFieldErrors({});
        setFormError(null);

        const countNum = Number(count);
        if (!Number.isInteger(countNum) || countNum < 1 || countNum > 1000) {
            setFieldErrors({ count: 'Enter a whole number between 1 and 1000.' });
            return;
        }

        try {
            const codes = await generate.mutateAsync({
                campaign_id: campaignId ? Number(campaignId) : null,
                count: countNum,
                max_uses: maxUses ? Number(maxUses) : null,
                length: length ? Number(length) : null,
                expires_at: expiresAt || null,
            });
            setGenerated(codes);
            toast.success(`Generated ${codes.length} code${codes.length === 1 ? '' : 's'}.`, 'toast-codes-generated');
        } catch (err) {
            const e2 = toAdminError(err);
            setFieldErrors(e2.fieldErrors);
            setFormError(e2.message);
            toast.error(e2.message, 'toast-codes-error');
        }
    }

    async function copyAll() {
        if (!generated) return;
        // Guard the Clipboard API: absent in non-secure contexts, rejects on
        // denied permission. Surface the failure (R14) and point the operator at
        // the CSV export rather than throwing an unhandled rejection.
        try {
            if (!navigator.clipboard?.writeText) throw new Error('Clipboard API unavailable');
            await navigator.clipboard.writeText(generated.map((c) => c.code).join('\n'));
            toast.success('Copied all codes to the clipboard.', 'toast-codes-copied');
        } catch {
            toast.error('Copy failed — use the CSV export instead.', 'toast-codes-copy-error');
        }
    }

    function downloadCsv() {
        if (!generated) return;
        const blob = new Blob([buildCsv(generated)], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'invite-codes.csv';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    return (
        <div
            data-testid="admin-invitations-codes-generate-drawer"
            role="dialog"
            aria-modal="true"
            aria-label="Generate invite codes"
            style={{
                position: 'fixed',
                inset: 0,
                background: 'rgba(0,0,0,.45)',
                display: 'flex',
                justifyContent: 'flex-end',
                zIndex: 50,
            }}
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
        >
            <div
                style={{
                    width: 'min(420px, 100%)',
                    height: '100%',
                    background: 'var(--bg-1, #14161c)',
                    borderLeft: '1px solid var(--panel-border, rgba(255,255,255,.12))',
                    padding: 20,
                    overflow: 'auto',
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 14,
                }}
            >
                <header style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <h2 style={{ margin: 0, fontSize: 16, color: 'var(--fg-0)' }}>Generate codes</h2>
                    <span style={{ flex: 1 }} />
                    <button
                        type="button"
                        data-testid="admin-invitations-codes-generate-close"
                        aria-label="Close"
                        onClick={onClose}
                        style={iconBtn}
                    >
                        <Icon.Close size={15} />
                    </button>
                </header>

                {generated === null ? (
                    // noValidate: the JS validation in handleSubmit is the single
                    // source of truth so out-of-range values surface as inline
                    // errors (consistent with the rest of the admin forms) instead
                    // of the browser's native constraint tooltip silently blocking
                    // submit. The min/max attrs stay for spinner-stepping UX only.
                    <form onSubmit={handleSubmit} noValidate style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                        <Field id="gen-campaign" label="Campaign (optional)" error={fieldErrors.campaign_id}>
                            <select
                                id="gen-campaign"
                                data-testid="admin-invitations-codes-generate-campaign"
                                value={campaignId}
                                onChange={(e) => setCampaignId(e.target.value)}
                                style={inputStyle}
                            >
                                <option value="">Standalone (no campaign)</option>
                                {campaigns.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field id="gen-count" label="Count (1–1000)" error={fieldErrors.count}>
                            <input
                                id="gen-count"
                                data-testid="admin-invitations-codes-generate-count"
                                type="number"
                                min={1}
                                max={1000}
                                value={count}
                                onChange={(e) => setCount(e.target.value)}
                                style={inputStyle}
                            />
                        </Field>

                        <Field id="gen-max-uses" label="Max uses per code (optional)" error={fieldErrors.max_uses}>
                            <input
                                id="gen-max-uses"
                                data-testid="admin-invitations-codes-generate-max-uses"
                                type="number"
                                min={1}
                                value={maxUses}
                                onChange={(e) => setMaxUses(e.target.value)}
                                placeholder="1"
                                style={inputStyle}
                            />
                        </Field>

                        <Field id="gen-length" label="Code length (optional)" error={fieldErrors.length}>
                            <input
                                id="gen-length"
                                data-testid="admin-invitations-codes-generate-length"
                                type="number"
                                min={4}
                                max={32}
                                value={length}
                                onChange={(e) => setLength(e.target.value)}
                                placeholder="8"
                                style={inputStyle}
                            />
                        </Field>

                        <Field id="gen-expires" label="Expires at (optional)" error={fieldErrors.expires_at}>
                            <input
                                id="gen-expires"
                                data-testid="admin-invitations-codes-generate-expires"
                                type="date"
                                value={expiresAt}
                                onChange={(e) => setExpiresAt(e.target.value)}
                                style={inputStyle}
                            />
                        </Field>

                        {formError && (
                            <p data-testid="admin-invitations-codes-generate-error" role="alert" style={{ color: 'var(--danger-fg, #f87171)', fontSize: 12.5, margin: 0 }}>
                                {formError}
                            </p>
                        )}

                        <button
                            type="submit"
                            data-testid="admin-invitations-codes-generate-submit"
                            disabled={generate.isPending}
                            style={{ ...primaryBtn, opacity: generate.isPending ? 0.6 : 1 }}
                        >
                            {generate.isPending ? 'Generating…' : 'Generate'}
                        </button>
                    </form>
                ) : (
                    <div data-testid="admin-invitations-codes-generate-result" style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                        <p style={{ margin: 0, fontSize: 13, color: 'var(--fg-1)' }}>
                            {generated.length} code{generated.length === 1 ? '' : 's'} generated.
                        </p>
                        <div style={{ display: 'flex', gap: 8 }}>
                            <button type="button" data-testid="admin-invitations-codes-generate-copy-all" onClick={copyAll} style={secondaryBtn}>
                                <Icon.Copy size={13} /> Copy all
                            </button>
                            <button type="button" data-testid="admin-invitations-codes-generate-csv" onClick={downloadCsv} style={secondaryBtn}>
                                <Icon.Download size={13} /> CSV
                            </button>
                        </div>
                        <ul
                            aria-label="Generated codes"
                            style={{
                                listStyle: 'none',
                                margin: 0,
                                padding: 10,
                                border: '1px solid var(--panel-border, rgba(255,255,255,.12))',
                                borderRadius: 8,
                                maxHeight: 320,
                                overflow: 'auto',
                                fontFamily: 'var(--font-mono, monospace)',
                                fontSize: 12.5,
                                color: 'var(--fg-0)',
                            }}
                        >
                            {generated.map((c) => (
                                <li key={c.id} data-testid={`admin-invitations-codes-generate-code-${c.id}`} style={{ padding: '2px 0' }}>
                                    {c.code}
                                </li>
                            ))}
                        </ul>
                        <button type="button" data-testid="admin-invitations-codes-generate-done" onClick={onClose} style={primaryBtn}>
                            Done
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

function Field({ id, label, error, children }: { id: string; label: string; error?: string; children: React.ReactNode }) {
    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
            <label htmlFor={id} style={{ fontSize: 12, color: 'var(--fg-2)' }}>
                {label}
            </label>
            {children}
            {error && (
                <span data-testid={`${id}-error`} role="alert" style={{ fontSize: 11.5, color: 'var(--danger-fg, #f87171)' }}>
                    {error}
                </span>
            )}
        </div>
    );
}

const inputStyle: React.CSSProperties = {
    padding: '6px 10px',
    borderRadius: 6,
    border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
    background: 'var(--bg-3, rgba(255,255,255,.04))',
    color: 'var(--fg-0)',
    fontSize: 13,
};

const primaryBtn: React.CSSProperties = {
    padding: '8px 14px',
    borderRadius: 6,
    border: '1px solid var(--accent, #6366f1)',
    background: 'var(--accent, #6366f1)',
    color: 'white',
    fontSize: 13,
    cursor: 'pointer',
};

const secondaryBtn: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    gap: 6,
    padding: '6px 12px',
    borderRadius: 6,
    border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
    background: 'transparent',
    color: 'var(--fg-1)',
    fontSize: 12.5,
    cursor: 'pointer',
};

const iconBtn: React.CSSProperties = {
    display: 'inline-flex',
    padding: 6,
    borderRadius: 6,
    border: 'none',
    background: 'transparent',
    color: 'var(--fg-2)',
    cursor: 'pointer',
};
