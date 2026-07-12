import { useEffect, useState, type CSSProperties } from 'react';
import type { ApiRoute, PaginationConfig } from './api-connectors.api';
import {
    useAnalyzeRoute,
    useApplyAiConfigure,
    useDetectPagination,
    useTestPagination,
    useTestRoute,
    useTestSearch,
    useUpdateRoute,
} from './api-connectors-hooks';
import { modalBackdropStyle, modalPanelStyle } from './styles';
import { prettyJson } from './pretty-json';
import { toAdminError } from '../shared/errors';
import { useToast } from '../shared/Toast';

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

type Tab = 'test' | 'data' | 'analysis' | 'pagination' | 'search';

const TABS: { id: Tab; label: string }[] = [
    { id: 'test', label: 'Test' },
    { id: 'data', label: 'Dati' },
    { id: 'analysis', label: 'Analisi' },
    { id: 'pagination', label: 'Paginazione' },
    { id: 'search', label: 'Cerca' },
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
    const applyAiMutation = useApplyAiConfigure();
    const detectMutation = useDetectPagination();
    const testPaginationMutation = useTestPagination();
    const updateRoute = useUpdateRoute();
    const searchMutation = useTestSearch();
    const toast = useToast();
    const [pagination, setPagination] = useState<PaginationConfig | null>(route.pagination ?? null);
    const [searchValues, setSearchValues] = useState<Record<string, string>>({});
    const [openApiUrl, setOpenApiUrl] = useState('');

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

    function runAiConfigure() {
        const args = parseArgs();
        if (!args) return;
        applyAiMutation.mutate(
            { routeId: route.id, exampleArgs: args, openApiUrl: openApiUrl.trim() || undefined },
            {
                onSuccess: (res) =>
                    res.applied &&
                    toast.success('Configurato con AI + test finale eseguito.', 'toast-api-route-ai-configured'),
            },
        );
    }

    function runDetect() {
        const args = parseArgs();
        if (!args) return;
        detectMutation.mutate(
            { routeId: route.id, exampleArgs: args },
            { onSuccess: (res) => res.config && setPagination(res.config) },
        );
    }

    function savePagination() {
        updateRoute.mutate(
            { routeId: route.id, payload: { pagination: pagination ?? null } },
            { onSuccess: () => toast.success('Paginazione salvata.', 'toast-api-route-pagination-saved') },
        );
    }

    function runTestPagination() {
        if (!pagination) return;
        const args = parseArgs();
        if (!args) return;
        testPaginationMutation.mutate({ routeId: route.id, pagination, exampleArgs: args });
    }

    function setPaginationType(type: PaginationConfig['type']) {
        setPagination((p) => ({ ...(p ?? {}), type }));
    }

    function setPaginationField(key: keyof PaginationConfig, value: string) {
        setPagination((p) => ({ ...(p ?? { type: 'none' }), [key]: value === '' ? undefined : value }));
    }

    function runSearch() {
        const args = Object.fromEntries(Object.entries(searchValues).filter(([, v]) => v !== ''));
        searchMutation.mutate({ routeId: route.id, searchArgs: args });
    }

    const test = testMutation.data ?? null;
    const testError = testMutation.isError ? toAdminError(testMutation.error).message : null;
    const analyze = analyzeMutation.data ?? null;
    const analyzeError = analyzeMutation.isError ? toAdminError(analyzeMutation.error).message : null;
    const applyAi = applyAiMutation.data ?? null;
    const applyAiError = applyAiMutation.isError ? toAdminError(applyAiMutation.error).message : null;
    const detect = detectMutation.data ?? null;
    const detectError = detectMutation.isError ? toAdminError(detectMutation.error).message : null;
    const pageTest = testPaginationMutation.data ?? null;
    const pageTestError = testPaginationMutation.isError ? toAdminError(testPaginationMutation.error).message : null;
    const search = searchMutation.data ?? null;
    const searchError = searchMutation.isError ? toAdminError(searchMutation.error).message : null;
    const llmParams = (route.parameters ?? []).filter((p) => p.source === 'llm');

    const state: 'idle' | 'loading' | 'ready' | 'error' = testMutation.isPending
        ? 'loading'
        : test === null
          ? 'idle'
          : test.test.ok
            ? 'ready'
            : 'error';

    const paginationField = (key: keyof PaginationConfig, label: string, placeholder: string) => (
        <label htmlFor={`api-route-wb-pagination-${key}`} style={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
            <span style={{ fontSize: 11.5, color: 'var(--fg-2)' }}>{label}</span>
            <input
                id={`api-route-wb-pagination-${key}`}
                data-testid={`api-route-wb-pagination-${key}`}
                value={(pagination?.[key] as string | undefined) ?? ''}
                onChange={(e) => setPaginationField(key, e.target.value)}
                placeholder={placeholder}
                spellCheck={false}
                style={{
                    fontFamily: 'var(--mono, monospace)',
                    fontSize: 12,
                    padding: '5px 8px',
                    borderRadius: 7,
                    border: '1px solid var(--hairline)',
                    background: 'var(--bg-1)',
                    color: 'var(--fg-0)',
                }}
            />
        </label>
    );

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
                    <div role="tabpanel" data-testid="api-route-wb-panel-analysis" style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                        {/* Configura con AI — ONE click: detect + apply + final test. */}
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 8, border: '1px solid var(--hairline)', borderRadius: 10, padding: 12 }}>
                            <p style={{ margin: 0, fontSize: 12.5, color: 'var(--fg-2)' }}>
                                <strong>Configura con AI</strong> — un click: rileva tipo, items_path, paginazione, nome/descrizione e parametri, <strong>applica tutto</strong> e fa il <strong>test finale</strong>.
                            </p>
                            <label htmlFor="api-route-wb-openapi-url" style={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
                                <span style={{ fontSize: 11.5, color: 'var(--fg-3)' }}>
                                    Link OpenAPI (opzionale) — se lo dai, legge tutto dal contratto (anche se l'endpoint richiede auth)
                                </span>
                                <input
                                    id="api-route-wb-openapi-url"
                                    data-testid="api-route-wb-openapi-url"
                                    type="url"
                                    value={openApiUrl}
                                    onChange={(e) => setOpenApiUrl(e.target.value)}
                                    placeholder="https://api.example.com/openapi.json"
                                    spellCheck={false}
                                    style={{ fontSize: 12, padding: '6px 8px', borderRadius: 7, border: '1px solid var(--hairline)', background: 'var(--bg-1)', color: 'var(--fg-0)' }}
                                />
                            </label>
                            <button type="button" data-testid="api-route-wb-ai-configure-run" className="focus-ring" disabled={applyAiMutation.isPending} onClick={runAiConfigure} style={primaryBtn(applyAiMutation.isPending)}>
                                {applyAiMutation.isPending ? 'Configuro + testo…' : 'Configura con AI'}
                            </button>
                            {applyAiError && (
                                <div data-testid="api-route-wb-ai-configure-error" role="alert" style={alertStyle()}>
                                    {applyAiError}
                                </div>
                            )}
                            {applyAi &&
                                (applyAi.applied ? (
                                    <div data-testid="api-route-wb-ai-applied" data-final-ok={applyAi.final_test.ok} style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                                        <ul style={{ margin: 0, paddingLeft: 18, fontSize: 12.5, color: 'var(--fg-1)', lineHeight: 1.6 }}>
                                            <li>
                                                Tipo: <strong>{applyAi.applied.endpoint_type}</strong>
                                                {applyAi.applied.items_path != null &&
                                                    ` · items: ${applyAi.applied.items_path === '' ? '(root)' : applyAi.applied.items_path}`}
                                            </li>
                                            <li>Paginazione: {applyAi.applied.pagination ? applyAi.applied.pagination.type : '—'}</li>
                                            <li>
                                                Tool: <code>{applyAi.applied.tool_name ?? '—'}</code>
                                                {applyAi.applied.tool_description ? ` — ${applyAi.applied.tool_description}` : ''}
                                            </li>
                                            <li>
                                                Parametri: {applyAi.applied.parameters.length}
                                                {applyAi.applied.parameters.length > 0 &&
                                                    ` (${applyAi.applied.parameters.map((p) => p.name).join(', ')})`}
                                            </li>
                                        </ul>
                                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                            <span data-testid="api-route-wb-ai-source" style={pillStyle(true)}>
                                                {applyAi.source === 'openapi' ? 'da OpenAPI' : 'da risposta'}
                                            </span>
                                            <span data-testid="api-route-wb-ai-final-test" style={pillStyle(applyAi.final_test.ok)}>
                                                Test finale: {applyAi.final_test.ok ? 'OK' : 'Fallito'} — HTTP {applyAi.final_test.status ?? '—'}
                                            </span>
                                            {applyAi.pagination_test && (
                                                <span data-testid="api-route-wb-ai-pagination-verdict" style={pillStyle(applyAi.pagination_test.distinct)}>
                                                    Paginazione: {applyAi.pagination_test.distinct ? 'distinte ✓' : 'non avanza'}
                                                </span>
                                            )}
                                        </div>
                                        <p style={{ margin: 0, fontSize: 11.5, color: 'var(--fg-3)' }}>
                                            Configurazione salvata sulla rotta (stato “tested”). Riapri l'editor per rifinire i campi.
                                        </p>
                                    </div>
                                ) : (
                                    <p data-testid="api-route-wb-ai-suggestion-empty" style={{ margin: 0, fontSize: 12.5, color: 'var(--fg-3)' }}>
                                        La chiamata non ha restituito JSON — configura metodo/auth/parametri nel tab “Test”, poi riprova.
                                    </p>
                                ))}
                        </div>

                        {/* Just narrate the structure (no apply). */}
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                            <button type="button" data-testid="api-route-wb-analyze-ai-run" className="focus-ring" disabled={analyzeMutation.isPending} onClick={runAnalyze} style={secondaryBtn()}>
                                {analyzeMutation.isPending ? 'Analisi…' : 'Solo descrivi la struttura'}
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
                                        style={{ fontSize: 13, lineHeight: 1.55, color: 'var(--fg-1)', background: 'var(--bg-2)', borderRadius: 8, padding: 12, whiteSpace: 'pre-wrap' }}
                                    >
                                        {analyze.analysis}
                                    </div>
                                ) : (
                                    <p data-testid="api-route-wb-analysis-empty" style={{ margin: 0, fontSize: 12.5, color: 'var(--fg-3)' }}>
                                        {analyze.reduced == null
                                            ? 'La chiamata non ha restituito JSON da analizzare — configura la chiamata nel tab “Test”.'
                                            : 'AI non disponibile (provider non configurato o llm_assist disattivo).'}
                                    </p>
                                ))}
                        </div>
                    </div>
                )}

                {tab === 'pagination' && (
                    <div role="tabpanel" data-testid="api-route-wb-panel-pagination" style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                            <button type="button" data-testid="api-route-wb-pagination-detect" className="focus-ring" disabled={detectMutation.isPending} onClick={runDetect} style={primaryBtn(detectMutation.isPending)}>
                                {detectMutation.isPending ? 'Rilevo…' : 'Rileva paginazione'}
                            </button>
                            {detect && (
                                <span data-testid="api-route-wb-pagination-source" data-source={detect.source} style={pillStyle(detect.source !== 'none')}>
                                    {detect.source === 'none' ? 'non rilevata' : detect.source === 'ai' ? 'rilevata (AI)' : 'rilevata (euristica)'}
                                </span>
                            )}
                        </div>
                        {detectError && (
                            <div data-testid="api-route-wb-pagination-detect-error" role="alert" style={alertStyle()}>
                                {detectError}
                            </div>
                        )}

                        <label htmlFor="api-route-wb-pagination-type" style={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
                            <span style={{ fontSize: 11.5, color: 'var(--fg-2)' }}>Tipo</span>
                            <select
                                id="api-route-wb-pagination-type"
                                data-testid="api-route-wb-pagination-type"
                                value={pagination?.type ?? 'none'}
                                onChange={(e) => setPaginationType(e.target.value as PaginationConfig['type'])}
                                style={{ fontSize: 12.5, padding: '6px 8px', borderRadius: 7, border: '1px solid var(--hairline)', background: 'var(--bg-1)', color: 'var(--fg-0)' }}
                            >
                                <option value="none">Nessuna</option>
                                <option value="page">Pagina (page number)</option>
                                <option value="cursor">Cursore / token</option>
                            </select>
                        </label>

                        {pagination?.type === 'page' && (
                            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
                                {paginationField('page_param', 'Param pagina', 'page')}
                                {paginationField('size_param', 'Param dimensione (opz.)', 'per_page')}
                            </div>
                        )}
                        {pagination?.type === 'cursor' && (
                            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                                {paginationField('cursor_param', 'Param cursore', 'cursor')}
                                {paginationField('next_cursor_path', 'Path del next-cursor nel body', 'meta.next_cursor')}
                                {paginationField('next_url_path', 'Path del next-URL (opz.)', 'links.next')}
                            </div>
                        )}
                        {(pagination?.type === 'page' || pagination?.type === 'cursor') && paginationField('items_path', 'Items path', 'data')}

                        <div style={{ display: 'flex', gap: 8 }}>
                            <button type="button" data-testid="api-route-wb-pagination-save" className="focus-ring" disabled={updateRoute.isPending} onClick={savePagination} style={primaryBtn(updateRoute.isPending)}>
                                {updateRoute.isPending ? 'Salvo…' : 'Salva'}
                            </button>
                            <button
                                type="button"
                                data-testid="api-route-wb-pagination-test"
                                className="focus-ring"
                                disabled={testPaginationMutation.isPending || !pagination || pagination.type === 'none'}
                                onClick={runTestPagination}
                                style={secondaryBtn()}
                            >
                                {testPaginationMutation.isPending ? 'Test…' : 'Testa paginazione'}
                            </button>
                        </div>
                        {pageTestError && (
                            <div data-testid="api-route-wb-pagination-test-error" role="alert" style={alertStyle()}>
                                {pageTestError}
                            </div>
                        )}
                        {pageTest && (
                            <div data-testid="api-route-wb-pagination-result" data-distinct={pageTest.distinct} style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                                <span data-testid="api-route-wb-pagination-verdict" style={pillStyle(pageTest.distinct)}>
                                    {pageTest.distinct ? 'Pagine distinte ✓' : 'Pagina 2 non avanza'}
                                </span>
                                <p style={{ margin: 0, fontSize: 12.5, color: 'var(--fg-2)' }}>{pageTest.note}</p>
                                <ul style={{ margin: 0, paddingLeft: 18, fontSize: 12.5, color: 'var(--fg-3)' }}>
                                    {pageTest.pages.map((pg, i) => (
                                        <li key={i}>
                                            Pagina {i + 1}: HTTP {pg.status ?? '—'} — {pg.item_count} item
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                )}

                {tab === 'search' && (
                    <div role="tabpanel" data-testid="api-route-wb-panel-search" style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                        {llmParams.length === 0 ? (
                            <p data-testid="api-route-wb-search-empty" style={{ margin: 0, fontSize: 12.5, color: 'var(--fg-3)' }}>
                                Questa rotta non ha parametri llm da cercare. Usa gli example args nel tab “Test”.
                            </p>
                        ) : (
                            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                                {llmParams.map((p) => (
                                    <label key={p.id} htmlFor={`api-route-wb-search-${p.name}`} style={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
                                        <span style={{ fontSize: 11.5, color: 'var(--fg-2)' }}>
                                            {p.name}
                                            {p.required ? ' *' : ''} <em style={{ color: 'var(--fg-3)' }}>({p.location})</em>
                                        </span>
                                        <input
                                            id={`api-route-wb-search-${p.name}`}
                                            data-testid={`api-route-wb-search-${p.name}`}
                                            value={searchValues[p.name] ?? ''}
                                            onChange={(e) => setSearchValues((v) => ({ ...v, [p.name]: e.target.value }))}
                                            placeholder={p.description ?? ''}
                                            spellCheck={false}
                                            style={{ fontSize: 12.5, padding: '6px 8px', borderRadius: 7, border: '1px solid var(--hairline)', background: 'var(--bg-1)', color: 'var(--fg-0)' }}
                                        />
                                    </label>
                                ))}
                            </div>
                        )}
                        <button type="button" data-testid="api-route-wb-search-run" className="focus-ring" disabled={searchMutation.isPending} onClick={runSearch} style={primaryBtn(searchMutation.isPending)}>
                            {searchMutation.isPending ? 'Cerca…' : 'Cerca'}
                        </button>
                        {searchError && (
                            <div data-testid="api-route-wb-search-error" role="alert" style={alertStyle()}>
                                {searchError}
                            </div>
                        )}
                        {search && (
                            <div data-testid="api-route-wb-search-result" data-ok={search.test.ok} style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                                <span data-testid="api-route-wb-search-status" style={pillStyle(search.test.ok)}>
                                    {search.test.ok ? 'OK' : 'Failed'} — HTTP {search.test.status ?? '—'} {search.test.status_label}
                                </span>
                                {search.test.error && (
                                    <div data-testid="api-route-wb-search-result-error" style={alertStyle()}>
                                        {search.test.error}
                                    </div>
                                )}
                                <pre data-testid="api-route-wb-search-body" style={preStyle()}>
                                    {prettyJson(search.test.body)}
                                </pre>
                            </div>
                        )}
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
