import type { ReactNode } from 'react';
import { SourceAvatar } from './SourceAvatar';

/*
 * Shared header for the connector Add-account modals (design handoff "Config
 * Modals"): the source avatar + a title + an optional subtitle + a close (×)
 * button. Used by the OAuth (AccountMetaForm) and credential (CredentialConnectorForm)
 * add flows so both open with the same polished chrome.
 *
 * R15 — the title carries the dialog's `aria-labelledby` id; the close button has
 * an accessible name and is keyboard-reachable.
 */

export interface ModalSourceHeaderProps {
    titleId: string;
    connectorKey: string;
    displayName: string;
    iconUrl?: string | null;
    title: string;
    subtitle?: string;
    onClose: () => void;
}

export function ModalSourceHeader({
    titleId,
    connectorKey,
    displayName,
    iconUrl,
    title,
    subtitle,
    onClose,
}: ModalSourceHeaderProps): ReactNode {
    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <SourceAvatar connectorKey={connectorKey} displayName={displayName} iconUrl={iconUrl} size={38} radius={10} />
            <div style={{ flex: 1, minWidth: 0 }}>
                <h2 id={titleId} style={{ margin: 0, fontSize: 16.5, fontWeight: 600, color: 'var(--fg-0)', lineHeight: 1.15 }}>
                    {title}
                </h2>
                {subtitle && (
                    <div style={{ fontSize: 12.5, color: 'var(--fg-3)', marginTop: 1 }}>{subtitle}</div>
                )}
            </div>
            <button
                type="button"
                aria-label="Close"
                className="amd-cn-menu-btn focus-ring"
                onClick={onClose}
                style={{
                    flex: 'none',
                    width: 32,
                    height: 32,
                    borderRadius: 8,
                    border: '1px solid transparent',
                    background: 'transparent',
                    color: 'var(--fg-2)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    cursor: 'pointer',
                }}
            >
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <path d="M18 6 6 18" />
                    <path d="M6 6l12 12" />
                </svg>
            </button>
        </div>
    );
}
