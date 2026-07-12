import { useEffect, useState, type CSSProperties } from 'react';
import type { ApiRoute } from './api-connectors.api';
import { useAnalyzeRoute, useTestRoute } from './api-connectors-hooks';
import { modalBackdropStyle, modalPanelStyle } from './styles';
import { prettyJson } from './pretty-json';
import { toAdminError } from '../shared/errors';

/**
 * "Test & esplora" — the route workbench (spec items 1-6). A tabbed modal opened
 * from the route editor (and the row) that fires the route live and lets the
 * operator explore the response. Self-contained: owns its own test/analyze
 * mutations and result state.
 *
 * P1 tabs: **Test** (fire + raw response + schema) and **Dati** (the
 * deterministically REDUCED structure so a long list reads start-to-end).
 * Analisi (AI) / Paginazione / Cerca land in later phases.
 *
 * R11/R29 testids `api-route-wb-…`; R15 `role=tablist`/`aria-selected` on the
 * focusable tab buttons + Esc-to-close; R14 every failure surfaces in the DOM.
 */

type Tab = 'test' | 'data' | 'analysis';

const TABS: { id: Tab; label: string }[] = [
    { id: 'test', label: 'Test' },
    { id: 'data', label: 'Dati' },
    { id: 'analysis', label: 'Analisi' },
];

export interface RouteWorkbenchProps {
    route: ApiRoute;
    onClose: () => void;
}

