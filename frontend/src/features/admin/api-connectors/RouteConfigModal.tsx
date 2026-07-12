import { useEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import type {
    ApiConnector,
    ApiRoute,
    EndpointTypeChoice,
    HttpMethod,
    PaginationConfig,
    ParamLocation,
    ParamSource,
    ParamType,
    RouteConfig,
    RouteConfigParam,
    TestResult,
} from './api-connectors.api';
import { useCreateRoute, useProduceConfig, useTestConfig, useUpdateRoute } from './api-connectors-hooks';
import { blankParam, diffGroups, emptyConfig, joinUrl, mapConfigErrors, routeToConfig, splitUrl } from './route-config';
import { modalBackdropStyle } from './styles';
import { prettyJson } from './pretty-json';
import { toAdminError } from '../shared/errors';
import { useToast } from '../shared/Toast';

/**
 * Route Config Modal — the from-scratch route editor. Its ENTIRE state is the
 * canonical config JSON ({@see RouteConfig}); the user sees only a clean form.
 * "Configura con AI" returns a config that fills the whole form in one setConfig
 * (with a green flash on the changed sections); "Testa" dry-runs the in-memory
 * config (so both work in create mode, no save-first); Save sends `{ config }`.
 *
 * Colours are theme tokens (light + dark); the AI indigo→violet gradient and the
 * success green are the only brand accents. Testids reuse `api-route-form-*`.
 */

const HTTP_METHODS: HttpMethod[] = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
const PARAM_LOCATIONS: ParamLocation[] = ['path', 'query', 'header', 'body'];
const PARAM_SOURCES: ParamSource[] = ['llm', 'fixed', 'secret'];
const PARAM_TYPES: ParamType[] = ['string', 'integer', 'number', 'boolean', 'array', 'object'];

const ENDPOINT_TYPES: { value: EndpointTypeChoice; label: string; hint: string }[] = [
    { value: 'auto', label: 'Auto', hint: 'Deduce list vs. detail dalla forma della risposta.' },
    { value: 'list', label: 'List', hint: 'Restituisce una collezione — item indicizzati.' },
    { value: 'detail', label: 'Detail', hint: 'Restituisce un singolo record per identificatore.' },
];

export interface RouteConfigModalProps {
    connector: ApiConnector;
    route: ApiRoute | null;
    onClose: () => void;
    onSaved?: () => void;
}

export function RouteConfigModal({ connector, route, onClose, onSaved }: RouteConfigModalProps) {
    const toast = useToast();
    const base = connector.base_url;
    const isEdit = !!route;

    const [config, setConfig] = useState<RouteConfig>(() => (route ? routeToConfig(route) : emptyConfig(connector)));
    const [dirty, setDirty] = useState(false);
    const [applied, setApplied] = useState<Record<string, boolean>>({});
    const flashTimer = useRef<number | undefined>(undefined);

    // console + validation UI
    const [exampleArgs, setExampleArgs] = useState('{}');
    const [argsError, setArgsError] = useState<string | null>(null);
    const [openApiUrl, setOpenApiUrl] = useState('');
    const [testResult, setTestResult] = useState<{ test: TestResult; endpoint_type?: string; item_count?: number | null } | null>(null);
    const [aiVerdict, setAiVerdict] = useState<{ source: string; ok: boolean; status: number | null } | null>(null);
    const [detectSource, setDetectSource] = useState<'response' | null>(null);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

    const createRoute = useCreateRoute();
    const updateRoute = useUpdateRoute();
    const testConfig = useTestConfig();
    const produceConfig = useProduceConfig();

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);
    useEffect(() => () => window.clearTimeout(flashTimer.current), []);

    // --- config patch helpers (the form binds to one grouped object) ---
    const patchIdentity = (p: Partial<RouteConfig['identity']>) => { setConfig((c) => ({ ...c, identity: { ...c.identity, ...p } })); setDirty(true); };
    const patchRequest = (p: Partial<RouteConfig['request']>) => { setConfig((c) => ({ ...c, request: { ...c.request, ...p } })); setDirty(true); };
    const patchResponse = (p: Partial<RouteConfig['response']>) => { setConfig((c) => ({ ...c, response: { ...c.response, ...p } })); setDirty(true); };
    const patchOptions = (p: Partial<RouteConfig['options']>) => { setConfig((c) => ({ ...c, options: { ...c.options, ...p } })); setDirty(true); };
    const setParams = (params: RouteConfigParam[]) => patchRequest({ params });

    function updateParam(i: number, p: Partial<RouteConfigParam>) {
        setParams(config.request.params.map((r, ix) => (ix === i ? { ...r, ...p } : r)));
    }
    function addParam() {
        setParams([...config.request.params, { ...blankParam(), sort_order: config.request.params.length }]);
    }
    function removeParam(i: number) {
        setParams(config.request.params.filter((_, ix) => ix !== i));
    }

    function flash(keys: (keyof RouteConfig)[]) {
        const map: Record<string, boolean> = {};
        keys.forEach((k) => (map[k] = true));
        setApplied(map);
        window.clearTimeout(flashTimer.current);
        flashTimer.current = window.setTimeout(() => setApplied({}), 1400);
    }

    function parseArgs(): Record<string, unknown> | null {
        const raw = exampleArgs.trim() === '' ? '{}' : exampleArgs;
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

    // --- actions (all operate on the in-memory config → work in create mode) ---
    function runTest() {
        const args = parseArgs();
        if (!args) return;
        testConfig.mutate(
            { connectorId: connector.id, config, exampleArgs: args },
            { onSuccess: (res) => setTestResult({ test: res.test, endpoint_type: res.endpoint_type, item_count: res.item_count }) },
        );
    }

    function runDetectPagination() {
        const args = parseArgs();
        if (!args) return;
        testConfig.mutate(
            { connectorId: connector.id, config, exampleArgs: args },
            {
                onSuccess: (res) => {
                    setTestResult({ test: res.test, endpoint_type: res.endpoint_type, item_count: res.item_count });
                    if (res.detected_pagination) {
                        patchResponse({ pagination: res.detected_pagination });
                        setDetectSource('response');
                        flash(['response']);
                    }
                },
            },
        );
    }

    function runAiConfigure() {
        const args = parseArgs();
        if (!args) return;
        produceConfig.mutate(
            { connectorId: connector.id, config, exampleArgs: args, openApiUrl: openApiUrl.trim() || undefined },
            {
                onSuccess: (res) => {
                    if (!res.config) {
                        setAiVerdict({ source: res.source, ok: res.final_test.ok, status: res.final_test.status });
                        setTestResult({ test: res.final_test });
                        return;
                    }
                    flash(diffGroups(config, res.config));
                    setConfig(res.config);
                    setDirty(true);
                    setTestResult({ test: res.final_test });
                    setAiVerdict({ source: res.source, ok: res.final_test.ok, status: res.final_test.status });
                    toast.success('Config generata con AI + test finale.', 'toast-api-route-ai-configured');
                },
            },
        );
    }

    function handleSave() {
        setSubmitError(null);
        setFieldErrors({});
        const onError = (e: unknown) => {
            const { message, fieldErrors: fe } = toAdminError(e);
            setSubmitError(message);
            setFieldErrors(mapConfigErrors(fe));
        };
        const done = (msg: string, id: string) => { toast.success(msg, id); onSaved?.(); onClose(); };
        if (route) {
            updateRoute.mutate({ routeId: route.id, config }, { onSuccess: () => done('Rotta salvata.', 'toast-api-route-updated'), onError });
        } else {
            createRoute.mutate({ connectorId: connector.id, config }, { onSuccess: () => done('Rotta creata.', 'toast-api-route-created'), onError });
        }
    }

    // --- derived ---
    const urlSplit = splitUrl(base, config.request.url);
    const testError =
        (testConfig.isError && toAdminError(testConfig.error).message) ||
        (produceConfig.isError && toAdminError(produceConfig.error).message) ||
        null;
    const responseBody = useMemo(() => (testResult ? prettyJson(testResult.test.body) : ''), [testResult]);
    const saving = createRoute.isPending || updateRoute.isPending;
    const aiRunning = produceConfig.isPending;
    const testing = testConfig.isPending;

    return (
        <div data-testid="api-route-form-backdrop" onClick={(e) => e.target === e.currentTarget && onClose()} style={modalBackdropStyle()}>
            <div role="dialog" aria-modal="true" aria-label={isEdit ? `Edit route: ${route?.name}` : 'New route'} data-testid="api-route-form" style={panelStyle}>
                {/* Header */}
                <div style={headerStyle}>
                    <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ fontSize: 16, fontWeight: 600, color: 'var(--fg-0)' }}>{isEdit ? 'Modifica rotta' : 'Nuova rotta'}</div>
                        <div style={{ fontSize: 12, color: 'var(--fg-3)', marginTop: 1 }}>{connector.name}</div>
                    </div>
                    <span data-testid="api-route-form-status" style={statusPill(dirty)}>
                        <span style={{ width: 6, height: 6, borderRadius: 999, background: dirty ? 'var(--warn, #fbbf24)' : 'var(--ok, #34d399)' }} />
                        {dirty ? 'Modifiche non salvate' : 'Salvata'}
                    </span>
                    <button type="button" aria-label="Close" data-testid="api-route-form-cancel" onClick={onClose} style={iconBtn}>{closeIcon}</button>
                </div>

                {/* Body */}
                <div className="amd-scroll" style={bodyStyle}>
                    {/* AI bar — the fast path, up top */}
                    <div style={aiCard}>
                        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 11 }}>
                            <div style={aiIcon}>{sparkIcon}</div>
                            <div style={{ flex: 1, minWidth: 0 }}>
                                <div style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--fg-0)' }}>Configura con AI</div>
                                <div style={{ fontSize: 12, color: 'var(--fg-2)', lineHeight: 1.5, marginTop: 2 }}>
                                    Chiama l'endpoint, analizza la risposta e compila da solo tutta la configurazione qui sotto.
                                </div>
                            </div>
                        </div>
                        <label htmlFor="api-route-form-ai-openapi-url" style={{ display: 'block', marginTop: 11 }}>
                            <span style={{ ...captionStyle, color: 'var(--fg-2)' }}>Link OpenAPI <span style={{ opacity: 0.7 }}>— opzionale, legge tutto dal contratto</span></span>
                            <input id="api-route-form-ai-openapi-url" data-testid="api-route-form-ai-openapi-url" type="url" value={openApiUrl} onChange={(e) => setOpenApiUrl(e.target.value)} placeholder="https://api.example.com/openapi.json" style={inputStyle} />
                        </label>
                        <div style={{ display: 'flex', gap: 9, marginTop: 11, alignItems: 'center', flexWrap: 'wrap' }}>
                            <button type="button" data-testid="api-route-form-ai-configure" disabled={aiRunning} onClick={runAiConfigure} style={aiBtn(aiRunning)}>
                                {aiRunning ? 'Analisi in corso…' : 'Configura con AI'}
                            </button>
                            {aiVerdict && (
                                <span data-testid="api-route-form-ai-applied" style={{ display: 'inline-flex', gap: 6, flexWrap: 'wrap' }}>
                                    <span data-testid="api-route-form-ai-source" style={pill('accent')}>{aiVerdict.source === 'openapi' ? 'da OpenAPI' : aiVerdict.source === 'response' ? 'da risposta' : 'nessuna'}</span>
                                    <span data-testid="api-route-form-ai-final-test" style={pill(aiVerdict.ok ? 'ok' : 'err')}>Test finale: {aiVerdict.ok ? 'OK' : 'Fallito'} — HTTP {aiVerdict.status ?? '—'}</span>
                                </span>
                            )}
                        </div>
                    </div>

                    {/* Identità */}
                    <Section title="Identità">
                        <Field label="Nome" required error={fieldErrors.name} errorId="api-route-form-name-error" flash={applied.identity}>
                            <input data-testid="api-route-form-name" value={config.identity.name} onChange={(e) => patchIdentity({ name: e.target.value })} style={inputStyle} />
                        </Field>
                        <div style={grid2}>
                            <Field label="Slug" note="— opz.">
                                <input data-testid="api-route-form-slug" value={config.identity.slug ?? ''} onChange={(e) => patchIdentity({ slug: e.target.value || null })} style={monoInput} />
                            </Field>
                            <Field label="Mode">
                                <div style={{ position: 'relative' }}>
                                    <select data-testid="api-route-form-mode" disabled value="tool" style={selectStyle}>
                                        <option value="tool">Tool</option>
                                        <option disabled>Ingest — Fase 2</option>
                                    </select>
                                    <span style={caretWrap}>{caret}</span>
                                </div>
                            </Field>
                        </div>
                        <Field label="Descrizione" note="— usata nella tool definition" flash={applied.identity}>
                            <textarea data-testid="api-route-form-description" rows={2} value={config.identity.description ?? ''} onChange={(e) => patchIdentity({ description: e.target.value || null })} placeholder="Cosa restituisce la rotta, così l'agent sa quando chiamarla…" style={textareaStyle} />
                        </Field>
                    </Section>

                    {/* Richiesta */}
                    <Section title="Richiesta">
                        <div style={{ display: 'grid', gridTemplateColumns: '120px 1fr', gap: 12 }}>
                            <Field label="Metodo" required>
                                <div style={{ position: 'relative' }}>
                                    <select data-testid="api-route-form-http_method" value={config.request.http_method} onChange={(e) => patchRequest({ http_method: e.target.value as HttpMethod })} style={selectStyle}>
                                        {HTTP_METHODS.map((m) => <option key={m} value={m}>{m}</option>)}
                                    </select>
                                    <span style={caretWrap}>{caret}</span>
                                </div>
                            </Field>
                            <Field label="Endpoint" required error={fieldErrors.url} errorId="api-route-form-url-error" flash={applied.request}>
                                <div style={pathWrap}>
                                    {urlSplit.prefix && <span title={urlSplit.prefix} style={pathPrefix}>{urlSplit.prefix}</span>}
                                    <input data-testid="api-route-form-url" value={urlSplit.path} onChange={(e) => patchRequest({ url: joinUrl(base, e.target.value) })} style={pathInput} />
                                </div>
                            </Field>
                        </div>
                        <Field label="Auth profile" note="— come autenticare la chiamata">
                            <div style={{ position: 'relative' }}>
                                <select data-testid="api-route-form-auth_profile_id" value={config.request.auth_profile_id ?? ''} onChange={(e) => patchRequest({ auth_profile_id: e.target.value === '' ? null : Number(e.target.value) })} style={selectStyle}>
                                    <option value="">Nessuno (chiamata anonima)</option>
                                    {(connector.auth_profiles ?? []).map((p) => <option key={p.id} value={p.id}>{p.type} (#{p.id})</option>)}
                                </select>
                                <span style={caretWrap}>{caret}</span>
                            </div>
                        </Field>
                    </Section>

                    {/* Parametri */}
                    <div style={applied.request ? flashBox : undefined}>
                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
                            <div style={{ ...groupHead, margin: 0 }}>Parametri <span style={{ color: 'var(--fg-3)', fontWeight: 600 }}>({config.request.params.length})</span></div>
                            <button type="button" data-testid="api-route-form-param-add" onClick={addParam} style={miniBtn}>+ Aggiungi parametro</button>
                        </div>
                        {config.request.params.length === 0 ? (
                            <div data-testid="api-route-form-params-empty" style={emptyBox}>Nessun parametro. La rotta è chiamata così com'è.</div>
                        ) : (
                            <div style={{ border: '1px solid var(--hairline)', borderRadius: 11, overflow: 'hidden', background: 'var(--bg-2)' }}>
                                {config.request.params.map((p, i) => (
                                    <div key={i} style={paramRow}>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                            <input aria-label={`Parametro ${i + 1} nome`} data-testid={`api-route-form-param-${i}-name`} value={p.name} onChange={(e) => updateParam(i, { name: e.target.value })} placeholder="nome" style={{ ...monoInputSm, flex: 1 }} />
                                            <CompactSelect ariaLabel={`Parametro ${i + 1} location`} testid={`api-route-form-param-${i}-location`} value={p.location} onChange={(v) => updateParam(i, { location: v as ParamLocation })} options={PARAM_LOCATIONS} />
                                            <CompactSelect ariaLabel={`Parametro ${i + 1} source`} testid={`api-route-form-param-${i}-source`} value={p.source} onChange={(v) => updateParam(i, { source: v as ParamSource })} options={PARAM_SOURCES} />
                                            <CompactSelect ariaLabel={`Parametro ${i + 1} tipo`} testid={`api-route-form-param-${i}-type`} value={p.type} onChange={(v) => updateParam(i, { type: v as ParamType })} options={PARAM_TYPES} />
                                            <button type="button" aria-label={`Rimuovi parametro ${i + 1}`} data-testid={`api-route-form-param-${i}-remove`} onClick={() => removeParam(i)} style={removeBtn}>{closeIconSm}</button>
                                        </div>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginTop: 8 }}>
                                            <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, color: 'var(--fg-2)', cursor: 'pointer' }}>
                                                <input type="checkbox" aria-label={`Parametro ${i + 1} obbligatorio`} data-testid={`api-route-form-param-${i}-required`} checked={p.required} onChange={(e) => updateParam(i, { required: e.target.checked })} />
                                                obbligatorio
                                            </label>
                                            {p.source === 'fixed' && (
                                                <input aria-label={`Parametro ${i + 1} valore fisso`} data-testid={`api-route-form-param-${i}-value`} value={p.value ?? ''} onChange={(e) => updateParam(i, { value: e.target.value })} placeholder="valore fisso" style={{ ...monoInputSm, flex: 1 }} />
                                            )}
                                            {p.source === 'secret' && (
                                                <input aria-label={`Parametro ${i + 1} secret ref`} data-testid={`api-route-form-param-${i}-secret_ref`} value={p.secret_ref ?? ''} onChange={(e) => updateParam(i, { secret_ref: e.target.value })} placeholder="chiave credenziale (secret_ref)" style={{ ...monoInputSm, flex: 1 }} />
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Risposta */}
                    <Section title="Mappatura risposta">
                        <div style={applied.response ? flashBox : undefined}>
                            <div id="api-route-form-endpoint_type-caption" style={labelStyle}>Tipo endpoint</div>
                            <div role="radiogroup" aria-labelledby="api-route-form-endpoint_type-caption" data-testid="api-route-form-endpoint_type" style={segmentWrap}>
                                {ENDPOINT_TYPES.map((o) => (
                                    <button key={o.value} type="button" role="radio" aria-checked={config.response.endpoint_type === o.value} data-testid={`api-route-form-endpoint_type-${o.value}`} onClick={() => patchResponse({ endpoint_type: o.value })} style={segmentBtn(config.response.endpoint_type === o.value)}>{o.label}</button>
                                ))}
                            </div>
                            <div style={hintStyle}>{(ENDPOINT_TYPES.find((o) => o.value === config.response.endpoint_type) ?? ENDPOINT_TYPES[0]).hint}</div>
                        </div>
                        {(config.response.endpoint_type === 'list' || config.response.endpoint_type === 'auto') && (
                            <>
                                <Field label="Items path" note="— dot-path all'array (vuoto = array top-level)" flash={applied.response}>
                                    <input data-testid="api-route-form-items_path" value={config.response.items_path ?? ''} onChange={(e) => patchResponse({ items_path: e.target.value })} placeholder="es. data.items" style={monoInput} />
                                </Field>
                                <PaginationCard
                                    pagination={config.response.pagination}
                                    setPagination={(p) => patchResponse({ pagination: p })}
                                    onDetect={runDetectPagination}
                                    detecting={testing}
                                    detectSource={detectSource}
                                />
                            </>
                        )}
                    </Section>

                    {/* Opzioni */}
                    <Section title="Opzioni">
                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 10 }}>
                            <Field label="Timeout" note="ms"><input data-testid="api-route-form-timeout_ms" value={config.options.timeout_ms ?? ''} onChange={(e) => patchOptions({ timeout_ms: intOrNull(e.target.value) })} placeholder="default" style={inputStyle} /></Field>
                            <Field label="Cache TTL" note="s"><input data-testid="api-route-form-cache_ttl_s" value={config.options.cache_ttl_s ?? ''} onChange={(e) => patchOptions({ cache_ttl_s: intOrNull(e.target.value) })} placeholder="0" style={inputStyle} /></Field>
                            <Field label="Rate limit" note="/min"><input data-testid="api-route-form-rate_limit" value={config.options.rate_limit ?? ''} onChange={(e) => patchOptions({ rate_limit: intOrNull(e.target.value) })} placeholder="0" style={inputStyle} /></Field>
                        </div>
                    </Section>

                    {/* Testa + viewer */}
                    <Section title="Prova">
                        <label htmlFor="api-route-form-example-args" style={{ display: 'block' }}>
                            <span style={labelStyle}>Example args <span style={{ color: 'var(--fg-3)', fontWeight: 400 }}>— JSON, i valori dei parametri LLM</span></span>
                            <textarea id="api-route-form-example-args" data-testid="api-route-form-example-args" rows={2} value={exampleArgs} onChange={(e) => setExampleArgs(e.target.value)} spellCheck={false} style={codeArea} />
                        </label>
                        {argsError && <span data-testid="api-route-form-example-args-error" style={{ fontSize: 12, color: 'var(--err, #fca5a5)' }}>{argsError}</span>}
                        <div style={{ display: 'flex', gap: 9, alignItems: 'center' }}>
                            <button type="button" data-testid="api-route-form-test" disabled={testing} onClick={runTest} style={primaryBtn(testing)}>{testing ? 'Chiamata…' : 'Testa chiamata'}</button>
                            {testResult && (
                                <span data-testid="api-route-form-test-result" data-ok={testResult.test.ok} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, fontWeight: 600, color: testResult.test.ok ? 'var(--ok, #34d399)' : 'var(--err, #fca5a5)' }}>
                                    {testResult.endpoint_type && <span data-testid="api-route-form-test-endpoint-type" data-endpoint-type={testResult.endpoint_type} style={pill('accent')}>{testResult.endpoint_type}</span>}
                                    HTTP {testResult.test.status ?? '—'} {testResult.test.status_label}{testResult.item_count != null ? ` · ${testResult.item_count} elementi` : ''}
                                </span>
                            )}
                        </div>
                        {testError && <div data-testid="api-route-form-test-error" role="alert" style={alertBox}>{testError}</div>}
                        {testResult && <pre className="amd-scroll" data-testid="api-route-form-response" style={preStyle}>{responseBody}</pre>}
                    </Section>
                </div>

                {/* Footer */}
                <div style={footerStyle}>
                    <span style={{ flex: 1, fontSize: 12, color: 'var(--fg-3)' }}>
                        {submitError ? <span data-testid="api-route-form-error" role="alert" style={{ color: 'var(--err, #fca5a5)' }}>{submitError}</span> : dirty ? 'Modifiche non salvate.' : 'Tutto salvato.'}
                    </span>
                    <button type="button" onClick={onClose} style={ghostBtn}>Annulla</button>
                    <button type="button" data-testid="api-route-form-submit" disabled={saving} onClick={handleSave} style={saveBtn}>{saving ? 'Salvo…' : isEdit ? 'Salva modifiche' : 'Crea rotta'}</button>
                </div>
            </div>
        </div>
    );
}

