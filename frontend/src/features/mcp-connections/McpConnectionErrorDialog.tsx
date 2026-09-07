import { useEffect, useRef, type CSSProperties } from 'react';
import { Button } from '../../components/Button';
import type { AdminApiError } from '../admin/shared/errors';
import { modalBackdropStyle, modalPanelStyle } from '../admin/api-connectors/styles';

export interface McpConnectionErrorDialogProps {
    error: AdminApiError;
    onClose: () => void;
}

export function McpConnectionErrorDialog({ error, onClose }: McpConnectionErrorDialogProps) {
    const closeRef = useRef<HTMLButtonElement>(null);
    const backend = error.details.backend_diagnostic;

    useEffect(() => {
        closeRef.current?.focus();
    }, []);

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                onClose();
            }
        };
        document.addEventListener('keydown', onKeyDown, true);
        return () => document.removeEventListener('keydown', onKeyDown, true);
    }, [onClose]);

    return (
        <div
            data-testid="mcp-connection-error-backdrop"
            onClick={(event) => event.target === event.currentTarget && onClose()}
            style={{ ...modalBackdropStyle(), zIndex: 120 }}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="mcp-connection-error-title"
                data-testid="mcp-connection-error-dialog"
                style={{ ...modalPanelStyle(680), padding: 0, gap: 0, overflow: 'hidden' }}
            >
                <div style={headerStyle}>
                    <div style={{ flex: 1, minWidth: 0 }}>
                        <h2 id="mcp-connection-error-title" style={titleStyle}>Connection error details</h2>
                        <p style={subtitleStyle}>
                            Backend diagnostics for this exact connection attempt.
                        </p>
                    </div>
                    <Button
                        ref={closeRef}
                        variant="quiet"
                        size="sm"
                        iconOnly
                        aria-label="Close error details"
                        title="Close"
                        onClick={onClose}
                    >
                        ×
                    </Button>
                </div>

                <div style={bodyStyle}>
                    <DetailGrid error={error} backend={backend} />

                    <div>
                        <div style={sectionLabelStyle}>Backend detail</div>
                        <pre data-testid="mcp-connection-error-detail" style={logStyle}>
                            {diagnosticText(error)}
                        </pre>
                    </div>

                    <p style={hintStyle}>
                        Search the backend log for diagnostic ID <code>{diagnosticId(backend) ?? 'not provided'}</code> to find the correlated stack trace.
                    </p>
                </div>

                <div style={footerStyle}>
                    <Button variant="secondary" onClick={onClose}>Close</Button>
                </div>
            </div>
        </div>
    );
}

function DetailGrid({ error, backend }: { error: AdminApiError; backend: Record<string, unknown> | null }) {
    const request = [error.details.method, error.details.url].filter(Boolean).join(' ');
    const rows = [
        ['Diagnostic ID', diagnosticId(backend) ?? 'Not provided by backend'],
        ['HTTP status', error.status > 0 ? `${error.status}${error.details.status_text ? ` ${error.details.status_text}` : ''}` : 'No response'],
        ['Request', request || 'Unavailable'],
        ['Occurred at', stringValue(backend?.occurred_at) ?? 'Unavailable'],
    ];

    return (
        <dl style={gridStyle}>
            {rows.map(([label, value]) => (
                <div key={label} style={detailCardStyle}>
                    <dt style={detailLabelStyle}>{label}</dt>
                    <dd style={detailValueStyle}>{value}</dd>
                </div>
            ))}
        </dl>
    );
}

function diagnosticText(error: AdminApiError): string {
    const backend = error.details.backend_diagnostic;
    if (backend !== null) {
        return JSON.stringify(backend, null, 2);
    }

    return JSON.stringify({
        client_message: error.details.client_message,
        method: error.details.method,
        url: error.details.url,
        status: error.details.status,
        status_text: error.details.status_text,
        response_body: error.details.response_body,
    }, null, 2);
}

function diagnosticId(backend: Record<string, unknown> | null): string | null {
    return stringValue(backend?.id);
}

function stringValue(value: unknown): string | null {
    return typeof value === 'string' && value !== '' ? value : null;
}

const headerStyle: CSSProperties = { display: 'flex', alignItems: 'flex-start', gap: 12, padding: '18px 20px', borderBottom: '1px solid var(--hairline)' };
const titleStyle: CSSProperties = { margin: 0, color: 'var(--fg-0)', fontSize: 17 };
const subtitleStyle: CSSProperties = { margin: '4px 0 0', color: 'var(--fg-3)', fontSize: 12.5 };
const bodyStyle: CSSProperties = { display: 'grid', gap: 16, padding: 20, overflowY: 'auto' };
const gridStyle: CSSProperties = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(210px, 1fr))', gap: 8, margin: 0 };
const detailCardStyle: CSSProperties = { minWidth: 0, padding: 10, border: '1px solid var(--hairline)', borderRadius: 9, background: 'var(--bg-2)' };
const detailLabelStyle: CSSProperties = { marginBottom: 4, color: 'var(--fg-3)', fontSize: 10.5, fontWeight: 650, textTransform: 'uppercase', letterSpacing: '.045em' };
const detailValueStyle: CSSProperties = { margin: 0, color: 'var(--fg-1)', fontFamily: 'var(--font-mono)', fontSize: 11.5, lineHeight: 1.5, overflowWrap: 'anywhere' };
const sectionLabelStyle: CSSProperties = { marginBottom: 7, color: 'var(--fg-3)', fontSize: 10.5, fontWeight: 650, textTransform: 'uppercase', letterSpacing: '.045em' };
const logStyle: CSSProperties = { boxSizing: 'border-box', maxHeight: 330, margin: 0, overflow: 'auto', whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', padding: 14, border: '1px solid var(--hairline)', borderRadius: 10, background: 'var(--bg-0)', color: 'var(--fg-1)', fontFamily: 'var(--font-mono)', fontSize: 11.5, lineHeight: 1.6 };
const hintStyle: CSSProperties = { margin: 0, color: 'var(--fg-3)', fontSize: 11.5, lineHeight: 1.5 };
const footerStyle: CSSProperties = { display: 'flex', justifyContent: 'flex-end', padding: '13px 20px', borderTop: '1px solid var(--hairline)', background: 'color-mix(in srgb, var(--bg-2) 50%, transparent)' };
