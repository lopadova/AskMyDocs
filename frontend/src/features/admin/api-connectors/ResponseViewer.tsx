import type { CSSProperties } from 'react';
import type { ProbeResult } from './api-connectors.api';
import { fieldCaptionStyle, fieldLabelStyle } from './styles';
import { prettyJson } from './pretty-json';

/**
 * Presentational viewer for a live-call outcome ({@see ProbeResult}) — the
 * "read the response + handle errors" half of the free-endpoint modal, extracted
 * so it is unit-testable and reusable.
 *
 * Three-layer surfacing (R14): (1) `error` = the ADMIN call to /probe failed
 * (transport / 422); (2) `result.error` = the UPSTREAM endpoint failed
 * (network / HTTP 4xx-5xx) — a valid outcome, rendered loudly with the status;
 * (3) success — status pill + timing + headers + a JSON-vs-text body branch.
 */
export interface ResponseViewerProps {
    result: ProbeResult | null;
    /** The probe REQUEST itself failed (transport / validation) — distinct from an upstream error. */
    error?: string | null;
}

export function ResponseViewer({ result, error }: ResponseViewerProps) {
    if (error) {
        return (
            <p data-testid="api-probe-response-error" role="alert" style={alertStyle()}>
                {error}
            </p>
        );
    }

    if (!result) {
        return null;
    }

    const headers = result.headers ?? {};
    const headerEntries = Object.entries(headers);

    return (
        <div
            data-testid="api-probe-response"
            data-ok={result.ok ? 'true' : 'false'}
            style={{
                border: '1px solid var(--hairline, rgba(255,255,255,.1))',
                borderRadius: 8,
                padding: 10,
                display: 'flex',
                flexDirection: 'column',
                gap: 8,
            }}
        >
            <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                <span data-testid="api-probe-response-status" role="status" style={pillStyle(result.ok)}>
                    {result.ok ? 'OK' : 'Failed'}
                    {result.status != null && ` — HTTP ${result.status}`}
                    {result.status_label && ` ${result.status_label}`}
                </span>
                {result.duration_ms != null && (
                    <span
                        data-testid="api-probe-response-duration"
                        style={{ fontSize: 11, color: 'var(--fg-3)' }}
                    >
                        {result.duration_ms} ms
                    </span>
                )}
            </div>

            {result.error && (
                <p data-testid="api-probe-response-upstream-error" role="alert" style={alertStyle()}>
                    {result.error}
                </p>
            )}

            {headerEntries.length > 0 && (
                <div style={fieldLabelStyle()}>
                    <span style={fieldCaptionStyle()}>Response headers</span>
                    <div
                        data-testid="api-probe-response-headers"
                        style={{
                            display: 'flex',
                            flexDirection: 'column',
                            gap: 2,
                            maxHeight: 120,
                            overflow: 'auto',
                            background: 'var(--bg-3, rgba(255,255,255,.04))',
                            borderRadius: 6,
                            padding: 8,
                        }}
                    >
                        {headerEntries.map(([name, value]) => (
                            <div key={name} style={{ display: 'flex', gap: 6, fontSize: 11 }}>
                                <span style={{ color: 'var(--fg-2)', fontFamily: 'var(--font-mono, monospace)' }}>
                                    {name}:
                                </span>
                                <span style={{ color: 'var(--fg-3)', wordBreak: 'break-all' }}>{value}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            <div style={fieldLabelStyle()}>
                <span style={fieldCaptionStyle()}>
                    Response body {result.is_json ? '(JSON)' : '(text)'}
                </span>
                <pre
                    data-testid="api-probe-response-body"
                    data-format={result.is_json ? 'json' : 'text'}
                    style={{
                        margin: 0,
                        maxHeight: 260,
                        overflow: 'auto',
                        fontSize: 11,
                        fontFamily: 'var(--font-mono, monospace)',
                        background: 'var(--bg-3, rgba(255,255,255,.04))',
                        borderRadius: 6,
                        padding: 8,
                        color: 'var(--fg-1)',
                        whiteSpace: 'pre-wrap',
                        wordBreak: 'break-word',
                    }}
                >
                    {result.is_json ? prettyJson(result.body) : String(result.body ?? '')}
                </pre>
            </div>
        </div>
    );
}

function pillStyle(ok: boolean): CSSProperties {
    return {
        fontSize: 11,
        fontWeight: 600,
        padding: '2px 8px',
        borderRadius: 999,
        background: ok ? 'rgba(16,185,129,0.16)' : 'rgba(239,68,68,0.16)',
        border: '1px solid ' + (ok ? 'rgba(16,185,129,0.45)' : 'rgba(239,68,68,0.45)'),
        color: ok ? '#34d399' : '#fca5a5',
    };
}

function alertStyle(): CSSProperties {
    return { margin: 0, fontSize: 11.5, color: 'var(--err, #fca5a5)' };
}
