import { useEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import type {
    AiConfigureSuggestion,
    ApiConnector,
    ApiRoute,
    EndpointTypeChoice,
    HttpMethod,
    PaginationConfig,
    ParamLocation,
    ParamSource,
    ParamType,
    RouteParameterInput,
    RoutePayload,
} from './api-connectors.api';
import {
    useAnalyzeRoute,
    useApplyAiConfigure,
    useCreateRoute,
    useDetectPagination,
    useTestRoute,
    useUpdateRoute,
} from './api-connectors-hooks';
import { modalBackdropStyle } from './styles';
import { prettyJson } from './pretty-json';
import { toAdminError } from '../shared/errors';
import { useToast } from '../shared/Toast';

/**
 * Route Workspace (design "Route Workspace.dc.html") — the unified route editor:
 * a two-pane modal that merges the route DEFINITION (left) with the "Prova &
 * esplora" console (right). Test / AI results flow INTO the left config and the
 * touched fields flash. Replaces both the old Edit-route form and the tabbed
 * Test workbench.
 *
 * Layout is faithful to the mockup; colours come from the app's theme tokens
 * (light + dark), only the AI (indigo→violet) and success (green) accents are
 * kept as brand accents. Testids preserve the `api-route-form-*` (left) and
 * `api-route-wb-*` (right) contract so existing E2E/unit selectors keep working.
 */

const HTTP_METHODS: HttpMethod[] = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
const PARAM_LOCATIONS: ParamLocation[] = ['path', 'query', 'header', 'body'];
const PARAM_SOURCES: ParamSource[] = ['llm', 'fixed', 'secret'];
const PARAM_TYPES: ParamType[] = ['string', 'integer', 'number', 'boolean', 'array', 'object'];

const ENDPOINT_TYPE_OPTIONS: { value: EndpointTypeChoice; label: string; hint: string }[] = [
    { value: 'auto', label: 'Auto', hint: 'Deduce list vs. detail dalla forma della risposta.' },
    { value: 'list', label: 'List', hint: 'Restituisce una collezione — risultati paginati e indicizzati.' },
    { value: 'detail', label: 'Detail', hint: 'Restituisce un singolo record per identificatore.' },
];

interface ParamRow extends RouteParameterInput {
    _key: string;
}

let paramKeySeq = 1;

function toRows(params: ApiRoute['parameters']): ParamRow[] {
    if (!params || params.length === 0) return [];
    return params.map((p) => ({
        _key: `param-${paramKeySeq++}`,
        name: p.name,
        location: p.location,
        source: p.source,
        type: p.type,
        required: p.required,
        value: p.value ?? '',
        secret_ref: p.secret_ref ?? '',
        description: p.description ?? '',
    }));
}

function initialEndpointChoice(route: ApiRoute | null | undefined): EndpointTypeChoice {
    if (route?.endpoint_type_locked && route.endpoint_type !== 'unknown') return route.endpoint_type;
    return 'auto';
}

function isFullUrl(v: string): boolean {
    return /^https?:\/\//i.test(v);
}

export interface RouteWorkspaceProps {
    connector: ApiConnector;
    route: ApiRoute | null;
    onClose: () => void;
    onSaved?: () => void;
}

export function RouteWorkspace({ connector, route, onClose, onSaved }: RouteWorkspaceProps) {
    const toast = useToast();
    const base = (connector.base_url ?? '').replace(/\/$/, '');

    const isEdit = !!route;
    const [name, setName] = useState(route?.name ?? '');
    const [slug, setSlug] = useState(route?.slug ?? '');
    const [description, setDescription] = useState(route?.description ?? '');
    const [method, setMethod] = useState<HttpMethod>(route?.http_method ?? 'GET');
    // The design splits base (connector) + path; we keep a single editable value
    // that is a path under the base, or a full URL when it doesn't match.
    const [path, setPath] = useState(() => {
        const u = route?.url ?? '';
        return base && u.startsWith(base) ? u.slice(base.length) : u;
    });
    const [authProfileId, setAuthProfileId] = useState<string>(() => {
        const id = route ? route.auth_profile_id : connector.default_auth_profile_id;
        return id != null ? String(id) : '';
    });
    const [endpointType, setEndpointType] = useState<EndpointTypeChoice>(initialEndpointChoice(route));
    const [itemsPath, setItemsPath] = useState(route?.items_path ?? '');
    const [pagination, setPagination] = useState<PaginationConfig | null>(route?.pagination ?? null);
    const [params, setParams] = useState<ParamRow[]>(() => toRows(route?.parameters));
    const [timeoutMs, setTimeoutMs] = useState(route?.timeout_ms != null ? String(route.timeout_ms) : '');
    const [cacheTtlS, setCacheTtlS] = useState(route?.cache_ttl_s != null ? String(route.cache_ttl_s) : '');
    const [rateLimit, setRateLimit] = useState(route?.rate_limit != null ? String(route.rate_limit) : '');

    const [dirty, setDirty] = useState(false);
    const [applied, setApplied] = useState<Record<string, boolean>>({});
    const flashTimer = useRef<number | undefined>(undefined);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

    // Right console
    const [openApiUrl, setOpenApiUrl] = useState('');
    const [exampleArgs, setExampleArgs] = useState('{}');
    const [argsError, setArgsError] = useState<string | null>(null);
    const [view, setView] = useState<'struct' | 'raw' | 'search'>('struct');
    const [searchQuery, setSearchQuery] = useState('');

    const createRoute = useCreateRoute();
    const updateRoute = useUpdateRoute();
    const testMutation = useTestRoute();
    const analyzeMutation = useAnalyzeRoute();
    const applyAiMutation = useApplyAiConfigure();
    const detectMutation = useDetectPagination();

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    useEffect(() => () => window.clearTimeout(flashTimer.current), []);

    const fullUrl = base && path && !isFullUrl(path) ? base + (path.startsWith('/') ? path : `/${path}`) : path;

    function markDirty() {
        setDirty(true);
    }

    function flash(keys: string[]) {
        const map: Record<string, boolean> = {};
        keys.forEach((k) => (map[k] = true));
        setApplied(map);
        window.clearTimeout(flashTimer.current);
        flashTimer.current = window.setTimeout(() => setApplied({}), 1600);
    }

    function updateParam(key: string, patch: Partial<ParamRow>) {
        setParams((rows) => rows.map((r) => (r._key === key ? { ...r, ...patch } : r)));
        markDirty();
    }

    function removeParam(key: string) {
        setParams((rows) => rows.filter((r) => r._key !== key));
        markDirty();
    }

    function addParam() {
        setParams((rows) => [
            ...rows,
            { _key: `param-${paramKeySeq++}`, name: '', location: 'query', source: 'llm', type: 'string', required: false, value: '', description: '' },
        ]);
        markDirty();
    }

    function parseIntOrNull(raw: string): number | null {
        const t = raw.trim();
        if (t === '') return null;
        const n = Number.parseInt(t, 10);
        return Number.isNaN(n) ? null : n;
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

    function buildPayload(): RoutePayload {
        const parameters: RouteParameterInput[] = params.map((p, idx) => ({
            name: p.name.trim(),
            location: p.location,
            source: p.source,
            type: p.type,
            required: p.required,
            value: p.value?.trim() === '' ? null : p.value,
            secret_ref: p.secret_ref?.trim() === '' ? null : p.secret_ref,
            description: p.description?.trim() === '' ? null : p.description,
            sort_order: idx,
        }));
        return {
            name: name.trim(),
            slug: slug.trim() === '' ? null : slug.trim(),
            description: description.trim() === '' ? null : description.trim(),
            http_method: method,
            url: fullUrl.trim(),
            auth_profile_id: authProfileId === '' ? null : Number.parseInt(authProfileId, 10),
            mode: 'tool',
            endpoint_type: endpointType,
            items_path: endpointType === 'list' ? itemsPath.trim() : endpointType === 'detail' ? null : undefined,
            pagination,
            timeout_ms: parseIntOrNull(timeoutMs),
            cache_ttl_s: parseIntOrNull(cacheTtlS),
            rate_limit: parseIntOrNull(rateLimit),
            parameters,
        };
    }

    function handleSave() {
        setSubmitError(null);
        setFieldErrors({});
        const payload = buildPayload();
        const onError = (e: unknown) => {
            const { message, fieldErrors: fe } = toAdminError(e);
            setSubmitError(message);
            setFieldErrors(fe);
        };
        if (route) {
            updateRoute.mutate(
                { routeId: route.id, payload },
                { onSuccess: () => { setDirty(false); toast.success('Rotta salvata.', 'toast-api-route-updated'); onSaved?.(); onClose(); }, onError },
            );
        } else {
            createRoute.mutate(
                { connectorId: connector.id, payload },
                { onSuccess: () => { toast.success('Rotta creata.', 'toast-api-route-created'); onSaved?.(); onClose(); }, onError },
            );
        }
    }

    // Right console actions (need a saved route id).
    const routeId = route?.id ?? null;

    function runTest() {
        if (routeId === null) return;
        const args = parseArgs();
        if (!args) return;
        testMutation.mutate({ routeId, exampleArgs: args });
    }

    function runAnalyze() {
        if (routeId === null) return;
        const args = parseArgs();
        if (!args) return;
        analyzeMutation.mutate({ routeId, exampleArgs: args });
    }

    // Deterministic (heuristic) pagination detection — no AI. Fills the left card.
    function runDetectPagination() {
        if (routeId === null) return;
        const args = parseArgs();
        if (!args) return;
        detectMutation.mutate(
            { routeId, exampleArgs: args },
            {
                onSuccess: (res) => {
                    if (!res.config) return;
                    setPagination(res.config);
                    markDirty();
                    flash(['pag']);
                },
            },
        );
    }

    function runAiConfigure() {
        if (routeId === null) return;
        const args = parseArgs();
        if (!args) return;
        applyAiMutation.mutate(
            { routeId, exampleArgs: args, openApiUrl: openApiUrl.trim() || undefined },
            {
                onSuccess: (res) => {
                    if (!res.applied) return;
                    applySuggestionToForm(res.applied);
                    toast.success('Configurato con AI + test finale eseguito.', 'toast-api-route-ai-configured');
                },
            },
        );
    }

    function applySuggestionToForm(s: AiConfigureSuggestion) {
        const touched: string[] = [];
        if (s.endpoint_type && s.endpoint_type !== 'unknown') {
            setEndpointType(s.endpoint_type);
            touched.push('type');
        }
        if (s.items_path != null) {
            setItemsPath(s.items_path);
            touched.push('items');
        }
        if (s.pagination) {
            setPagination(s.pagination);
            touched.push('pag');
        }
        if (s.tool_description) {
            setDescription(s.tool_description);
            touched.push('desc');
        }
        if (s.tool_name) {
            setSlug(s.tool_name);
            touched.push('name');
        }
        if (s.parameters.length > 0) {
            setParams(toRows(s.parameters as ApiRoute['parameters']));
            touched.push('params');
        }
        setDirty(true);
        flash(touched);
    }

    const test = testMutation.data ?? null;
    const analyze = analyzeMutation.data ?? null;
    const applyAi = applyAiMutation.data ?? null;
    const testError =
        (testMutation.isError && toAdminError(testMutation.error).message) ||
        (analyzeMutation.isError && toAdminError(analyzeMutation.error).message) ||
        (applyAiMutation.isError && toAdminError(applyAiMutation.error).message) ||
        null;

    const hasResponse = test !== null || analyze !== null;
    const rawBody = test?.test.body ?? applyAi?.final_test.body ?? null;
    const structText = analyze?.reduced != null ? prettyJson(analyze.reduced) : test?.output_schema != null ? prettyJson(test.output_schema) : null;

    const responseText = useMemo(() => {
        if (view === 'struct') return structText ?? '';
        const raw = rawBody == null ? '' : prettyJson(rawBody);
        if (view === 'search' && searchQuery.trim()) {
            const q = searchQuery.trim().toLowerCase();
            return raw.split('\n').filter((l) => l.toLowerCase().includes(q)).join('\n') || `// nessuna riga corrisponde a “${searchQuery}”`;
        }
        return raw;
    }, [view, structText, rawBody, searchQuery]);

    const running = testMutation.isPending || analyzeMutation.isPending;
    const aiRunning = applyAiMutation.isPending;
    const itemCount = Array.isArray(rawBody) ? rawBody.length : null;

    return (
        <div data-testid="api-route-form-backdrop" onClick={(e) => e.target === e.currentTarget && onClose()} style={modalBackdropStyle()}>
            <div
                role="dialog"
                aria-modal="true"
                aria-label={isEdit ? `Edit route: ${route?.name}` : 'New route'}
                data-testid="api-route-form"
                style={panelStyle}
            >
                {/* Header */}
                <div style={headerStyle}>
                    <div style={connectorIconStyle}>{gearIcon}</div>
                    <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ fontSize: 16, fontWeight: 600, color: 'var(--fg-0)' }}>{isEdit ? 'Edit route' : 'New route'}</div>
                        <div style={{ fontSize: 12, color: 'var(--fg-3)', marginTop: 1 }}>
                            {connector.name} · <span style={{ fontFamily: mono, color: 'var(--fg-1)' }}>{name || 'rotta'}</span>
                        </div>
                    </div>
                    <span data-testid="api-route-workspace-status" style={statusPill(dirty)}>
                        <span style={{ width: 6, height: 6, borderRadius: 999, background: dirty ? 'var(--warn, #fbbf24)' : 'var(--ok, #34d399)' }} />
                        {dirty ? 'Modifiche non salvate' : 'Salvata'}
                    </span>
                    <button type="button" aria-label="Close" data-testid="api-route-form-cancel" onClick={onClose} style={iconBtn}>
                        {closeIcon}
                    </button>
                </div>

                {/* Two panes */}
                <div style={{ flex: 1, display: 'flex', minHeight: 0 }}>
                    {/* LEFT — definition */}
                    <div className="om-scroll" style={leftPaneStyle}>
                        <Section title="Identità">
                            <Field label="Name" required flash={applied.name} error={fieldErrors.name} errorTestId="api-route-form-name-error">
                                <input data-testid="api-route-form-name" value={name} onChange={(e) => { setName(e.target.value); markDirty(); }} style={inputStyleT} />
                            </Field>
                            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                                <Field label="Slug" note="— opz.">
                                    <input data-testid="api-route-form-slug" value={slug} onChange={(e) => { setSlug(e.target.value); markDirty(); }} style={monoInput} />
                                </Field>
                                <Field label="Mode">
                                    <div style={{ position: 'relative' }}>
                                        <select data-testid="api-route-form-mode" disabled value="tool" style={selectStyleT}>
                                            <option value="tool">Tool</option>
                                            <option disabled>Ingest — Fase 2</option>
                                            <option disabled>Both — Fase 2</option>
                                        </select>
                                        <span style={caretWrap}>{caret}</span>
                                    </div>
                                </Field>
                            </div>
                            <Field label="Description" note="— usata nella tool definition" flash={applied.desc}>
                                <textarea
                                    data-testid="api-route-form-description"
                                    rows={2}
                                    value={description}
                                    onChange={(e) => { setDescription(e.target.value); markDirty(); }}
                                    placeholder="Descrivi cosa restituisce la rotta, così l'agent sa quando chiamarla…"
                                    style={textareaStyle}
                                />
                            </Field>
                        </Section>

                        <Section title="Richiesta">
                            <div style={{ display: 'grid', gridTemplateColumns: '120px 1fr', gap: 12 }}>
                                <Field label="Method" required>
                                    <div style={{ position: 'relative' }}>
                                        <select data-testid="api-route-form-http_method" value={method} onChange={(e) => { setMethod(e.target.value as HttpMethod); markDirty(); }} style={selectStyleT}>
                                            {HTTP_METHODS.map((m) => <option key={m} value={m}>{m}</option>)}
                                        </select>
                                        <span style={caretWrap}>{caret}</span>
                                    </div>
                                </Field>
                                <Field label="Endpoint path" required error={fieldErrors.url} errorTestId="api-route-form-url-error">
                                    <div style={pathWrap}>
                                        {base && !isFullUrl(path) && (
                                            <span title={base} style={pathPrefix}>{base}</span>
                                        )}
                                        <input data-testid="api-route-form-url" value={path} onChange={(e) => { setPath(e.target.value); markDirty(); }} style={pathInput} />
                                    </div>
                                </Field>
                            </div>
                            <Field label="Auth profile" note="— come autenticare la chiamata">
                                <div style={{ position: 'relative' }}>
                                    <select data-testid="api-route-form-auth_profile_id" value={authProfileId} onChange={(e) => { setAuthProfileId(e.target.value); markDirty(); }} style={selectStyleT}>
                                        <option value="">Nessuno (chiamata anonima)</option>
                                        {(connector.auth_profiles ?? []).map((p) => (
                                            <option key={p.id} value={p.id}>{p.type} (#{p.id}){p.has_credentials ? '' : ' — no credenziali'}</option>
                                        ))}
                                    </select>
                                    <span style={caretWrap}>{caret}</span>
                                </div>
                            </Field>
                        </Section>

                        <Section title="Mappatura risposta">
                            <div style={applied.type ? flashBox : undefined}>
                                <div id="api-route-form-endpoint_type-caption" style={labelStyle}>Endpoint type</div>
                                <div role="radiogroup" aria-labelledby="api-route-form-endpoint_type-caption" data-testid="api-route-form-endpoint_type" style={segmentWrap}>
                                    {ENDPOINT_TYPE_OPTIONS.map((o) => (
                                        <button
                                            key={o.value}
                                            type="button"
                                            role="radio"
                                            aria-checked={endpointType === o.value}
                                            data-testid={`api-route-form-endpoint_type-${o.value}`}
                                            onClick={() => { setEndpointType(o.value); markDirty(); }}
                                            style={segmentBtn(endpointType === o.value)}
                                        >
                                            {o.label}
                                        </button>
                                    ))}
                                </div>
                                <div style={hintStyle}>{(ENDPOINT_TYPE_OPTIONS.find((o) => o.value === endpointType) ?? ENDPOINT_TYPE_OPTIONS[0]).hint}</div>
                            </div>

                            {(endpointType === 'list' || endpointType === 'auto') && (
                                <>
                                    <Field label="Items path" note="— dot-path all'array (vuoto = array top-level)" flash={applied.items}>
                                        <input data-testid="api-route-form-items_path" value={itemsPath} onChange={(e) => { setItemsPath(e.target.value); markDirty(); }} placeholder="es. data.items" style={monoInput} />
                                    </Field>
                                    <PaginationCard
                                        pagination={pagination}
                                        setPagination={(p) => { setPagination(p); markDirty(); }}
                                        flash={applied.pag}
                                        onDetect={routeId !== null ? runDetectPagination : undefined}
                                        detecting={detectMutation.isPending}
                                        detectSource={detectMutation.data?.source ?? null}
                                    />
                                </>
                            )}
                        </Section>

                        <div style={applied.params ? flashBox : undefined}>
                            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
                                <div style={{ ...groupHead, margin: 0 }}>
                                    Parametri <span style={{ color: 'var(--fg-3)', fontWeight: 600 }}>({params.length})</span>
                                </div>
                                <button type="button" data-testid="api-route-form-param-add" onClick={addParam} style={miniBtn}>+ Add parameter</button>
                            </div>
                            {params.length === 0 ? (
                                <div data-testid="api-route-form-params-empty" style={emptyBox}>Nessun parametro. La rotta è chiamata così com'è.</div>
                            ) : (
                                <div style={{ border: '1px solid var(--hairline)', borderRadius: 11, overflow: 'hidden', background: 'var(--bg-2)' }}>
                                    {params.map((p, i) => (
                                        <div key={p._key} style={paramRow}>
                                            <input aria-label={`Parameter ${i + 1} name`} data-testid={`api-route-form-param-${i}-name`} value={p.name} onChange={(e) => updateParam(p._key, { name: e.target.value })} placeholder="name" style={{ ...monoInputSm, flex: 1 }} />
                                            <CompactSelect ariaLabel={`Parameter ${i + 1} location`} testid={`api-route-form-param-${i}-location`} value={p.location} onChange={(v) => updateParam(p._key, { location: v as ParamLocation })} options={PARAM_LOCATIONS} />
                                            <CompactSelect ariaLabel={`Parameter ${i + 1} source`} testid={`api-route-form-param-${i}-source`} value={p.source} onChange={(v) => updateParam(p._key, { source: v as ParamSource })} options={PARAM_SOURCES} />
                                            <CompactSelect ariaLabel={`Parameter ${i + 1} type`} testid={`api-route-form-param-${i}-type`} value={p.type ?? 'string'} onChange={(v) => updateParam(p._key, { type: v as ParamType })} options={PARAM_TYPES} />
                                            <button type="button" aria-label={`Remove parameter ${i + 1}`} data-testid={`api-route-form-param-${i}-remove`} onClick={() => removeParam(p._key)} style={removeBtn}>{closeIconSm}</button>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>

                        <Section title="Avanzate">
                            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 10 }}>
                                <Field label="Timeout" note="ms"><input data-testid="api-route-form-timeout_ms" value={timeoutMs} onChange={(e) => { setTimeoutMs(e.target.value); markDirty(); }} placeholder="default" style={inputStyleT} /></Field>
                                <Field label="Cache TTL" note="s"><input data-testid="api-route-form-cache_ttl_s" value={cacheTtlS} onChange={(e) => { setCacheTtlS(e.target.value); markDirty(); }} placeholder="0" style={inputStyleT} /></Field>
                                <Field label="Rate limit" note="/min"><input data-testid="api-route-form-rate_limit" value={rateLimit} onChange={(e) => { setRateLimit(e.target.value); markDirty(); }} placeholder="0" style={inputStyleT} /></Field>
                            </div>
                        </Section>
                    </div>

                    {/* RIGHT — Prova & esplora */}
                    <div className="om-scroll" data-testid="api-route-wb" style={rightPaneStyle}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <div style={{ ...groupHead, margin: 0 }}>Prova &amp; esplora</div>
                            <div style={{ flex: 1 }} />
                            <span style={{ fontSize: 11, color: 'var(--fg-3)' }}>i risultati aggiornano la definizione a sinistra</span>
                        </div>

                        <div style={requestLine}>
                            <span style={methodChip}>{method}</span>
                            <span style={{ flex: 1, minWidth: 0, fontFamily: mono, fontSize: 12, color: 'var(--fg-1)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{fullUrl}</span>
                        </div>

                        {routeId === null ? (
                            <div style={{ ...emptyBox, padding: 16 }}>Salva la rotta per testarla e usare “Configura con AI”.</div>
                        ) : (
                            <>
                                {/* AI assist */}
                                <div style={aiCard}>
                                    <div style={{ display: 'flex', alignItems: 'flex-start', gap: 11 }}>
                                        <div style={aiIcon}>{sparkIcon}</div>
                                        <div style={{ flex: 1, minWidth: 0 }}>
                                            <div style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--fg-0)' }}>Configura con AI</div>
                                            <div style={{ fontSize: 12, color: 'var(--fg-2)', lineHeight: 1.5, marginTop: 2 }}>
                                                Un click: rileva tipo, items path, paginazione, nome/descrizione e parametri, li <b>applica alla definizione</b> e fa il test finale.
                                            </div>
                                        </div>
                                    </div>
                                    <label htmlFor="api-route-wb-openapi-url" style={{ display: 'block', marginTop: 12 }}>
                                        <span style={{ ...labelSm, color: 'var(--fg-2)' }}>Link OpenAPI <span style={{ opacity: 0.7 }}>— opzionale, legge tutto dal contratto</span></span>
                                        <input id="api-route-wb-openapi-url" data-testid="api-route-wb-openapi-url" type="url" value={openApiUrl} onChange={(e) => setOpenApiUrl(e.target.value)} placeholder="https://api.example.com/openapi.json" style={inputStyleT} />
                                    </label>
                                    <div style={{ display: 'flex', gap: 9, marginTop: 12 }}>
                                        <button type="button" data-testid="api-route-wb-ai-configure-run" disabled={aiRunning} onClick={runAiConfigure} style={aiBtn(aiRunning)}>
                                            {aiRunning ? 'Analisi in corso…' : 'Configura con AI'}
                                        </button>
                                        <button type="button" data-testid="api-route-wb-analyze-run" onClick={runAnalyze} style={ghostBtn}>Solo descrivi la struttura</button>
                                    </div>
                                    {applyAi?.applied && (
                                        <div data-testid="api-route-wb-ai-applied" style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 10 }}>
                                            <span data-testid="api-route-wb-ai-source" style={pill('accent')}>{applyAi.source === 'openapi' ? 'da OpenAPI' : 'da risposta'}</span>
                                            <span data-testid="api-route-wb-ai-final-test" style={pill(applyAi.final_test.ok ? 'ok' : 'err')}>Test finale: {applyAi.final_test.ok ? 'OK' : 'Fallito'} — HTTP {applyAi.final_test.status ?? '—'}</span>
                                            {applyAi.pagination_test && <span data-testid="api-route-wb-ai-pagination-verdict" style={pill(applyAi.pagination_test.distinct ? 'ok' : 'err')}>Paginazione: {applyAi.pagination_test.distinct ? 'distinte ✓' : 'non avanza'}</span>}
                                        </div>
                                    )}
                                </div>

                                {/* Example args */}
                                <div>
                                    <label htmlFor="api-route-wb-example-args" style={{ display: 'block' }}>
                                        <span style={labelStyle}>Example args <span style={{ color: 'var(--fg-3)', fontWeight: 400 }}>— JSON, i valori dei parametri LLM</span></span>
                                        <textarea id="api-route-wb-example-args" data-testid="api-route-wb-example-args" rows={3} value={exampleArgs} onChange={(e) => setExampleArgs(e.target.value)} spellCheck={false} style={codeArea} />
                                    </label>
                                    {argsError && <span data-testid="api-route-wb-example-args-error" style={{ fontSize: 12, color: 'var(--err, #fca5a5)' }}>{argsError}</span>}
                                </div>

                                <div style={{ display: 'flex', gap: 9, alignItems: 'center' }}>
                                    <button type="button" data-testid="api-route-wb-test-run" disabled={running} onClick={runTest} style={primaryBtn(running)}>
                                        {testMutation.isPending ? 'Chiamata in corso…' : 'Testa chiamata'}
                                    </button>
                                    {test && (
                                        <span data-testid="api-route-wb-test-result" data-ok={test.test.ok} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, fontWeight: 600, color: test.test.ok ? 'var(--ok, #34d399)' : 'var(--err, #fca5a5)' }}>
                                            <span data-testid="api-route-wb-test-endpoint-type" data-endpoint-type={test.endpoint_type} style={pill('accent')}>{test.endpoint_type}</span>
                                            HTTP {test.test.status ?? '—'} {test.test.status_label}{itemCount != null ? ` · ${itemCount} elementi` : ''}
                                        </span>
                                    )}
                                </div>

                                {testError && <div data-testid="api-route-wb-test-error" role="alert" style={alertBox}>{testError}</div>}
                            </>
                        )}

                        {/* Response viewer */}
                        <div style={{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
                                <div style={tabsWrap}>
                                    {([['struct', 'Struttura'], ['raw', 'JSON'], ['search', 'Cerca']] as const).map(([id, label]) => (
                                        <button key={id} type="button" data-testid={`api-route-wb-tab-${id}`} aria-selected={view === id} onClick={() => setView(id)} style={tabBtn(view === id)}>{label}</button>
                                    ))}
                                </div>
                                <div style={{ flex: 1 }} />
                                {view === 'search' && (
                                    <input aria-label="Filtra il JSON" data-testid="api-route-wb-search" value={searchQuery} onChange={(e) => setSearchQuery(e.target.value)} placeholder="Filtra il JSON…" style={{ ...inputStyleT, width: 200, fontSize: 12.5 }} />
                                )}
                            </div>
                            {hasResponse ? (
                                <pre className="om-scroll" data-testid="api-route-wb-response" style={preStyle}>{responseText}</pre>
                            ) : (
                                <div style={viewerEmpty}>
                                    Esegui <b>Testa chiamata</b> o <b>Configura con AI</b> per vedere la risposta e la sua struttura.
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {/* Footer */}
                <div style={footerStyle}>
                    <span style={{ flex: 1, fontSize: 12, color: 'var(--fg-3)' }}>
                        {submitError ? <span data-testid="api-route-form-error" role="alert" style={{ color: 'var(--err, #fca5a5)' }}>{submitError}</span> : dirty ? 'Modifiche non salvate.' : 'Tutto salvato.'}
                    </span>
                    <button type="button" onClick={onClose} style={ghostBtn2}>Cancel</button>
                    <button type="button" data-testid="api-route-form-submit" disabled={createRoute.isPending || updateRoute.isPending} onClick={handleSave} style={saveBtn}>
                        {createRoute.isPending || updateRoute.isPending ? 'Salvo…' : isEdit ? 'Save changes' : 'Create route'}
                    </button>
                </div>
            </div>
        </div>
    );
}

/* ── small presentational helpers ─────────────────────────────────────────── */

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div>
            <div style={groupHead}>{title}</div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>{children}</div>
        </div>
    );
}

function Field({
    label,
    required,
    note,
    flash,
    error,
    errorTestId,
    children,
}: {
    label: string;
    required?: boolean;
    note?: string;
    flash?: boolean;
    error?: string;
    errorTestId?: string;
    children: ReactNode;
}) {
    // The <label> wraps its single control, so the visible caption is
    // programmatically associated with it (R15) without threading ids.
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
            {error && (
                <span data-testid={errorTestId} role="alert" style={{ display: 'block', marginTop: 5, fontSize: 12, color: 'var(--err, #fca5a5)' }}>
                    {error}
                </span>
            )}
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

function PaginationCard({
    pagination,
    setPagination,
    flash,
    onDetect,
    detecting,
    detectSource,
}: {
    pagination: PaginationConfig | null;
    setPagination: (p: PaginationConfig | null) => void;
    flash?: boolean;
    onDetect?: () => void;
    detecting?: boolean;
    detectSource?: 'heuristic' | 'ai' | 'none' | null;
}) {
    const on = pagination != null && pagination.type !== 'none';
    const toggle = () => setPagination(on ? { type: 'none' } : { type: 'cursor' });
    const set = (patch: Partial<PaginationConfig>) => setPagination({ ...(pagination ?? { type: 'cursor' }), ...patch });
    return (
        <div style={{ ...(flash ? flashBox : {}), border: '1px solid var(--hairline)', borderRadius: 11, background: 'var(--bg-2)', overflow: 'hidden' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '11px 13px' }}>
                <button type="button" data-testid="api-route-form-pagination-toggle" onClick={toggle} style={{ flex: 1, display: 'flex', alignItems: 'center', gap: 10, background: 'transparent', border: 'none', padding: 0, cursor: 'pointer', textAlign: 'left' }}>
                    <span style={{ flex: 1, fontSize: 13, fontWeight: 600, color: 'var(--fg-1)' }}>Paginazione</span>
                    {on && <span style={pill('accent')}>{pagination?.type}</span>}
                    <span style={knobWrap(on)}><span style={knob(on)} /></span>
                </button>
                {onDetect && (
                    <button type="button" data-testid="api-route-form-pagination-detect" disabled={detecting} onClick={onDetect} style={miniBtn}>
                        {detecting ? 'Rilevo…' : 'Rileva'}
                    </button>
                )}
            </div>
            {detectSource && detectSource !== 'none' && (
                <div data-testid="api-route-form-pagination-source" style={{ padding: '0 13px 8px', fontSize: 11.5, color: 'var(--fg-3)' }}>
                    Rilevata ({detectSource === 'ai' ? 'AI' : 'euristica'}).
                </div>
            )}
            {on && pagination && (
                <div style={{ padding: '2px 13px 14px', display: 'flex', flexDirection: 'column', gap: 12, borderTop: '1px solid var(--hairline)' }}>
                    <label style={{ display: 'block', marginTop: 12 }}>
                        <span style={labelSm}>Tipo</span>
                        <div style={{ position: 'relative' }}>
                            <select data-testid="api-route-form-pagination-type" value={pagination.type} onChange={(e) => set({ type: e.target.value as PaginationConfig['type'] })} style={selectStyleT}>
                                <option value="cursor">Cursore / token</option>
                                <option value="page">Numero pagina</option>
                            </select>
                            <span style={caretWrap}>{caret}</span>
                        </div>
                    </label>
                    {pagination.type === 'cursor' ? (
                        <>
                            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
                                <label style={{ display: 'block' }}><span style={labelSm}>Param cursore</span><input data-testid="api-route-form-pagination-cursor_param" value={pagination.cursor_param ?? ''} onChange={(e) => set({ cursor_param: e.target.value })} placeholder="cursor" style={monoInputSm} /></label>
                                <label style={{ display: 'block' }}><span style={labelSm}>Next-cursor path</span><input data-testid="api-route-form-pagination-next_cursor_path" value={pagination.next_cursor_path ?? ''} onChange={(e) => set({ next_cursor_path: e.target.value })} placeholder="meta.next_cursor" style={monoInputSm} /></label>
                            </div>
                            <label style={{ display: 'block' }}><span style={labelSm}>Next-URL path <span style={{ color: 'var(--fg-3)', fontWeight: 400 }}>— opz.</span></span><input data-testid="api-route-form-pagination-next_url_path" value={pagination.next_url_path ?? ''} onChange={(e) => set({ next_url_path: e.target.value })} placeholder="links.next" style={monoInputSm} /></label>
                        </>
                    ) : (
                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
                            <label style={{ display: 'block' }}><span style={labelSm}>Param pagina</span><input data-testid="api-route-form-pagination-page_param" value={pagination.page_param ?? ''} onChange={(e) => set({ page_param: e.target.value })} placeholder="page" style={monoInputSm} /></label>
                            <label style={{ display: 'block' }}><span style={labelSm}>Param dimensione</span><input data-testid="api-route-form-pagination-size_param" value={pagination.size_param ?? ''} onChange={(e) => set({ size_param: e.target.value })} placeholder="per_page" style={monoInputSm} /></label>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

/* ── styles (theme tokens) + icons ────────────────────────────────────────── */

const mono = 'var(--mono, ui-monospace, monospace)';
const ACCENT = '#8b5cf6';
const ACCENT_GRAD = 'linear-gradient(135deg,#6366f1,#8b5cf6)';

const panelStyle: CSSProperties = { width: 1180, maxWidth: '100%', height: 840, maxHeight: '94vh', display: 'flex', flexDirection: 'column', background: 'var(--bg-1)', border: '1px solid var(--hairline)', borderRadius: 16, boxShadow: '0 24px 70px rgba(0,0,0,.5)', overflow: 'hidden' };
const headerStyle: CSSProperties = { flex: 'none', padding: '16px 20px', borderBottom: '1px solid var(--hairline)', display: 'flex', alignItems: 'center', gap: 12 };
const connectorIconStyle: CSSProperties = { flex: 'none', width: 36, height: 36, borderRadius: 10, background: '#2684FF', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center' };
const leftPaneStyle: CSSProperties = { width: 520, flex: 'none', overflowY: 'auto', borderRight: '1px solid var(--hairline)', padding: '18px 20px 24px', display: 'flex', flexDirection: 'column', gap: 20 };
const rightPaneStyle: CSSProperties = { flex: 1, minWidth: 0, overflowY: 'auto', background: 'var(--bg-2)', padding: '18px 20px 24px', display: 'flex', flexDirection: 'column', gap: 16 };
const footerStyle: CSSProperties = { flex: 'none', display: 'flex', alignItems: 'center', gap: 10, padding: '13px 20px', borderTop: '1px solid var(--hairline)', background: 'var(--bg-1)' };

const groupHead: CSSProperties = { fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.07em', color: 'var(--fg-3)', marginBottom: 12 };
const labelStyle: CSSProperties = { display: 'block', fontSize: 12.5, fontWeight: 600, color: 'var(--fg-1)', marginBottom: 6 };
const labelSm: CSSProperties = { display: 'block', fontSize: 11.5, fontWeight: 600, color: 'var(--fg-2)', marginBottom: 5 };
const hintStyle: CSSProperties = { fontSize: 11.5, color: 'var(--fg-3)', marginTop: 6, lineHeight: 1.45 };

const inputBase: CSSProperties = { width: '100%', background: 'var(--bg-2)', border: '1px solid var(--hairline)', borderRadius: 9, color: 'var(--fg-0)', font: 'inherit', fontSize: 13.5, padding: '10px 12px', outline: 'none' };
const inputStyleT: CSSProperties = inputBase;
const monoInput: CSSProperties = { ...inputBase, fontFamily: mono, fontSize: 12.5 };
const monoInputSm: CSSProperties = { ...inputBase, fontFamily: mono, fontSize: 12, padding: '8px 10px' };
const textareaStyle: CSSProperties = { ...inputBase, resize: 'vertical', lineHeight: 1.5, minHeight: 58 };
const codeArea: CSSProperties = { ...inputBase, background: 'var(--bg-3, var(--bg-2))', fontFamily: mono, fontSize: 12.5, lineHeight: 1.6, resize: 'vertical', minHeight: 68 };
const selectStyleT: CSSProperties = { ...inputBase, paddingRight: 34, cursor: 'pointer' };
const compactSelect: CSSProperties = { width: 92, background: 'var(--bg-1)', border: '1px solid var(--hairline)', borderRadius: 7, color: 'var(--fg-1)', font: 'inherit', fontSize: 12, padding: '7px 22px 7px 9px', outline: 'none', cursor: 'pointer', appearance: 'none' };

const caretWrap: CSSProperties = { position: 'absolute', right: 12, top: '50%', transform: 'translateY(-50%)', pointerEvents: 'none', display: 'flex' };
const caretWrapSm: CSSProperties = { position: 'absolute', right: 7, top: '50%', transform: 'translateY(-50%)', pointerEvents: 'none', display: 'flex' };

const pathWrap: CSSProperties = { display: 'flex', alignItems: 'stretch', background: 'var(--bg-2)', border: '1px solid var(--hairline)', borderRadius: 9, overflow: 'hidden' };
const pathPrefix: CSSProperties = { flex: 'none', maxWidth: '52%', display: 'flex', alignItems: 'center', padding: '0 4px 0 11px', background: 'var(--bg-1)', borderRight: '1px solid var(--hairline)', fontFamily: mono, fontSize: 12, color: 'var(--fg-3)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', direction: 'rtl' };
const pathInput: CSSProperties = { flex: 1, minWidth: 0, background: 'transparent', border: 'none', outline: 'none', color: 'var(--fg-0)', fontFamily: mono, fontSize: 12, padding: '10px 11px' };

const segmentWrap: CSSProperties = { display: 'flex', background: 'var(--bg-2)', border: '1px solid var(--hairline)', borderRadius: 10, padding: 3, gap: 2 };
const segmentBtn = (on: boolean): CSSProperties => ({ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', background: on ? 'var(--bg-3, var(--hairline))' : 'transparent', color: on ? 'var(--fg-0)' : 'var(--fg-3)', border: 'none', font: 'inherit', fontSize: 13, fontWeight: 600, padding: '8px 10px', borderRadius: 7, cursor: 'pointer' });

const paramRow: CSSProperties = { display: 'flex', alignItems: 'center', gap: 8, padding: '8px 10px', borderBottom: '1px solid var(--hairline)' };
const removeBtn: CSSProperties = { flex: 'none', width: 30, height: 30, borderRadius: 7, border: '1px solid transparent', background: 'transparent', color: 'var(--fg-3)', display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer' };
const emptyBox: CSSProperties = { border: '1px dashed var(--hairline)', borderRadius: 11, padding: 16, textAlign: 'center', color: 'var(--fg-3)', fontSize: 12.5 };
const flashBox: CSSProperties = { borderRadius: 11, padding: 4, margin: -4, animation: 'flash 1.6s ease both' };

const requestLine: CSSProperties = { display: 'flex', alignItems: 'center', gap: 9, background: 'var(--bg-1)', border: '1px solid var(--hairline)', borderRadius: 9, padding: '9px 12px' };
const methodChip: CSSProperties = { flex: 'none', fontSize: 11, fontWeight: 700, letterSpacing: '.03em', color: '#34d399', background: 'rgba(52,211,153,.12)', padding: '2px 8px', borderRadius: 6 };
const aiCard: CSSProperties = { border: `1px solid ${ACCENT}55`, borderRadius: 12, background: 'linear-gradient(180deg,rgba(139,92,246,.1),rgba(99,102,241,.04))', padding: 15 };
const aiIcon: CSSProperties = { flex: 'none', width: 32, height: 32, borderRadius: 9, background: ACCENT_GRAD, display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: '0 2px 10px rgba(99,102,241,.4)' };
const alertBox: CSSProperties = { padding: 10, fontSize: 12.5, background: 'rgba(239,68,68,.08)', border: '1px solid rgba(239,68,68,.3)', borderRadius: 8, color: 'var(--err, #fca5a5)' };
const viewerEmpty: CSSProperties = { flex: 1, minHeight: 200, display: 'flex', alignItems: 'center', justifyContent: 'center', textAlign: 'center', padding: 20, border: '1px dashed var(--hairline)', borderRadius: 10, color: 'var(--fg-3)', fontSize: 13 };
const preStyle: CSSProperties = { flex: 1, minHeight: 200, margin: 0, overflow: 'auto', background: 'var(--bg-3, var(--bg-2))', border: '1px solid var(--hairline)', borderRadius: 10, padding: 14, fontFamily: mono, fontSize: 12, lineHeight: 1.6, color: 'var(--fg-1)', whiteSpace: 'pre' };

const tabsWrap: CSSProperties = { display: 'flex', background: 'var(--bg-1)', border: '1px solid var(--hairline)', borderRadius: 8, padding: 3, gap: 2 };
const tabBtn = (on: boolean): CSSProperties => ({ background: on ? 'var(--bg-3, var(--hairline))' : 'transparent', color: on ? 'var(--fg-0)' : 'var(--fg-3)', border: 'none', font: 'inherit', fontSize: 12, fontWeight: 600, padding: '6px 12px', borderRadius: 6, cursor: 'pointer' });

const miniBtn: CSSProperties = { flex: 'none', background: 'var(--bg-2)', border: '1px solid var(--hairline)', color: 'var(--fg-1)', font: 'inherit', fontSize: 12, fontWeight: 600, padding: '6px 11px', borderRadius: 8, cursor: 'pointer' };
const iconBtn: CSSProperties = { flex: 'none', width: 32, height: 32, borderRadius: 8, border: '1px solid transparent', background: 'transparent', color: 'var(--fg-3)', display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer' };
const ghostBtn: CSSProperties = { border: '1px solid var(--hairline)', background: 'transparent', color: 'var(--fg-1)', font: 'inherit', fontSize: 12.5, fontWeight: 600, padding: '8px 13px', borderRadius: 9, cursor: 'pointer' };
const ghostBtn2: CSSProperties = { border: '1px solid var(--hairline)', background: 'transparent', color: 'var(--fg-1)', font: 'inherit', fontSize: 13, fontWeight: 600, padding: '9px 16px', borderRadius: 9, cursor: 'pointer' };
const primaryBtn = (busy: boolean): CSSProperties => ({ display: 'inline-flex', alignItems: 'center', gap: 7, border: 'none', background: ACCENT_GRAD, color: '#fff', font: 'inherit', fontSize: 13, fontWeight: 600, padding: '9px 16px', borderRadius: 9, cursor: busy ? 'default' : 'pointer', boxShadow: '0 2px 12px rgba(99,102,241,.4)', opacity: busy ? 0.8 : 1 });
const aiBtn = (busy: boolean): CSSProperties => ({ display: 'inline-flex', alignItems: 'center', gap: 8, border: 'none', background: ACCENT_GRAD, color: '#fff', font: 'inherit', fontSize: 13, fontWeight: 600, padding: '9px 15px', borderRadius: 9, cursor: busy ? 'default' : 'pointer', boxShadow: '0 2px 12px rgba(124,58,237,.4)', opacity: busy ? 0.8 : 1 });
const saveBtn: CSSProperties = { border: 'none', background: ACCENT_GRAD, color: '#fff', font: 'inherit', fontSize: 13, fontWeight: 600, padding: '9px 18px', borderRadius: 9, cursor: 'pointer', boxShadow: '0 2px 12px rgba(99,102,241,.4)' };

const statusPill = (dirty: boolean): CSSProperties => ({ flex: 'none', display: 'inline-flex', alignItems: 'center', gap: 6, padding: '3px 10px', borderRadius: 999, fontSize: 11.5, fontWeight: 600, color: dirty ? 'var(--warn, #fbbf24)' : 'var(--ok, #34d399)', background: dirty ? 'rgba(251,191,36,.13)' : 'rgba(52,211,153,.13)' });
function pill(kind: 'ok' | 'err' | 'accent'): CSSProperties {
    const map = { ok: ['#34d399', 'rgba(52,211,153,.14)'], err: ['#fca5a5', 'rgba(239,68,68,.14)'], accent: ['#a5b4fc', 'rgba(99,102,241,.16)'] } as const;
    const [fg, bg] = map[kind];
    return { fontSize: 11.5, fontWeight: 600, padding: '2px 8px', borderRadius: 999, color: fg, background: bg };
}
const knobWrap = (on: boolean): CSSProperties => ({ flex: 'none', width: 36, height: 21, borderRadius: 999, background: on ? ACCENT : 'var(--hairline)', position: 'relative', transition: 'background .15s' });
const knob = (on: boolean): CSSProperties => ({ position: 'absolute', top: 2, left: on ? 17 : 2, width: 17, height: 17, borderRadius: 999, background: '#fff', transition: 'left .15s' });

const gearIcon = (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3" /><path d="M21 8V5a2 2 0 0 0-2-2h-3" /><path d="M3 16v3a2 2 0 0 0 2 2h3" /><path d="M16 21h3a2 2 0 0 0 2-2v-3" /><circle cx="12" cy="12" r="3" /></svg>
);
const closeIcon = <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M18 6 6 18" /><path d="M6 6l12 12" /></svg>;
const closeIconSm = <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M18 6 6 18" /><path d="M6 6l12 12" /></svg>;
const caret = <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--fg-3)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="m6 9 6 6 6-6" /></svg>;
const caretSm = <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--fg-3)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="m6 9 6 6 6-6" /></svg>;
const sparkIcon = <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 3v2" /><path d="m19 5-1.5 1.5" /><path d="M21 12h-2" /><path d="M12 21v-2" /><path d="m5 19 1.5-1.5" /><path d="M3 12h2" /><path d="M5 5l1.5 1.5" /><circle cx="12" cy="12" r="3.5" /></svg>;
