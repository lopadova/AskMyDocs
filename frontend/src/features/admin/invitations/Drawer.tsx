import { useEffect, type ReactNode } from 'react';
import { Icon } from '../../../components/Icons';

/*
 * Shared slide-over drawer chrome for the invitations admin forms (campaign
 * editor, send invitation). role="dialog" + aria-modal, Escape closes,
 * click-on-scrim closes. Field is the labelled input wrapper with an inline
 * error slot (R15: bound <label htmlFor>; R14: error visible next to the field).
 */

export interface DrawerProps {
    title: string;
    testid: string;
    onClose: () => void;
    children: ReactNode;
}

export function Drawer({ title, testid, onClose, children }: DrawerProps) {
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    return (
        <div
            data-testid={testid}
            role="dialog"
            aria-modal="true"
            aria-label={title}
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
            style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.45)', display: 'flex', justifyContent: 'flex-end', zIndex: 50 }}
        >
            <div
                style={{
                    width: 'min(460px, 100%)',
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
                    <h2 style={{ margin: 0, fontSize: 16, color: 'var(--fg-0)' }}>{title}</h2>
                    <span style={{ flex: 1 }} />
                    <button type="button" data-testid={`${testid}-close`} aria-label="Close" onClick={onClose} style={drawerIconBtn}>
                        <Icon.Close size={15} />
                    </button>
                </header>
                {children}
            </div>
        </div>
    );
}

export function Field({ id, label, error, children }: { id: string; label: string; error?: string; children: ReactNode }) {
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

export const drawerInput: React.CSSProperties = {
    padding: '6px 10px',
    borderRadius: 6,
    border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
    background: 'var(--bg-3, rgba(255,255,255,.04))',
    color: 'var(--fg-0)',
    fontSize: 13,
};

export const drawerPrimaryBtn: React.CSSProperties = {
    padding: '8px 14px',
    borderRadius: 6,
    border: '1px solid var(--accent, #6366f1)',
    background: 'var(--accent, #6366f1)',
    color: 'white',
    fontSize: 13,
    cursor: 'pointer',
};

const drawerIconBtn: React.CSSProperties = {
    display: 'inline-flex',
    padding: 6,
    borderRadius: 6,
    border: 'none',
    background: 'transparent',
    color: 'var(--fg-2)',
    cursor: 'pointer',
};