/* ── presentational helpers ───────────────────────────────────────────────── */

function intOrNull(raw: string): number | null {
    const t = raw.trim();
    if (t === '') return null;
    const n = Number.parseInt(t, 10);
    return Number.isNaN(n) ? null : n;
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div>
            <div style={groupHead}>{title}</div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>{children}</div>
        </div>
    );
}

function Field({ label, required, note, error, errorId, flash, children }: { label: string; required?: boolean; note?: string; error?: string; errorId?: string; flash?: boolean; children: ReactNode }) {
    return (
        <div style={flash ? flashBox : undefined}>
            <label style={{ display: 'block' }}>
                <span style={labelStyle}>
                    {label}
                    {required && <span style={{ color: 'var(--err, #f87171)' }}> *</span>}
                    {note && <span style={{ color: 'var(--fg-3)', fontWeight: 400 }}> {note}</span>}
                </span>
                {children}
            </label>
            {error && <span data-testid={errorId} role="alert" style={{ display: 'block', marginTop: 5, fontSize: 12, color: 'var(--err, #fca5a5)' }}>{error}</span>}
        </div>
    );
}

function CompactSelect({ testid, ariaLabel, value, onChange, options }: { testid: string; ariaLabel: string; value: string; onChange: (v: string) => void; options: string[] }) {
    return (
        <div style={{ position: 'relative', flex: 'none' }}>
            <select aria-label={ariaLabel} data-testid={testid} value={value} onChange={(e) => onChange(e.target.value)} style={compactSelect}>
                {options.map((o) => <option key={o} value={o}>{o}</option>)}
            </select>
            <span style={caretWrapSm}>{caretSm}</span>
        </div>
    );
}

