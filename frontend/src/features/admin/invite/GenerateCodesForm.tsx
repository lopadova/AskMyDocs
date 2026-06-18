import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import type { GenerateCodesPayload, InviteCampaign, InviteCode } from './admin-invite.api';

export interface GenerateCodesFormProps {
    campaigns: InviteCampaign[];
    defaultCampaignId: number | null;
    onSubmit: (payload: GenerateCodesPayload) => void;
    onClose: () => void;
    submitError?: string | null;
    isSubmitting?: boolean;
    /** Codes minted by the last successful submit — shown for copy. */
    generated?: InviteCode[] | null;
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
 * Bulk code-generation dialog. After a successful mint it stays open and lists
 * the freshly minted codes so the operator can copy them (they are never
 * re-fetchable in full after this — the list view shows them too, but this is
 * the "just generated" affordance).
 */
export function GenerateCodesForm({ campaigns, defaultCampaignId, onSubmit, onClose, submitError, isSubmitting, generated }: GenerateCodesFormProps): ReactNode {
    const [campaignId, setCampaignId] = useState<string>(defaultCampaignId != null ? String(defaultCampaignId) : '');
    const [count, setCount] = useState('10');
    const [maxUses, setMaxUses] = useState('1');
    const [length, setLength] = useState('8');

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        onSubmit({
            campaign_id: campaignId === '' ? null : Number.parseInt(campaignId, 10),
            count: Math.max(1, Number.parseInt(count, 10) || 1),
            max_uses: Math.max(1, Number.parseInt(maxUses, 10) || 1),
            length: Math.max(4, Number.parseInt(length, 10) || 8),
        });
    };

    return (
        <div
            data-testid="admin-invite-codes-form-backdrop"
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
            style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.4)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 100 }}
        >
            <form
                role="dialog"
                aria-modal="true"
                aria-labelledby="admin-invite-codes-form-title"
                data-testid="admin-invite-codes-form"
                onSubmit={handleSubmit}
                style={{ width: 460, maxWidth: '92vw', background: 'var(--panel-solid)', border: '1px solid var(--panel-border)', borderRadius: 10, padding: 20, display: 'flex', flexDirection: 'column', gap: 12 }}
            >
                <h2 id="admin-invite-codes-form-title" style={{ margin: 0, fontSize: 16, color: 'var(--fg-0)' }}>
                    Generate codes
                </h2>

                <label htmlFor="admin-invite-codes-form-campaign" style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--fg-2)' }}>
                    <span>Campaign (optional — standalone if none)</span>
                    <select id="admin-invite-codes-form-campaign" data-testid="admin-invite-codes-form-campaign" value={campaignId} onChange={(e) => setCampaignId(e.target.value)} style={fieldStyle}>
                        <option value="">— standalone —</option>
                        {campaigns.map((c) => (
                            <option key={c.id} value={c.id}>{c.name} ({c.key})</option>
                        ))}
                    </select>
                </label>

                <div style={{ display: 'flex', gap: 12 }}>
                    <label htmlFor="admin-invite-codes-form-count" style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--fg-2)' }}>
                        <span>How many</span>
                        <input id="admin-invite-codes-form-count" data-testid="admin-invite-codes-form-count" type="number" min={1} max={1000} value={count} onChange={(e) => setCount(e.target.value)} style={fieldStyle} />
                    </label>
                    <label htmlFor="admin-invite-codes-form-max-uses" style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--fg-2)' }}>
                        <span>Uses each</span>
                        <input id="admin-invite-codes-form-max-uses" data-testid="admin-invite-codes-form-max-uses" type="number" min={1} value={maxUses} onChange={(e) => setMaxUses(e.target.value)} style={fieldStyle} />
                    </label>
                    <label htmlFor="admin-invite-codes-form-length" style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--fg-2)' }}>
                        <span>Length</span>
                        <input id="admin-invite-codes-form-length" data-testid="admin-invite-codes-form-length" type="number" min={4} max={32} value={length} onChange={(e) => setLength(e.target.value)} style={fieldStyle} />
                    </label>
                </div>

                {submitError && (
                    <p data-testid="admin-invite-codes-form-error" role="alert" style={{ color: 'var(--err)', fontSize: 12, margin: 0 }}>
                        {submitError}
                    </p>
                )}

                {generated && generated.length > 0 && (
                    <div data-testid="admin-invite-codes-form-result" data-state="ready" style={{ background: 'var(--bg-0)', border: '1px solid var(--panel-border)', borderRadius: 6, padding: 10, maxHeight: 180, overflow: 'auto' }}>
                        <p style={{ margin: '0 0 6px', fontSize: 12, color: 'var(--fg-2)' }}>{generated.length} codes minted:</p>
                        <ul style={{ margin: 0, padding: 0, listStyle: 'none', fontFamily: 'var(--font-mono)', fontSize: 13, color: 'var(--fg-0)' }}>
                            {generated.map((c) => (
                                <li key={c.id}>{c.code}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 4 }}>
                    <button type="button" data-testid="admin-invite-codes-form-close" onClick={onClose} style={{ padding: '8px 14px', borderRadius: 6, border: '1px solid var(--panel-border)', background: 'transparent', color: 'var(--fg-1)', cursor: 'pointer' }}>
                        {generated && generated.length > 0 ? 'Done' : 'Cancel'}
                    </button>
                    <button type="submit" data-testid="admin-invite-codes-form-submit" disabled={isSubmitting} style={{ padding: '8px 14px', borderRadius: 6, border: 'none', background: 'var(--accent)', color: '#fff', cursor: 'pointer', opacity: isSubmitting ? 0.6 : 1 }}>
                        {isSubmitting ? 'Generating…' : 'Generate'}
                    </button>
                </div>
            </form>
        </div>
    );
}
