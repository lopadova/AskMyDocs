import { useUiTranslation } from "../../lib/ui-locale";
import type { CSSProperties } from 'react';
import * as Dialog from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { Button } from '../../components/Button';
import type { AdminApiError } from '../admin/shared/errors';
import { modalBackdropStyle, modalPanelStyle } from '../admin/api-connectors/styles';

export interface McpConnectionErrorDialogProps {
    error: AdminApiError;
    onClose: () => void;
}

export function McpConnectionErrorDialog({ error, onClose }: McpConnectionErrorDialogProps) {
    const { t: translateInterface } = useUiTranslation();
    const backend = error.details.backend_diagnostic;

    return (
        <Dialog.Root open onOpenChange={(open) => { if (!open) onClose(); }}><Dialog.Portal>
            <Dialog.Overlay data-testid="mcp-connection-error-backdrop" style={{ ...modalBackdropStyle(), zIndex: 120 }} />
            <Dialog.Content
                role="dialog"
                aria-modal="true"
                aria-labelledby="mcp-connection-error-title"
                data-testid="mcp-connection-error-dialog"
                style={{ ...modalPanelStyle(680), position: 'fixed', top: '50%', left: '50%', transform: 'translate(-50%, -50%)', zIndex: 121, minWidth: 0, width: 'calc(100vw - 32px)', padding: 0, gap: 0, overflow: 'hidden' }}
                onEscapeKeyDown={(event) => event.preventDefault()} onInteractOutside={(event) => event.preventDefault()}
            >
                <div style={headerStyle}>
                    <div style={{ flex: 1, minWidth: 0 }}>
                        <Dialog.Title asChild><h2 id="mcp-connection-error-title" style={titleStyle}>{translateInterface("Connection error details")}</h2></Dialog.Title>
                        <Dialog.Description asChild><p style={subtitleStyle}>
                            {translateInterface("Backend diagnostics for this exact connection attempt.")}
                        </p></Dialog.Description>
                    </div>
                    <Button
                        variant="quiet"
                        size="sm"
                        iconOnly
                        aria-label={translateInterface("Close error details")}
                        title={translateInterface("Close")}
                        onClick={onClose}
                    >
                        <X size={16} aria-hidden />
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
                    <Button variant="secondary" onClick={onClose}>{translateInterface("Close")}</Button>
                </div>
            </Dialog.Content>
        </Dialog.Portal></Dialog.Root>
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
