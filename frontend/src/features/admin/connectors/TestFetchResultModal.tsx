import { useEffect, type ReactNode } from 'react';
import type { ConnectorInstallationDto, TestFetchResponse } from './connectors.api';

/**
 * Read-only modal showing the result of a "test fetch" probe — the sanitized
 * preview of the single newest email of a folder, downloaded WITHOUT ingesting.
 * Two states: a `message` preview, or a reachable-but-empty folder notice
 * (`message === null`). Failures never reach here — the caller toasts them and
 * does not open the modal.
 *
 * R11/R29: stable testids `connector-test-fetch-*`.
 * R15: role=dialog + aria-modal + labelled title; Esc + backdrop + a labelled
 *      close button all dismiss.
 */

export interface TestFetchResultModalProps {
    account: ConnectorInstallationDto;
    result: TestFetchResponse['data'];
    onClose: () => void;
}

export function TestFetchResultModal({ account, result, onClose }: TestFetchResultModalProps): ReactNode {
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const titleId = 'connector-test-fetch-title';
    const message = result.message;

    return (
        <div
            data-testid="connector-test-fetch-backdrop"
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
            style={{
                position: 'fixed',
                inset: 0,
                background: 'rgba(0,0,0,.4)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                zIndex: 100,
            }}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                data-testid="connector-test-fetch-result"
                data-result-state={message ? 'message' : 'empty'}
                style={{
                    background: 'var(--panel-solid, #1a1a22)',
                    border: '1px solid var(--panel-border-strong, rgba(255,255,255,.12))',
                    borderRadius: 12,
                    boxShadow: 'var(--shadow, 0 8px 24px rgba(0,0,0,.4))',
                    minWidth: 360,
                    maxWidth: 520,
                    padding: 16,
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 12,
                }}
            >
                <h2 id={titleId} style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>
                    Test fetch — {account.label}
                </h2>
                <div
                    data-testid="connector-test-fetch-folder"
                    style={{ fontSize: 11.5, color: 'var(--fg-3)', fontFamily: 'var(--font-mono)' }}
                >
                    Folder: {result.folder}
                </div>

                {message ? (
                    <dl
                        data-testid="connector-test-fetch-message"
                        style={{ margin: 0, display: 'flex', flexDirection: 'column', gap: 8 }}
                    >
                        <Field label="Subject" testid="connector-test-fetch-subject" value={message.subject} />
                        <Field
                            label="From"
                            testid="connector-test-fetch-from"
                            value={
                                message.from_name
                                    ? `${message.from_name} <${message.from_email}>`
                                    : message.from_email || '(unknown)'
                            }
                        />
                        <Field
                            label="Date"
                            testid="connector-test-fetch-date"
                            value={message.date ?? '(no date)'}
                        />
                        <Field
                            label="Recipients / attachments"
                            testid="connector-test-fetch-meta"
                            value={`${message.to_count} recipient${message.to_count === 1 ? '' : 's'} · ${message.attachments_count} attachment${message.attachments_count === 1 ? '' : 's'}`}
                        />
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                            <dt style={labelStyle()}>Preview</dt>
                            <dd
                                data-testid="connector-test-fetch-snippet"
                                style={{
                                    margin: 0,
                                    fontSize: 12,
                                    color: 'var(--fg-1)',
                                    whiteSpace: 'pre-wrap',
                                    wordBreak: 'break-word',
                                    background: 'var(--bg-3, rgba(255,255,255,.04))',
                                    border: '1px solid var(--panel-border, rgba(255,255,255,.12))',
                                    borderRadius: 8,
                                    padding: 8,
                                    maxHeight: 160,
                                    overflow: 'auto',
                                }}
                            >
                                {message.snippet || '(empty body)'}
                            </dd>
                        </div>
                    </dl>
                ) : (
                    <p
                        data-testid="connector-test-fetch-empty"
                        role="status"
                        style={{ margin: 0, fontSize: 12.5, color: 'var(--fg-2)' }}
                    >
                        Connected successfully, but the folder has no messages to preview.
                    </p>
                )}

                <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 4 }}>
                    <button
                        type="button"
                        data-testid="connector-test-fetch-close"
                        aria-label="Close test fetch result"
                        onClick={onClose}
                        style={{
                            padding: '5px 14px',
                            borderRadius: 6,
                            border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
                            background: 'transparent',
                            color: 'var(--fg-1)',
                            fontSize: 11.5,
                            cursor: 'pointer',
                        }}
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    );
}

function Field({ label, testid, value }: { label: string; testid: string; value: string }): ReactNode {
    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
            <dt style={labelStyle()}>{label}</dt>
            <dd data-testid={testid} style={{ margin: 0, fontSize: 12.5, color: 'var(--fg-0)' }}>
                {value}
            </dd>
        </div>
    );
}

function labelStyle(): React.CSSProperties {
    return { color: 'var(--fg-3)', fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '0.04em' };
}
