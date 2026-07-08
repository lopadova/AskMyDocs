import { useEffect, useRef, useState, type ReactNode } from 'react';
import type { HttpMethod, ProbePayload, ProbeResult } from './api-connectors.api';
import { ResponseViewer } from './ResponseViewer';
import {
    buttonStyle,
    fieldCaptionStyle,
    fieldLabelStyle,
    inputStyle,
    modalBackdropStyle,
    modalPanelStyle,
} from './styles';

/**
 * Free-endpoint playground — a big modal to fire an ad-hoc, UNAUTHENTICATED live
 * call: paste a URL, pick GET/POST/PUT/PATCH/DELETE, optionally add headers /
 * query params / a raw JSON body (body only for methods that allow one), hit
 * "Send", and read the response + errors. It persists nothing — the "add it as a
 * tool" step comes later.
 *
 * Presentational (R11/R16): it owns the FORM state but delegates the call via
 * `onSend` and receives `result`/`error`/`isSending`, so it is unit-testable
 * without a query/router context. R14 — the ResponseViewer surfaces request vs
 * upstream failure distinctly. R15 — bound labels, role=dialog, Esc closes, the
 * URL input autofocuses.
 */

const HTTP_METHODS: HttpMethod[] = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

function methodAllowsBody(method: HttpMethod): boolean {
    return method === 'POST' || method === 'PUT' || method === 'PATCH';
}

/** Parse a "Key: Value" per-line textarea into a header/query map (blank + colon-less lines ignored). */
function parseKeyValueLines(text: string): Record<string, string> {
    const out: Record<string, string> = {};
    for (const line of text.split('\n')) {
        const trimmed = line.trim();
        if (trimmed === '') continue;
        const idx = trimmed.indexOf(':');
        if (idx === -1) continue;
        const key = trimmed.slice(0, idx).trim();
        if (key === '') continue;
        out[key] = trimmed.slice(idx + 1).trim();
    }
    return out;
}

export interface FreeEndpointModalProps {
    onSend: (payload: ProbePayload) => void;
    /** Latest probe outcome, or null before the first send. */
    result: ProbeResult | null;
    /** The probe REQUEST failed (transport / 422) — surfaced by the viewer. */
    error?: string | null;
    isSending?: boolean;
    onClose: () => void;
}

