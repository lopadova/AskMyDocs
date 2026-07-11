import { useEffect, useRef } from 'react';
import type { ConnectionVM } from './connection-vm';

/*
 * "Sync failed" detail modal (design handoff). Opened from the Issue button on an
 * errored connection; shows the full error message + the last-attempt time and
 * offers Dismiss / Retry sync. Retry re-queues the account's sync then closes.
 *
 * R15 — a real dialog: `role="dialog" aria-modal`, labelled by its title, the
 * close button is focused on open, Escape + backdrop click dismiss it.
 * R14 — the full (untruncated) error message is shown so the operator can act.
 */

export interface SyncErrorModalProps {
    vm: ConnectionVM;
    onClose: () => void;
    onRetry: (id: number) => void;
}

export function SyncErrorModal({ vm, onClose, onRetry }: SyncErrorModalProps) {
    const closeRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        closeRef.current?.focus();
    }, []);

    return (
        <div
            data-testid="connector-sync-error-backdrop"
            onClick={onClose}
            onKeyDown={(e) => {
                if (e.key === 'Escape') onClose();
            }}
            style={{
                position: 'fixed',
                inset: 0,
                background: 'rgba(4,5,7,.66)',
                backdropFilter: 'blur(3px)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                padding: 24,
                zIndex: 60,
                animation: 'amd-cn-fade .15s ease both',
            }}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="connector-sync-error-title"
                data-testid="connector-sync-error-modal"
                onClick={(e) => e.stopPropagation()}
                style={{
                    width: 520,
                    maxWidth: '100%',
                    background: 'var(--panel-solid)',
                    border: '1px solid var(--panel-border-strong)',
                    borderRadius: 16,
                    boxShadow: 'var(--shadow-lg)',
                    animation: 'amd-cn-modal .2s ease both',
                    overflow: 'hidden',
                }}
            >
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'flex-start',
                        gap: 13,
                        padding: '20px 20px 16px',
                        borderBottom: '1px solid var(--hairline)',
                    }}
                >
                    <div
                        aria-hidden="true"
                        style={{
                            flex: 'none',
                            width: 38,
                            height: 38,
                            borderRadius: 10,
                            background: 'rgba(248,113,113,.14)',
                            border: '1px solid rgba(248,113,113,.3)',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                        }}
                    >
                        <svg
                            width="19"
                            height="19"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="#f87171"
                            strokeWidth="2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        >
                            <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                            <path d="M12 9v4" />
                            <path d="M12 17h.01" />
                        </svg>
                    </div>
                    <div style={{ flex: 1, minWidth: 0 }}>
                        <div
                            id="connector-sync-error-title"
                            style={{ fontSize: 15.5, fontWeight: 600, color: 'var(--fg-0)' }}
                        >
                            Sync failed
                        </div>
                        <div style={{ fontSize: 12.5, color: 'var(--fg-2)', marginTop: 2 }}>
                            {vm.sourceName} ·{' '}
                            <span style={{ fontFamily: 'var(--font-mono)' }}>{vm.account}</span>
                        </div>
                    </div>
                    <button
                        ref={closeRef}
                        type="button"
                        data-testid="connector-sync-error-close"
                        className="amd-cn-menu-btn focus-ring"
                        aria-label="Close"
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
                        <svg
                            width="17"
                            height="17"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            aria-hidden="true"
                        >
                            <path d="M18 6 6 18" />
                            <path d="M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div style={{ padding: '18px 20px' }}>
                    <div
                        style={{
                            fontSize: 11.5,
                            fontWeight: 600,
                            textTransform: 'uppercase',
                            letterSpacing: '.05em',
                            color: 'var(--fg-3)',
                            marginBottom: 8,
                        }}
                    >
                        Error detail
                    </div>
                    <div
                        data-testid="connector-sync-error-detail"
                        style={{
                            fontFamily: 'var(--font-mono)',
                            fontSize: 12.5,
                            lineHeight: 1.65,
                            color: '#e5b3b3',
                            background: 'var(--bg-0)',
                            border: '1px solid var(--hairline)',
                            borderRadius: 10,
                            padding: 14,
                            whiteSpace: 'pre-wrap',
                            wordBreak: 'break-word',
                        }}
                    >
                        {vm.errorMessage ?? 'Connector reported an error.'}
                    </div>
                    <div
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: 8,
                            marginTop: 14,
                            fontSize: 12.5,
                            color: 'var(--fg-3)',
                        }}
                    >
                        <svg
                            width="14"
                            height="14"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="1.9"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            aria-hidden="true"
                        >
                            <circle cx="12" cy="12" r="9" />
                            <path d="M12 8v4l2.5 1.5" />
                        </svg>
                        Last attempt {vm.lastSync ?? 'recently'}
                    </div>
                </div>

                <div
                    style={{
                        display: 'flex',
                        justifyContent: 'flex-end',
                        gap: 10,
                        padding: '14px 20px 20px',
                    }}
                >
                    <button
                        type="button"
                        data-testid="connector-sync-error-dismiss"
                        className="amd-cn-btn focus-ring"
                        onClick={onClose}
                        style={{
                            border: '1px solid var(--panel-border-strong)',
                            background: 'transparent',
                            color: 'var(--fg-1)',
                            font: 'inherit',
                            fontSize: 13,
                            fontWeight: 600,
                            padding: '9px 16px',
                            borderRadius: 9,
                            cursor: 'pointer',
                        }}
                    >
                        Dismiss
                    </button>
                    <button
                        type="button"
                        data-testid="connector-sync-error-retry"
                        className="focus-ring"
                        onClick={() => {
                            onRetry(vm.id);
                            onClose();
                        }}
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: 7,
                            border: 'none',
                            background: 'var(--grad-accent)',
                            color: '#fff',
                            font: 'inherit',
                            fontSize: 13,
                            fontWeight: 600,
                            padding: '9px 16px',
                            borderRadius: 9,
                            cursor: 'pointer',
                            boxShadow: '0 2px 10px rgba(139,92,246,.4)',
                        }}
                    >
                        <svg
                            width="15"
                            height="15"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            aria-hidden="true"
                        >
                            <path d="M21 12a9 9 0 1 1-3-6.7" />
                            <path d="M21 3v5h-5" />
                        </svg>
                        Retry sync
                    </button>
                </div>
            </div>
        </div>
    );
}
