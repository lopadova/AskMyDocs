import { useEffect, useState } from 'react';
import { useApplicationLog } from './logs.api';

/*
 * Phase H1 — admin Log Viewer, Application log tab.
 *
 * File picker + level filter + "tail N" slider. Default file is
 * `laravel.log`; other valid shapes are `laravel-YYYY-MM-DD.log`
 * (Monolog daily rotation) — the backend whitelist rejects anything
 * else with HTTP 422.
 *
 * Live-tail toggle: when `?live=1` is in the URL the TanStack Query
 * refetchInterval flips to 5s. No SSE infra in H1; polling is plenty
 * for an admin peek.
 */

const COMMON_FILES = ['laravel.log'];

const LEVELS = ['', 'EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG'];

function readLiveFlag(): boolean {
    if (typeof window === 'undefined') return false;
    const params = new URLSearchParams(window.location.search);
    return params.get('live') === '1';
}

function syncLiveFlagToUrl(live: boolean): void {
    if (typeof window === 'undefined') return;
    const params = new URLSearchParams(window.location.search);
    if (live) {
        params.set('live', '1');
    } else {
        params.delete('live');
    }
    const qs = params.toString();
    const next = qs === '' ? window.location.pathname : `${window.location.pathname}?${qs}`;
    window.history.replaceState(null, '', next);
}

export function ApplicationLogTab() {
    const [file, setFile] = useState('laravel.log');
    const [customFile, setCustomFile] = useState('');
    const [level, setLevel] = useState('');
    const [tail, setTail] = useState(500);
    const [live, setLive] = useState<boolean>(readLiveFlag);

    // Copilot #6 fix: the toggle reads `?live=1` on mount but never
    // wrote it back on flip, so deep-linking + share-URL semantics
    // were broken (LogsView uses the same `?tab=` pattern). Write
    // via `history.replaceState` so the URL stays consistent and the
    // admin can copy-paste a URL that preserves live mode.
    useEffect(() => {
        syncLiveFlagToUrl(live);
    }, [live]);

    // When the user types a custom filename (e.g. a rotated daily log
    // not in the dropdown) the custom field wins over the dropdown.
    const effectiveFile = customFile.trim() !== '' ? customFile.trim() : file;

    const q = useApplicationLog(
        { file: effectiveFile, level: level || undefined, tail },
        { live },
    );

    const state: 'loading' | 'ready' | 'empty' | 'error' = q.isLoading
        ? 'loading'
        : q.isError
          ? 'error'
          : (q.data?.lines.length ?? 0) === 0
            ? 'empty'
            : 'ready';

    const errorMessage = (() => {
        if (!q.isError) return null;
        const err = q.error as { response?: { status?: number; data?: { message?: string } } };
        const status = err?.response?.status ?? 0;
        const message = err?.response?.data?.message ?? 'Unexpected error';
        return `${status || 'ERR'}: ${message}`;
    })();

    return (
        <div
            data-testid="application-log"
            data-state={state}
            style={{ display: 'flex', flexDirection: 'column', gap: 12, padding: '12px 0' }}
        >
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, alignItems: 'flex-end' }}>
                <label style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                    <span style={labelStyle}>File (preset)</span>
                    <select
                        data-testid="application-log-file"
                        value={file}
                        onChange={(e) => setFile(e.target.value)}
                        style={inputStyle}
                    >
                        {COMMON_FILES.map((f) => (
                            <option key={f} value={f}>
                                {f}
                            </option>
                        ))}
                    </select>
                </label>
                <label style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                    <span style={labelStyle}>Or custom filename</span>
                    <input
                        data-testid="application-log-file-custom"
                        value={customFile}
                        onChange={(e) => setCustomFile(e.target.value)}
                        placeholder="laravel-2025-01-01.log"
                        style={{ ...inputStyle, minWidth: 220 }}
                    />
                </label>
                <label style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                    <span style={labelStyle}>Level</span>
                    <select
                        data-testid="application-log-level"
                        value={level}
                        onChange={(e) => setLevel(e.target.value)}
                        style={inputStyle}
                    >
                        {LEVELS.map((l) => (
                            <option key={l} value={l}>
                                {l || 'any'}
                            </option>
                        ))}
                    </select>
                </label>
                <label style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                    <span style={labelStyle}>Tail</span>
                    <input
                        data-testid="application-log-tail"
                        type="number"
                        min={1}
                        max={2000}
                        value={tail}
                        onChange={(e) => setTail(Math.max(1, Math.min(2000, Number(e.target.value) || 500)))}
                        style={{ ...inputStyle, minWidth: 100 }}
                    />
                </label>
                <button
                    type="button"
                    data-testid="application-log-refresh"
                    onClick={() => q.refetch()}
                    style={{
                        padding: '6px 12px',
                        fontSize: 12.5,
                        background: 'var(--bg-0)',
                        border: '1px solid var(--hairline)',
                        borderRadius: 6,
                        cursor: 'pointer',
                    }}
                >
                    Refresh
                </button>
                <label
                    data-testid="application-log-live-toggle"
                    style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12 }}
                >
                    <input
                        type="checkbox"
                        checked={live}
                        onChange={(e) => setLive(e.target.checked)}
                    />
                    Live (5s)
                </label>
            </div>

            {errorMessage ? (
                <div
                    data-testid="application-log-error"
                    style={{
                        padding: 12,
                        color: 'var(--danger-fg, #b91c1c)',
                        border: '1px solid var(--danger-fg, #b91c1c)',
                        borderRadius: 6,
                        fontSize: 13,
                    }}
                >
                    {errorMessage}
                </div>
            ) : null}

            {state === 'loading' ? (
                <div
                    data-testid="application-log-loading"
                    style={{ padding: 12, color: 'var(--fg-3)' }}
                >
                    Reading log…
                </div>
            ) : null}

            {state === 'empty' ? (
                <div
                    data-testid="application-log-empty"
                    style={{ padding: 12, color: 'var(--fg-3)' }}
                >
                    No lines to show for this file / level combo.
                </div>
            ) : null}

            {state === 'ready' ? (
                <>
                    <div style={{ fontSize: 11, color: 'var(--fg-3)' }}>
                        Showing {q.data?.lines.length ?? 0} lines (scanned{' '}
                        {q.data?.total_scanned ?? 0}
                        {q.data?.truncated ? ', truncated' : ''})
                    </div>
                    <pre
                        data-testid="application-log-lines"
                        style={{
                            padding: 10,
                            background: 'var(--bg-0)',
                            border: '1px solid var(--hairline)',
                            borderRadius: 6,
                            fontSize: 11.5,
                            fontFamily: 'var(--font-mono)',
                            maxHeight: '60vh',
                            overflow: 'auto',
                            margin: 0,
                        }}
                    >
                        {(q.data?.lines ?? []).join('\n')}
                    </pre>
                </>
            ) : null}
        </div>
    );
}

const labelStyle: React.CSSProperties = {
    fontSize: 10.5,
    color: 'var(--fg-3)',
    textTransform: 'uppercase',
    letterSpacing: '0.04em',
};
const inputStyle: React.CSSProperties = {
    padding: '6px 8px',
    fontSize: 12.5,
    background: 'var(--bg-0)',
    border: '1px solid var(--hairline)',
    borderRadius: 6,
    color: 'var(--fg-1)',
    minWidth: 140,
};