function PaginationCard({ pagination, setPagination, onDetect, detecting, detectSource }: {
    pagination: PaginationConfig | null;
    setPagination: (p: PaginationConfig | null) => void;
    onDetect: () => void;
    detecting: boolean;
    detectSource: 'response' | null;
}) {
    const on = pagination != null && pagination.type !== 'none';
    const toggle = () => setPagination(on ? { type: 'none' } : { type: 'cursor' });
    const set = (patch: Partial<PaginationConfig>) => setPagination({ ...(pagination ?? { type: 'cursor' }), ...patch });
    return (
        <div style={{ border: '1px solid var(--hairline)', borderRadius: 11, background: 'var(--bg-2)', overflow: 'hidden' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '11px 13px' }}>
                <button type="button" data-testid="api-route-form-pagination-toggle" onClick={toggle} style={{ flex: 1, display: 'flex', alignItems: 'center', gap: 10, background: 'transparent', border: 'none', padding: 0, cursor: 'pointer', textAlign: 'left' }}>
                    <span style={{ flex: 1, fontSize: 13, fontWeight: 600, color: 'var(--fg-1)' }}>Paginazione</span>
                    {on && <span style={pill('accent')}>{pagination?.type}</span>}
                    <span style={knobWrap(on)}><span style={knob(on)} /></span>
                </button>
                <button type="button" data-testid="api-route-form-pagination-detect" disabled={detecting} onClick={onDetect} style={miniBtn}>{detecting ? 'Rilevo…' : 'Rileva'}</button>
            </div>
            {detectSource && (
                <div data-testid="api-route-form-pagination-source" style={{ padding: '0 13px 8px', fontSize: 11.5, color: 'var(--fg-3)' }}>Rilevata dalla risposta.</div>
            )}
            {on && pagination && (
                <div style={{ padding: '2px 13px 14px', display: 'flex', flexDirection: 'column', gap: 12, borderTop: '1px solid var(--hairline)' }}>
                    <label style={{ display: 'block', marginTop: 12 }}>
                        <span style={captionStyle}>Tipo</span>
                        <div style={{ position: 'relative' }}>
                            <select data-testid="api-route-form-pagination-type" value={pagination.type} onChange={(e) => set({ type: e.target.value as PaginationConfig['type'] })} style={selectStyle}>
                                <option value="cursor">Cursore / token</option>
                                <option value="page">Numero pagina</option>
                            </select>
                            <span style={caretWrap}>{caret}</span>
                        </div>
                    </label>
                    {pagination.type === 'cursor' ? (
                        <>
                            <div style={grid2}>
                                <label style={{ display: 'block' }}><span style={captionStyle}>Param cursore</span><input data-testid="api-route-form-pagination-cursor_param" value={pagination.cursor_param ?? ''} onChange={(e) => set({ cursor_param: e.target.value })} placeholder="cursor" style={monoInputSm} /></label>
                                <label style={{ display: 'block' }}><span style={captionStyle}>Next-cursor path</span><input data-testid="api-route-form-pagination-next_cursor_path" value={pagination.next_cursor_path ?? ''} onChange={(e) => set({ next_cursor_path: e.target.value })} placeholder="meta.next_cursor" style={monoInputSm} /></label>
                            </div>
                            <label style={{ display: 'block' }}><span style={captionStyle}>Next-URL path <span style={{ color: 'var(--fg-3)', fontWeight: 400 }}>— opz.</span></span><input data-testid="api-route-form-pagination-next_url_path" value={pagination.next_url_path ?? ''} onChange={(e) => set({ next_url_path: e.target.value })} placeholder="links.next" style={monoInputSm} /></label>
                        </>
                    ) : (
                        <div style={grid2}>
                            <label style={{ display: 'block' }}><span style={captionStyle}>Param pagina</span><input data-testid="api-route-form-pagination-page_param" value={pagination.page_param ?? ''} onChange={(e) => set({ page_param: e.target.value })} placeholder="page" style={monoInputSm} /></label>
                            <label style={{ display: 'block' }}><span style={captionStyle}>Param dimensione</span><input data-testid="api-route-form-pagination-size_param" value={pagination.size_param ?? ''} onChange={(e) => set({ size_param: e.target.value })} placeholder="per_page" style={monoInputSm} /></label>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

/* ── styles (theme tokens) ────────────────────────────────────────────────── */

const mono = 'var(--mono, ui-monospace, monospace)';
const ACCENT_GRAD = 'linear-gradient(135deg,#6366f1,#8b5cf6)';

const panelStyle: CSSProperties = { width: 620, maxWidth: '100%', maxHeight: '92vh', display: 'flex', flexDirection: 'column', background: 'var(--bg-1)', border: '1px solid var(--hairline)', borderRadius: 16, boxShadow: '0 24px 70px rgba(0,0,0,.5)', overflow: 'hidden' };
const headerStyle: CSSProperties = { flex: 'none', padding: '16px 20px', borderBottom: '1px solid var(--hairline)', display: 'flex', alignItems: 'center', gap: 12 };
const bodyStyle: CSSProperties = { flex: 1, overflowY: 'auto', padding: '18px 20px 24px', display: 'flex', flexDirection: 'column', gap: 20 };
const footerStyle: CSSProperties = { flex: 'none', display: 'flex', alignItems: 'center', gap: 10, padding: '13px 20px', borderTop: '1px solid var(--hairline)', background: 'var(--bg-1)' };

const groupHead: CSSProperties = { fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.07em', color: 'var(--fg-3)', marginBottom: 12 };
const labelStyle: CSSProperties = { display: 'block', fontSize: 12.5, fontWeight: 600, color: 'var(--fg-1)', marginBottom: 6 };
const captionStyle: CSSProperties = { display: 'block', fontSize: 11.5, fontWeight: 600, color: 'var(--fg-2)', marginBottom: 5 };
const hintStyle: CSSProperties = { fontSize: 11.5, color: 'var(--fg-3)', marginTop: 6, lineHeight: 1.45 };
const grid2: CSSProperties = { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 };

const inputBase: CSSProperties = { width: '100%', background: 'var(--bg-2)', border: '1px solid var(--hairline)', borderRadius: 9, color: 'var(--fg-0)', font: 'inherit', fontSize: 13.5, padding: '10px 12px', outline: 'none' };
const inputStyle: CSSProperties = inputBase;
const monoInput: CSSProperties = { ...inputBase, fontFamily: mono, fontSize: 12.5 };
const monoInputSm: CSSProperties = { ...inputBase, fontFamily: mono, fontSize: 12, padding: '8px 10px' };
const textareaStyle: CSSProperties = { ...inputBase, resize: 'vertical', lineHeight: 1.5, minHeight: 56 };
const codeArea: CSSProperties = { ...inputBase, background: 'var(--bg-3, var(--bg-2))', fontFamily: mono, fontSize: 12.5, lineHeight: 1.6, resize: 'vertical', minHeight: 52 };
const selectStyle: CSSProperties = { ...inputBase, paddingRight: 34, cursor: 'pointer' };
const compactSelect: CSSProperties = { width: 92, background: 'var(--bg-1)', border: '1px solid var(--hairline)', borderRadius: 7, color: 'var(--fg-1)', font: 'inherit', fontSize: 12, padding: '7px 22px 7px 9px', outline: 'none', cursor: 'pointer', appearance: 'none' };

const caretWrap: CSSProperties = { position: 'absolute', right: 12, top: '50%', transform: 'translateY(-50%)', pointerEvents: 'none', display: 'flex' };
const caretWrapSm: CSSProperties = { position: 'absolute', right: 7, top: '50%', transform: 'translateY(-50%)', pointerEvents: 'none', display: 'flex' };

const pathWrap: CSSProperties = { display: 'flex', alignItems: 'stretch', background: 'var(--bg-2)', border: '1px solid var(--hairline)', borderRadius: 9, overflow: 'hidden' };
const pathPrefix: CSSProperties = { flex: 'none', maxWidth: '48%', display: 'flex', alignItems: 'center', padding: '0 4px 0 11px', background: 'var(--bg-1)', borderRight: '1px solid var(--hairline)', fontFamily: mono, fontSize: 12, color: 'var(--fg-3)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', direction: 'rtl' };
const pathInput: CSSProperties = { flex: 1, minWidth: 0, background: 'transparent', border: 'none', outline: 'none', color: 'var(--fg-0)', fontFamily: mono, fontSize: 12, padding: '10px 11px' };

const segmentWrap: CSSProperties = { display: 'flex', background: 'var(--bg-2)', border: '1px solid var(--hairline)', borderRadius: 10, padding: 3, gap: 2 };
const segmentBtn = (activeState: boolean): CSSProperties => ({ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', background: activeState ? 'var(--bg-3, var(--hairline))' : 'transparent', color: activeState ? 'var(--fg-0)' : 'var(--fg-3)', border: 'none', font: 'inherit', fontSize: 13, fontWeight: 600, padding: '8px 10px', borderRadius: 7, cursor: 'pointer' });

const paramRow: CSSProperties = { padding: '10px 10px', borderBottom: '1px solid var(--hairline)' };
const removeBtn: CSSProperties = { flex: 'none', width: 30, height: 30, borderRadius: 7, border: '1px solid transparent', background: 'transparent', color: 'var(--fg-3)', display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer' };
const emptyBox: CSSProperties = { border: '1px dashed var(--hairline)', borderRadius: 11, padding: 16, textAlign: 'center', color: 'var(--fg-3)', fontSize: 12.5 };
const flashBox: CSSProperties = { borderRadius: 10, padding: 6, margin: -6, transition: 'background-color 1.2s ease, box-shadow 1.2s ease', backgroundColor: 'rgba(16,185,129,.14)', boxShadow: '0 0 0 1px rgba(16,185,129,.4)' };

const aiCard: CSSProperties = { border: '1px solid #8b5cf655', borderRadius: 12, background: 'linear-gradient(180deg,rgba(139,92,246,.1),rgba(99,102,241,.04))', padding: 15 };
const aiIcon: CSSProperties = { flex: 'none', width: 32, height: 32, borderRadius: 9, background: ACCENT_GRAD, display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: '0 2px 10px rgba(99,102,241,.4)' };
const alertBox: CSSProperties = { padding: 10, fontSize: 12.5, background: 'rgba(239,68,68,.08)', border: '1px solid rgba(239,68,68,.3)', borderRadius: 8, color: 'var(--err, #fca5a5)' };
const preStyle: CSSProperties = { margin: 0, maxHeight: 260, overflow: 'auto', background: 'var(--bg-3, var(--bg-2))', border: '1px solid var(--hairline)', borderRadius: 10, padding: 12, fontFamily: mono, fontSize: 12, lineHeight: 1.6, color: 'var(--fg-1)', whiteSpace: 'pre' };

const miniBtn: CSSProperties = { flex: 'none', background: 'var(--bg-2)', border: '1px solid var(--hairline)', color: 'var(--fg-1)', font: 'inherit', fontSize: 12, fontWeight: 600, padding: '6px 11px', borderRadius: 8, cursor: 'pointer' };
const iconBtn: CSSProperties = { flex: 'none', width: 32, height: 32, borderRadius: 8, border: '1px solid transparent', background: 'transparent', color: 'var(--fg-3)', display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer' };
const ghostBtn: CSSProperties = { border: '1px solid var(--hairline)', background: 'transparent', color: 'var(--fg-1)', font: 'inherit', fontSize: 13, fontWeight: 600, padding: '9px 16px', borderRadius: 9, cursor: 'pointer' };
const primaryBtn = (busy: boolean): CSSProperties => ({ display: 'inline-flex', alignItems: 'center', gap: 7, border: 'none', background: ACCENT_GRAD, color: '#fff', font: 'inherit', fontSize: 13, fontWeight: 600, padding: '9px 16px', borderRadius: 9, cursor: busy ? 'default' : 'pointer', boxShadow: '0 2px 12px rgba(99,102,241,.4)', opacity: busy ? 0.8 : 1 });
const aiBtn = (busy: boolean): CSSProperties => ({ display: 'inline-flex', alignItems: 'center', gap: 8, border: 'none', background: ACCENT_GRAD, color: '#fff', font: 'inherit', fontSize: 13, fontWeight: 600, padding: '9px 15px', borderRadius: 9, cursor: busy ? 'default' : 'pointer', boxShadow: '0 2px 12px rgba(124,58,237,.4)', opacity: busy ? 0.8 : 1 });
const saveBtn: CSSProperties = { border: 'none', background: ACCENT_GRAD, color: '#fff', font: 'inherit', fontSize: 13, fontWeight: 600, padding: '9px 18px', borderRadius: 9, cursor: 'pointer', boxShadow: '0 2px 12px rgba(99,102,241,.4)' };

const statusPill = (isDirty: boolean): CSSProperties => ({ flex: 'none', display: 'inline-flex', alignItems: 'center', gap: 6, padding: '3px 10px', borderRadius: 999, fontSize: 11.5, fontWeight: 600, color: isDirty ? 'var(--warn, #fbbf24)' : 'var(--ok, #34d399)', background: isDirty ? 'rgba(251,191,36,.13)' : 'rgba(52,211,153,.13)' });
function pill(kind: 'ok' | 'err' | 'accent'): CSSProperties {
    const map = { ok: ['#34d399', 'rgba(52,211,153,.14)'], err: ['#fca5a5', 'rgba(239,68,68,.14)'], accent: ['#a5b4fc', 'rgba(99,102,241,.16)'] } as const;
    const [fg, bg] = map[kind];
    return { fontSize: 11.5, fontWeight: 600, padding: '2px 8px', borderRadius: 999, color: fg, background: bg };
}
const knobWrap = (isOn: boolean): CSSProperties => ({ flex: 'none', width: 36, height: 21, borderRadius: 999, background: isOn ? '#8b5cf6' : 'var(--hairline)', position: 'relative', transition: 'background .15s' });
const knob = (isOn: boolean): CSSProperties => ({ position: 'absolute', top: 2, left: isOn ? 17 : 2, width: 17, height: 17, borderRadius: 999, background: '#fff', transition: 'left .15s' });

const closeIcon = <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M18 6 6 18" /><path d="M6 6l12 12" /></svg>;
const closeIconSm = <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M18 6 6 18" /><path d="M6 6l12 12" /></svg>;
const caret = <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--fg-3)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="m6 9 6 6 6-6" /></svg>;
const caretSm = <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--fg-3)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="m6 9 6 6 6-6" /></svg>;
const sparkIcon = <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 3v2" /><path d="m19 5-1.5 1.5" /><path d="M21 12h-2" /><path d="M12 21v-2" /><path d="m5 19 1.5-1.5" /><path d="M3 12h2" /><path d="M5 5l1.5 1.5" /><circle cx="12" cy="12" r="3.5" /></svg>;