export function FreeEndpointModal({
    onSend,
    result,
    error,
    isSending,
    onClose,
}: FreeEndpointModalProps): ReactNode {
    const [method, setMethod] = useState<HttpMethod>('GET');
    const [url, setUrl] = useState('');
    const [headersText, setHeadersText] = useState('');
    const [queryText, setQueryText] = useState('');
    const [bodyText, setBodyText] = useState('');
    const [urlError, setUrlError] = useState<string | null>(null);
    const [bodyError, setBodyError] = useState<string | null>(null);

    const urlRef = useRef<HTMLInputElement>(null);
    useEffect(() => {
        urlRef.current?.focus();
    }, []);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const allowsBody = methodAllowsBody(method);

    function handleSend() {
        setUrlError(null);
        setBodyError(null);

        const trimmedUrl = url.trim();
        if (trimmedUrl === '') {
            setUrlError('URL is required.');
            urlRef.current?.focus();
            return;
        }

        let parsedBody: Record<string, unknown> | undefined;
        if (allowsBody && bodyText.trim() !== '') {
            try {
                const obj: unknown = JSON.parse(bodyText);
                if (obj === null || typeof obj !== 'object' || Array.isArray(obj)) {
                    throw new Error('Body must be a JSON object.');
                }
                parsedBody = obj as Record<string, unknown>;
            } catch (e) {
                setBodyError(e instanceof Error ? e.message : 'Invalid JSON.');
                return;
            }
        }

        const headers = parseKeyValueLines(headersText);
        const query = parseKeyValueLines(queryText);

        onSend({
            http_method: method,
            url: trimmedUrl,
            headers: Object.keys(headers).length > 0 ? headers : undefined,
            query: Object.keys(query).length > 0 ? query : undefined,
            body: parsedBody,
        });
    }

    const titleId = 'api-probe-title';
    const state: 'idle' | 'loading' | 'ready' | 'error' = isSending
        ? 'loading'
        : error
          ? 'error'
          : result
            ? 'ready'
            : 'idle';

    return (
        <div
            data-testid="api-probe-backdrop"
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
            style={modalBackdropStyle()}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-busy={isSending}
                data-testid="api-probe-panel"
                data-state={state}
                style={modalPanelStyle(820)}
            >
                <div>
                    <h2 id={titleId} style={{ margin: 0, fontSize: 15, color: 'var(--fg-0)' }}>
                        Probe a live endpoint
                    </h2>
                    <p style={{ margin: '2px 0 0', fontSize: 11.5, color: 'var(--fg-3)' }}>
                        Fire a free, unauthenticated call and read the response. Nothing is saved —
                        https only.
                    </p>
                </div>

                {/* Method + URL */}
                <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end' }}>
                    <label htmlFor="api-probe-method" style={{ ...fieldLabelStyle(), flex: '0 0 110px' }}>
                        <span style={fieldCaptionStyle()}>Method</span>
                        <select
                            id="api-probe-method"
                            data-testid="api-probe-method"
                            value={method}
                            onChange={(e) => setMethod(e.target.value as HttpMethod)}
                            style={inputStyle()}
                        >
                            {HTTP_METHODS.map((m) => (
                                <option key={m} value={m}>
                                    {m}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label htmlFor="api-probe-url" style={{ ...fieldLabelStyle(), flex: 1 }}>
                        <span style={fieldCaptionStyle()}>URL</span>
                        <input
                            id="api-probe-url"
                            ref={urlRef}
                            data-testid="api-probe-url"
                            type="text"
                            inputMode="url"
                            placeholder="https://api.example.com/v1/orders"
                            value={url}
                            onChange={(e) => setUrl(e.target.value)}
                            style={{ ...inputStyle(), fontFamily: 'var(--font-mono, monospace)' }}
                        />
                        {urlError && (
                            <span
                                data-testid="api-probe-url-error"
                                role="alert"
                                style={{ fontSize: 10.5, color: 'var(--err, #fca5a5)' }}
                            >
                                {urlError}
                            </span>
                        )}
                    </label>
                </div>

                {/* Headers + Query */}
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    <label htmlFor="api-probe-headers" style={{ ...fieldLabelStyle(), flex: '1 1 240px' }}>
                        <span style={fieldCaptionStyle()}>Headers (one Key: Value per line)</span>
                        <textarea
                            id="api-probe-headers"
                            data-testid="api-probe-headers"
                            rows={3}
                            placeholder={'Accept: application/json'}
                            value={headersText}
                            onChange={(e) => setHeadersText(e.target.value)}
                            style={{ ...inputStyle(), resize: 'vertical', fontFamily: 'var(--font-mono, monospace)' }}
                        />
                    </label>
                    <label htmlFor="api-probe-query" style={{ ...fieldLabelStyle(), flex: '1 1 240px' }}>
                        <span style={fieldCaptionStyle()}>Query params (one Key: Value per line)</span>
                        <textarea
                            id="api-probe-query"
                            data-testid="api-probe-query"
                            rows={3}
                            placeholder={'page: 1'}
                            value={queryText}
                            onChange={(e) => setQueryText(e.target.value)}
                            style={{ ...inputStyle(), resize: 'vertical', fontFamily: 'var(--font-mono, monospace)' }}
                        />
                    </label>
                </div>

                {/* Body (only for methods that allow one) */}
                {allowsBody && (
                    <label htmlFor="api-probe-body" style={fieldLabelStyle()}>
                        <span style={fieldCaptionStyle()}>Request body (JSON object)</span>
                        <textarea
                            id="api-probe-body"
                            data-testid="api-probe-body"
                            rows={4}
                            placeholder={'{\n  "sku": "A1"\n}'}
                            value={bodyText}
                            onChange={(e) => setBodyText(e.target.value)}
                            style={{ ...inputStyle(), resize: 'vertical', fontFamily: 'var(--font-mono, monospace)' }}
                        />
                        {bodyError && (
                            <span
                                data-testid="api-probe-body-error"
                                role="alert"
                                style={{ fontSize: 10.5, color: 'var(--err, #fca5a5)' }}
                            >
                                {bodyError}
                            </span>
                        )}
                    </label>
                )}

                <div>
                    <button
                        type="button"
                        data-testid="api-probe-send"
                        onClick={handleSend}
                        disabled={isSending}
                        style={buttonStyle('primary', !!isSending)}
                    >
                        {isSending ? 'Sending…' : 'Send'}
                    </button>
                </div>

                <ResponseViewer result={result} error={error ?? null} />

                <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 4 }}>
                    <button
                        type="button"
                        data-testid="api-probe-close"
                        onClick={onClose}
                        style={buttonStyle('secondary', false)}
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    );
}