export function RouteWorkbench({ route, onClose }: RouteWorkbenchProps) {
    const [tab, setTab] = useState<Tab>('test');
    const [exampleArgsText, setExampleArgsText] = useState('{}');
    const [argsError, setArgsError] = useState<string | null>(null);

    const testMutation = useTestRoute();
    const analyzeMutation = useAnalyzeRoute();

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    function parseArgs(): Record<string, unknown> | null {
        const raw = exampleArgsText.trim() === '' ? '{}' : exampleArgsText;
        try {
            const parsed: unknown = JSON.parse(raw);
            if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
                setArgsError('Gli example args devono essere un oggetto JSON.');
                return null;
            }
            setArgsError(null);
            return parsed as Record<string, unknown>;
        } catch {
            setArgsError('JSON non valido.');
            return null;
        }
    }

    function runTest() {
        const args = parseArgs();
        if (!args) return;
        testMutation.mutate({ routeId: route.id, exampleArgs: args });
    }

    function runAnalyze() {
        const args = parseArgs();
        if (!args) return;
        analyzeMutation.mutate({ routeId: route.id, exampleArgs: args });
    }

    const test = testMutation.data ?? null;
    const testError = testMutation.isError ? toAdminError(testMutation.error).message : null;
    const analyze = analyzeMutation.data ?? null;
    const analyzeError = analyzeMutation.isError ? toAdminError(analyzeMutation.error).message : null;

    const state: 'idle' | 'loading' | 'ready' | 'error' = testMutation.isPending
        ? 'loading'
        : test === null
          ? 'idle'
          : test.test.ok
            ? 'ready'
            : 'error';

    return (
        <div
            data-testid="api-route-wb-backdrop"
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
            style={modalBackdropStyle()}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-label={`Test & esplora: ${route.name}`}
                data-testid="api-route-wb"
                data-state={state}
                style={modalPanelStyle(760)}
            >
                <div>
                    <h2 style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>Test &amp; esplora: {route.name}</h2>
                    <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--fg-3)', fontFamily: 'var(--mono, monospace)' }}>
                        {route.http_method} {route.url}
                    </p>
                </div>

                <div role="tablist" aria-label="Workbench" style={{ display: 'flex', gap: 4, background: 'var(--bg-2)', borderRadius: 9, padding: 3 }}>
                    {TABS.map((t) => (
                        <button
                            key={t.id}
                            type="button"
                            role="tab"
                            aria-selected={tab === t.id}
                            data-testid={`api-route-wb-tab-${t.id}`}
                            className="focus-ring"
                            onClick={() => setTab(t.id)}
                            style={tabStyle(tab === t.id)}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>

                {/* Shared example-args — both tabs fire the route with these. */}
                <label htmlFor="api-route-wb-example-args" style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                    <span style={{ fontSize: 12, color: 'var(--fg-2)' }}>Example args (JSON — i valori dei parametri llm)</span>
                    <textarea
                        id="api-route-wb-example-args"
                        data-testid="api-route-wb-example-args"
                        value={exampleArgsText}
                        onChange={(e) => setExampleArgsText(e.target.value)}
                        rows={3}
                        spellCheck={false}
                        style={{
                            fontFamily: 'var(--mono, monospace)',
                            fontSize: 12,
                            padding: 8,
                            borderRadius: 8,
                            border: '1px solid var(--hairline)',
                            background: 'var(--bg-1)',
                            color: 'var(--fg-0)',
                            resize: 'vertical',
                        }}
                    />
                    {argsError && (
                        <span data-testid="api-route-wb-example-args-error" style={{ fontSize: 12, color: '#fca5a5' }}>
                            {argsError}
                        </span>
                    )}
                </label>

                {tab === 'test' && (
                    <div role="tabpanel" data-testid="api-route-wb-panel-test" style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                        <button type="button" data-testid="api-route-wb-test-run" className="focus-ring" disabled={testMutation.isPending} onClick={runTest} style={primaryBtn(testMutation.isPending)}>
                            {testMutation.isPending ? 'Testing…' : 'Testa chiamata'}
                        </button>
                        {testError && (
                            <div data-testid="api-route-wb-test-error" role="alert" style={alertStyle()}>
                                {testError}
                            </div>
                        )}
                        {test && (
                            <div data-testid="api-route-wb-test-result" data-ok={test.test.ok} style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                    <span data-testid="api-route-wb-test-status" style={pillStyle(test.test.ok)}>
                                        {test.test.ok ? 'OK' : 'Failed'} — HTTP {test.test.status ?? '—'} {test.test.status_label}
                                    </span>
                                    <span data-testid="api-route-wb-test-endpoint-type" data-endpoint-type={test.endpoint_type} style={pillStyle(true)}>
                                        {test.endpoint_type}
                                    </span>
                                </div>
                                {test.test.error && (
                                    <div data-testid="api-route-wb-test-result-error" style={alertStyle()}>
                                        {test.test.error}
                                    </div>
                                )}
                                <pre data-testid="api-route-wb-test-body" style={preStyle()}>
                                    {prettyJson(test.test.body)}
                                </pre>
                            </div>
                        )}
                    </div>
                )}

                {tab === 'data' && (
                    <div role="tabpanel" data-testid="api-route-wb-panel-data" style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                        <p style={{ margin: 0, fontSize: 12, color: 'var(--fg-3)' }}>
                            Struttura ridotta: ogni lista lunga è troncata a pochi elementi, così vedi la forma dall'inizio alla fine.
                        </p>
                        <button type="button" data-testid="api-route-wb-analyze-run" className="focus-ring" disabled={analyzeMutation.isPending} onClick={runAnalyze} style={primaryBtn(analyzeMutation.isPending)}>
                            {analyzeMutation.isPending ? 'Riduzione…' : 'Riduci struttura'}
                        </button>
                        {analyzeError && (
                            <div data-testid="api-route-wb-data-error" role="alert" style={alertStyle()}>
                                {analyzeError}
                            </div>
                        )}
                        {analyze && (
                            <>
                                {analyze.notes.length > 0 && (
                                    <p data-testid="api-route-wb-data-notes" style={{ margin: 0, fontSize: 12, color: 'var(--fg-2)' }}>
                                        Ridotti {analyze.notes.length} grupp{analyze.notes.length === 1 ? 'o' : 'i'} — il più grande:{' '}
                                        <code>{analyze.notes[0].path}</code> ({analyze.notes[0].omitted} omessi su {analyze.notes[0].total}).
                                    </p>
                                )}
                                <pre data-testid="api-route-wb-reduced" style={preStyle()}>
                                    {analyze.reduced == null ? '(risposta non-JSON — nessuna struttura da ridurre)' : prettyJson(analyze.reduced)}
                                </pre>
                            </>
                        )}
                    </div>
                )}

                {tab === 'analysis' && (
                    <div role="tabpanel" data-testid="api-route-wb-panel-analysis" style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                        <p style={{ margin: 0, fontSize: 12, color: 'var(--fg-3)' }}>
                            Analisi AI della struttura (eseguita sulla versione ridotta). Richiede un provider AI configurato.
                        </p>
                        <button type="button" data-testid="api-route-wb-analyze-ai-run" className="focus-ring" disabled={analyzeMutation.isPending} onClick={runAnalyze} style={primaryBtn(analyzeMutation.isPending)}>
                            {analyzeMutation.isPending ? 'Analisi…' : 'Analizza'}
                        </button>
                        {analyzeError && (
                            <div data-testid="api-route-wb-analysis-error" role="alert" style={alertStyle()}>
                                {analyzeError}
                            </div>
                        )}
                        {analyze &&
                            (analyze.analysis ? (
                                <div
                                    data-testid="api-route-wb-analysis"
                                    style={{
                                        fontSize: 13,
                                        lineHeight: 1.55,
                                        color: 'var(--fg-1)',
                                        background: 'var(--bg-2)',
                                        borderRadius: 8,
                                        padding: 12,
                                        whiteSpace: 'pre-wrap',
                                    }}
                                >
                                    {analyze.analysis}
                                </div>
                            ) : (
                                <p data-testid="api-route-wb-analysis-empty" style={{ margin: 0, fontSize: 12.5, color: 'var(--fg-3)' }}>
                                    Nessuna analisi AI (provider non configurato o llm_assist disattivo). La struttura ridotta è nel tab “Dati”.
                                </p>
                            ))}
                    </div>
                )}

                <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
                    <button type="button" data-testid="api-route-wb-close" className="focus-ring" onClick={onClose} style={secondaryBtn()}>
                        Chiudi
                    </button>
                </div>
            </div>
        </div>
    );
}

function tabStyle(active: boolean): CSSProperties {
    return {
        flex: 1,
        fontSize: 12.5,
        fontWeight: 600,
        padding: '6px 12px',
        borderRadius: 7,
        border: 'none',
        background: active ? 'var(--bg-3)' : 'transparent',
        color: active ? 'var(--fg-0)' : 'var(--fg-3)',
        cursor: 'pointer',
    };
}

function preStyle(): CSSProperties {
    return {
        margin: 0,
        maxHeight: 260,
        overflow: 'auto',
        fontFamily: 'var(--mono, monospace)',
        fontSize: 12,
        background: 'var(--bg-3)',
        borderRadius: 6,
        padding: 8,
        color: 'var(--fg-1)',
        whiteSpace: 'pre-wrap',
        wordBreak: 'break-word',
    };
}

function pillStyle(ok: boolean): CSSProperties {
    return {
        fontSize: 11.5,
        fontWeight: 600,
        padding: '2px 8px',
        borderRadius: 999,
        background: ok ? 'rgba(34,197,94,0.14)' : 'rgba(239,68,68,0.14)',
        color: ok ? '#86efac' : '#fca5a5',
    };
}

function alertStyle(): CSSProperties {
    return {
        padding: 10,
        fontSize: 12.5,
        background: 'rgba(239,68,68,0.08)',
        border: '1px solid rgba(239,68,68,0.30)',
        borderRadius: 8,
        color: '#fca5a5',
    };
}

function primaryBtn(disabled: boolean): CSSProperties {
    return {
        alignSelf: 'flex-start',
        fontSize: 12.5,
        fontWeight: 600,
        padding: '7px 14px',
        borderRadius: 8,
        border: '1px solid var(--hairline)',
        background: 'var(--bg-2)',
        color: 'var(--fg-0)',
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.6 : 1,
    };
}

function secondaryBtn(): CSSProperties {
    return {
        fontSize: 12.5,
        padding: '7px 12px',
        borderRadius: 8,
        border: '1px solid var(--hairline)',
        background: 'transparent',
        color: 'var(--fg-2)',
        cursor: 'pointer',
    };
}
