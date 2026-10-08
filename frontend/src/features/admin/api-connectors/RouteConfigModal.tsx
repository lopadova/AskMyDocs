import { useEffect, useRef, useState, type ReactNode } from 'react';
import * as Dialog from '@radix-ui/react-dialog';
import * as Tabs from '@radix-ui/react-tabs';
import { Check, ChevronDown, Circle, Play, Plus, Save, Sparkles, X } from 'lucide-react';
import { Button } from '../../../components/Button';
import type {
    ApiConnector, ApiRoute, EndpointTypeChoice, HttpMethod, PaginationConfig,
    ParamLocation, ParamSource, ParamType, RouteConfig, RouteConfigParam,
} from './api-connectors.api';
import { useCreateRoute, useProduceConfig, useTestConfig, useTestRoute, useUpdateRoute } from './api-connectors-hooks';
import { blankParam, diffGroups, emptyConfig, joinUrl, mapConfigErrors, routeToConfig, splitUrl } from './route-config';
import { RouteTestResult, type RouteTestOutcome } from './RouteTestResult';
import { toAdminError } from '../shared/errors';
import { useToast } from '../shared/Toast';
import './route-config-modal.css';

const HTTP_METHODS: HttpMethod[] = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
const PARAM_LOCATIONS: ParamLocation[] = ['path', 'query', 'header', 'body'];
const PARAM_SOURCES: ParamSource[] = ['llm', 'fixed', 'secret'];
const PARAM_TYPES: ParamType[] = ['string', 'integer', 'number', 'boolean', 'array', 'object'];
const SOURCE_LABELS: Record<ParamSource, string> = { llm: 'Dal modello', fixed: 'Valore fisso', secret: 'Credenziale' };
const ENDPOINT_TYPES: { value: EndpointTypeChoice; label: string; hint: string }[] = [
    { value: 'auto', label: 'Automatico', hint: 'Rileva il tipo dai dati restituiti da un test riuscito.' },
    { value: 'list', label: 'Lista', hint: 'Restituisce una collezione di record.' },
    { value: 'detail', label: 'Dettaglio', hint: 'Restituisce un singolo record per identificatore.' },
];
type EditorTab = 'request' | 'params' | 'response' | 'test';

export interface RouteConfigModalProps {
    connector: ApiConnector;
    route: ApiRoute | null;
    onClose: () => void;
    onSaved?: () => void;
}

export function RouteConfigModal({ connector, route, onClose, onSaved }: RouteConfigModalProps) {
    const toast = useToast();
    const base = connector.base_url;
    // Retain a newly created route when its final test fails, so retrying Save updates it.
    const [savedRoute, setSavedRoute] = useState(route);
    const isEdit = !!savedRoute;
    const [config, setConfig] = useState<RouteConfig>(() => route ? routeToConfig(route) : emptyConfig(connector));
    const [dirty, setDirty] = useState(false);
    const [tab, setTab] = useState<EditorTab>('request');
    const [applied, setApplied] = useState<Record<string, boolean>>({});
    const flashTimer = useRef<number | undefined>(undefined);
    const [exampleArgs, setExampleArgs] = useState('{}');
    const [argsError, setArgsError] = useState<string | null>(null);
    const [openApiUrl, setOpenApiUrl] = useState('');
    const [testResult, setTestResult] = useState<RouteTestOutcome | null>(null);
    const [aiVerdict, setAiVerdict] = useState<{ source: string; ok: boolean; status: number | null } | null>(null);
    const [detectSource, setDetectSource] = useState<'response' | null>(null);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [saveNotice, setSaveNotice] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const createRoute = useCreateRoute();
    const updateRoute = useUpdateRoute();
    const testRoute = useTestRoute();
    const testConfig = useTestConfig();
    const produceConfig = useProduceConfig();
    const saving = createRoute.isPending || updateRoute.isPending || testRoute.isPending;
    const aiRunning = produceConfig.isPending;
    const testing = testConfig.isPending;
    const busy = saving || aiRunning || testing;
    const urlSplit = splitUrl(base, config.request.url);
    const testError = (testConfig.isError && toAdminError(testConfig.error).message) ||
        (produceConfig.isError && toAdminError(produceConfig.error).message) || null;

    useEffect(() => () => window.clearTimeout(flashTimer.current), []);

    function changed() { setDirty(true); setSaveNotice(null); setTestResult(null); }
    const patchIdentity = (p: Partial<RouteConfig['identity']>) => { setConfig(c => ({ ...c, identity: { ...c.identity, ...p } })); changed(); };
    const patchRequest = (p: Partial<RouteConfig['request']>) => { setConfig(c => ({ ...c, request: { ...c.request, ...p } })); changed(); };
    const patchResponse = (p: Partial<RouteConfig['response']>) => { setConfig(c => ({ ...c, response: { ...c.response, ...p } })); changed(); };
    const patchOptions = (p: Partial<RouteConfig['options']>) => { setConfig(c => ({ ...c, options: { ...c.options, ...p } })); changed(); };
    function updateParam(i: number, p: Partial<RouteConfigParam>) {
        patchRequest({ params: config.request.params.map((r, ix) => ix === i ? { ...r, ...p } : r) });
    }
    function flash(keys: (keyof RouteConfig)[]) {
        setApplied(Object.fromEntries(keys.map(k => [k, true])));
        window.clearTimeout(flashTimer.current);
        flashTimer.current = window.setTimeout(() => setApplied({}), 1400);
    }
    function parseArgs(): Record<string, unknown> | null {
        try {
            const parsed: unknown = JSON.parse(exampleArgs.trim() || '{}');
            if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
                setArgsError('Inserisci un oggetto JSON con i valori da usare nella prova.');
                return null;
            }
            setArgsError(null);
            return parsed as Record<string, unknown>;
        } catch { setArgsError('JSON non valido.'); return null; }
    }
    function runTest() {
        setTab('test');
        const args = parseArgs();
        if (!args) return;
        setTestResult(null);
        testConfig.mutate({ connectorId: connector.id, config, exampleArgs: args }, { onSuccess: setTestResult });
    }
    function runDetectPagination() {
        const args = parseArgs();
        if (!args) { setTab('test'); return; }
        testConfig.mutate({ connectorId: connector.id, config, exampleArgs: args }, {
            onSuccess: res => {
                if (res.test.ok && res.detected_pagination) {
                    patchResponse({ pagination: res.detected_pagination });
                    setDetectSource('response');
                    flash(['response']);
                } else { setTab('test'); }
                setTestResult(res);
            },
            onError: () => setTab('test'),
        });
    }
    function runAiConfigure() {
        const args = parseArgs();
        if (!args) { setTab('test'); return; }
        produceConfig.mutate({ connectorId: connector.id, config, exampleArgs: args, openApiUrl: openApiUrl.trim() || undefined }, {
            onSuccess: res => {
                setAiVerdict({ source: res.source, ok: res.final_test.ok, status: res.final_test.status });
                if (res.config) {
                    flash(diffGroups(config, res.config));
                    setConfig(res.config);
                    setDirty(true);
                    setSaveNotice(null);
                    toast.success('Configurazione generata. Verifica i campi prima di salvare.', 'toast-api-route-ai-configured');
                }
                setTestResult({ test: res.final_test });
                if (!res.final_test.ok) setTab('test');
            },
            onError: () => setTab('test'),
        });
    }
    function handleSave() {
        const args = parseArgs();
        if (!args) { setTab('test'); return; }
        setSubmitError(null);
        setFieldErrors({});
        setSaveNotice(null);
        const onError = (e: unknown) => {
            const error = toAdminError(e);
            const fields = mapConfigErrors(error.fieldErrors);
            setSubmitError(error.message);
            setFieldErrors(fields);
            setTab(Object.keys(fields).some(k => k.startsWith('param-')) ? 'params' : 'request');
        };
        const created = !savedRoute;
        const afterSave = (saved: ApiRoute) => {
            setSavedRoute(saved);
            setDirty(false);
            setTestResult(null);
            setSaveNotice('Configurazione salvata. Verifica della chiamata in corso…');
            testRoute.mutate({ routeId: saved.id, exampleArgs: args }, {
                onSuccess: res => {
                    setTestResult(res);
                    if (!res.test.ok) {
                        setSaveNotice('Configurazione salvata. Il test non è riuscito: controlla il risultato prima di attivare la rotta.');
                        setTab('test');
                        return;
                    }
                    toast.success(created ? 'Rotta creata e verificata.' : 'Rotta salvata e verificata.', created ? 'toast-api-route-created' : 'toast-api-route-updated');
                    onSaved?.();
                    onClose();
                },
                onError: e => {
                    setSaveNotice('Configurazione salvata. Non è stato possibile completare il test.');
                    setSubmitError(toAdminError(e).message);
                    setTab('test');
                },
            });
        };
        if (savedRoute) updateRoute.mutate({ routeId: savedRoute.id, config }, { onSuccess: afterSave, onError });
        else createRoute.mutate({ connectorId: connector.id, config }, { onSuccess: afterSave, onError });
    }

    return (
        <Dialog.Root open onOpenChange={open => { if (!open && !busy) onClose(); }}>
            <Dialog.Portal>
                <Dialog.Overlay className="route-editor-overlay" data-testid="api-route-form-backdrop" />
                <Dialog.Content className="route-editor" data-testid="api-route-form" onEscapeKeyDown={e => { if (busy) e.preventDefault(); }} onInteractOutside={e => { if (busy) e.preventDefault(); }}>
                    <header className="route-editor-header">
                        <div className="route-editor-heading">
                            <Dialog.Title className="route-editor-title">{isEdit ? 'Modifica rotta' : 'Nuova rotta'}</Dialog.Title>
                            <Dialog.Description className="route-editor-description">{connector.name}</Dialog.Description>
                        </div>
                        <span className="route-editor-status" data-testid="api-route-form-status" data-dirty={dirty}>
                            {dirty ? <Circle size={10} aria-hidden="true" /> : <Check size={14} aria-hidden="true" />}
                            {dirty ? 'Modifiche non salvate' : isEdit ? 'Salvata' : 'Da creare'}
                        </span>
                        <Button variant="quiet" size="sm" iconOnly aria-label="Chiudi" title="Chiudi" data-testid="api-route-form-cancel" disabled={busy} onClick={onClose}><X size={18} aria-hidden="true" /></Button>
                    </header>
                    <div className="route-editor-url"><span className="route-editor-badge">{config.request.http_method}</span><code>{config.request.url || 'Inserisci l’endpoint della rotta'}</code></div>
                    <Tabs.Root value={tab} onValueChange={v => setTab(v as EditorTab)} style={{ display: 'flex', flexDirection: 'column', flex: 1, minHeight: 0 }}>
                        <Tabs.List className="route-editor-tabs" aria-label="Configurazione della rotta">
                            <Tabs.Trigger value="request" className="route-editor-tab">Richiesta</Tabs.Trigger>
                            <Tabs.Trigger value="params" className="route-editor-tab">Parametri <span className="route-editor-tab-count">{config.request.params.length}</span></Tabs.Trigger>
                            <Tabs.Trigger value="response" className="route-editor-tab">Risposta</Tabs.Trigger>
                            <Tabs.Trigger value="test" className="route-editor-tab">Prova</Tabs.Trigger>
                        </Tabs.List>
                        <Tabs.Content forceMount value="request" className="route-editor-panel amd-scroll">
                            <fieldset disabled={busy} className="route-editor-fields" style={{ border: 0, padding: 0, margin: 0 }}>
                                <Section title="Richiesta HTTP">
                                    <Field label="Nome" required error={fieldErrors.name} errorId="api-route-form-name-error" flash={applied.identity}>
                                        <input className="route-editor-control" data-testid="api-route-form-name" value={config.identity.name} aria-invalid={!!fieldErrors.name} onChange={e => patchIdentity({ name: e.target.value })} />
                                    </Field>
                                    <div className="route-editor-request-grid">
                                        <Field label="Metodo" required><Select testid="api-route-form-http_method" value={config.request.http_method} onChange={v => patchRequest({ http_method: v as HttpMethod })} options={HTTP_METHODS} /></Field>
                                        <Field label="Endpoint" required error={fieldErrors.url} errorId="api-route-form-url-error" flash={applied.request}>
                                            {urlSplit.prefix && <span className="route-editor-base">{urlSplit.prefix}</span>}
                                            <input className="route-editor-control is-mono" data-testid="api-route-form-url" value={urlSplit.path} aria-invalid={!!fieldErrors.url} placeholder="/ordini" onChange={e => patchRequest({ url: joinUrl(base, e.target.value) })} />
                                        </Field>
                                    </div>
                                    <Field label="Profilo di autenticazione" note="Usato per tutte le chiamate di questa rotta.">
                                        <div className="route-editor-select"><select className="route-editor-control" data-testid="api-route-form-auth_profile_id" value={config.request.auth_profile_id ?? ''} onChange={e => patchRequest({ auth_profile_id: e.target.value === '' ? null : Number(e.target.value) })}>
                                            <option value="">{connector.default_auth_profile_id != null ? `Predefinito del connettore (#${connector.default_auth_profile_id})` : 'Nessuno (chiamata anonima)'}</option>
                                            {(connector.auth_profiles ?? []).map(p => <option key={p.id} value={p.id}>{p.type === 'basic' ? 'Username e password' : p.type} (#{p.id})</option>)}
                                        </select><ChevronDown size={15} aria-hidden="true" /></div>
                                    </Field>
                                    <Field label="Descrizione" note="Spiega all’assistente quando usare questa rotta." flash={applied.identity}>
                                        <textarea className="route-editor-control" data-testid="api-route-form-description" rows={2} value={config.identity.description ?? ''} onChange={e => patchIdentity({ description: e.target.value || null })} />
                                    </Field>
                                </Section>
                                <details className="route-editor-ai">
                                    <summary><Sparkles size={16} aria-hidden="true" /> Compila con AI o OpenAPI</summary>
                                    <div className="route-editor-section">
                                        <p className="route-editor-section-note">Genera i campi da un contratto OpenAPI o dai dati restituiti dall’endpoint. Rivedi il risultato prima di salvarlo.</p>
                                        <Field label="Link OpenAPI" note="Opzionale"><input className="route-editor-control" data-testid="api-route-form-ai-openapi-url" type="url" value={openApiUrl} onChange={e => setOpenApiUrl(e.target.value)} placeholder="https://api.example.com/openapi.json" /></Field>
                                        <div className="route-editor-inline-actions">
                                            <Button data-testid="api-route-form-ai-configure" size="sm" busy={aiRunning} onClick={runAiConfigure} leadingIcon={<Sparkles size={15} />}>Configura con AI</Button>
                                            {aiVerdict && <span data-testid="api-route-form-ai-applied" className="route-editor-inline-actions">
                                                <span data-testid="api-route-form-ai-source" className="route-editor-badge">{aiVerdict.source === 'openapi' ? 'da OpenAPI' : aiVerdict.source === 'response' ? 'da risposta' : 'Nessuna configurazione'}</span>
                                                <span data-testid="api-route-form-ai-final-test" className="route-editor-hint">Test finale: {aiVerdict.ok ? 'OK' : 'Fallito'} · HTTP {aiVerdict.status ?? '—'}</span>
                                            </span>}
                                        </div>
                                    </div>
                                </details>
                                <details className="route-editor-disclosure">
                                    <summary>Opzioni avanzate</summary>
                                    <div className="route-editor-section" style={{ padding: 14 }}>
                                        <Field label="Slug" note="Opzionale"><input className="route-editor-control is-mono" data-testid="api-route-form-slug" value={config.identity.slug ?? ''} onChange={e => patchIdentity({ slug: e.target.value || null })} /></Field>
                                        <div className="route-editor-options">
                                            <Field label="Timeout (ms)"><input className="route-editor-control" inputMode="numeric" data-testid="api-route-form-timeout_ms" value={config.options.timeout_ms ?? ''} onChange={e => patchOptions({ timeout_ms: intOrNull(e.target.value) })} placeholder="Predefinito" /></Field>
                                            <Field label="Cache (secondi)"><input className="route-editor-control" inputMode="numeric" data-testid="api-route-form-cache_ttl_s" value={config.options.cache_ttl_s ?? ''} onChange={e => patchOptions({ cache_ttl_s: intOrNull(e.target.value) })} placeholder="0" /></Field>
                                            <Field label="Chiamate al minuto"><input className="route-editor-control" inputMode="numeric" data-testid="api-route-form-rate_limit" value={config.options.rate_limit ?? ''} onChange={e => patchOptions({ rate_limit: intOrNull(e.target.value) })} placeholder="0" /></Field>
                                        </div>
                                    </div>
                                </details>
                            </fieldset>
                        </Tabs.Content>
                        <Tabs.Content forceMount value="params" className="route-editor-panel amd-scroll">
                            <fieldset disabled={busy} className="route-editor-fields" style={{ border: 0, padding: 0, margin: 0 }}>
                                <div className="route-editor-section-heading"><h3 className="route-editor-section-title">Parametri della richiesta</h3><Button data-testid="api-route-form-param-add" size="sm" leadingIcon={<Plus size={15} />} onClick={() => patchRequest({ params: [...config.request.params, { ...blankParam(), sort_order: config.request.params.length }] })}>Aggiungi</Button></div>
                                <p className="route-editor-section-note">Per ogni valore scegli dove inviarlo e se proviene dal modello, da un valore fisso o da una credenziale.</p>
                                {config.request.params.length === 0 ? <div data-testid="api-route-form-params-empty" className="route-editor-empty">Nessun parametro. La rotta viene chiamata con il solo endpoint.</div> :
                                    <div className="route-editor-params">
                                        {config.request.params.map((p, i) => <div key={i} className={`route-editor-param ${applied.request ? 'route-editor-flash' : ''}`}>
                                            <div className="route-editor-param-grid">
                                                <div className="route-editor-param-name"><Field label="Nome" error={fieldErrors[`param-${i}-name`]} errorId={`api-route-form-param-${i}-name-error`}><input className="route-editor-control is-mono" aria-label={`Parametro ${i + 1} nome`} data-testid={`api-route-form-param-${i}-name`} value={p.name} onChange={e => updateParam(i, { name: e.target.value })} placeholder="nome" /></Field></div>
                                                <Field label="Posizione"><Select ariaLabel={`Parametro ${i + 1} location`} testid={`api-route-form-param-${i}-location`} value={p.location} onChange={v => updateParam(i, { location: v as ParamLocation })} options={PARAM_LOCATIONS} /></Field>
                                                <Field label="Origine"><Select ariaLabel={`Parametro ${i + 1} source`} testid={`api-route-form-param-${i}-source`} value={p.source} onChange={v => updateParam(i, { source: v as ParamSource })} options={PARAM_SOURCES} labels={SOURCE_LABELS} /></Field>
                                                <Field label="Tipo"><Select ariaLabel={`Parametro ${i + 1} tipo`} testid={`api-route-form-param-${i}-type`} value={p.type} onChange={v => updateParam(i, { type: v as ParamType })} options={PARAM_TYPES} /></Field>
                                                <Button className="route-editor-param-remove" variant="quiet" size="sm" iconOnly aria-label={`Rimuovi parametro ${i + 1}`} title={`Rimuovi parametro ${i + 1}`} data-testid={`api-route-form-param-${i}-remove`} onClick={() => patchRequest({ params: config.request.params.filter((_, ix) => ix !== i) })}><X size={16} aria-hidden="true" /></Button>
                                            </div>
                                            <div className="route-editor-param-extra">
                                                <label className="route-editor-checkbox"><input type="checkbox" aria-label={`Parametro ${i + 1} obbligatorio`} data-testid={`api-route-form-param-${i}-required`} checked={p.required} onChange={e => updateParam(i, { required: e.target.checked })} />Obbligatorio</label>
                                                {p.source === 'fixed' && <Field label="Valore fisso"><input className="route-editor-control is-mono" aria-label={`Parametro ${i + 1} valore fisso`} data-testid={`api-route-form-param-${i}-value`} value={p.value ?? ''} onChange={e => updateParam(i, { value: e.target.value })} /></Field>}
                                                {p.source === 'secret' && <Field label="Chiave della credenziale"><input className="route-editor-control is-mono" aria-label={`Parametro ${i + 1} secret ref`} data-testid={`api-route-form-param-${i}-secret_ref`} value={p.secret_ref ?? ''} onChange={e => updateParam(i, { secret_ref: e.target.value })} /></Field>}
                                            </div>
                                        </div>)}
                                    </div>}
                            </fieldset>
                        </Tabs.Content>
                        <Tabs.Content forceMount value="response" className="route-editor-panel amd-scroll">
                            <fieldset disabled={busy} className="route-editor-fields" style={{ border: 0, padding: 0, margin: 0 }}>
                                <Section title="Dati restituiti">
                                    <div className={applied.response ? 'route-editor-flash' : undefined}>
                                        <div id="api-route-form-endpoint_type-caption" className="route-editor-label">Tipo di risposta</div>
                                        <div className="ui-button-group" role="group" aria-labelledby="api-route-form-endpoint_type-caption" data-testid="api-route-form-endpoint_type">
                                            {ENDPOINT_TYPES.map(o => <Button key={o.value} size="sm" aria-pressed={config.response.endpoint_type === o.value} data-testid={`api-route-form-endpoint_type-${o.value}`} onClick={() => patchResponse({ endpoint_type: o.value })}>{o.label}</Button>)}
                                        </div>
                                        <p className="route-editor-hint">{ENDPOINT_TYPES.find(o => o.value === config.response.endpoint_type)?.hint}</p>
                                    </div>
                                    {config.response.endpoint_type !== 'detail' && <>
                                        <Field label="Percorso degli elementi" note="Usa un percorso come data.items. Lascia vuoto se la risposta è già un array." flash={applied.response}>
                                            <input className="route-editor-control is-mono" data-testid="api-route-form-items_path" value={config.response.items_path ?? ''} onChange={e => patchResponse({ items_path: e.target.value })} placeholder="data" />
                                        </Field>
                                        <PaginationCard pagination={config.response.pagination} setPagination={p => { patchResponse({ pagination: p }); setDetectSource(null); }} onDetect={runDetectPagination} detecting={testing} detectSource={detectSource} />
                                    </>}
                                </Section>
                            </fieldset>
                        </Tabs.Content>
                        <Tabs.Content forceMount value="test" className="route-editor-panel amd-scroll">
                            <div className="route-editor-fields">
                                <Section title="Prova la chiamata" note="Usa la configurazione attuale, comprese le modifiche non ancora salvate.">
                                    <Field label="Valori di prova (JSON)" note="Inserisci i valori dei parametri forniti dal modello. I valori fissi e l’autenticazione sono già inclusi." error={argsError ?? undefined} errorId="api-route-form-example-args-error">
                                        <textarea className="route-editor-control is-mono route-editor-arguments" data-testid="api-route-form-example-args" value={exampleArgs} disabled={busy} aria-invalid={!!argsError} onChange={e => { setExampleArgs(e.target.value); setTestResult(null); }} spellCheck={false} />
                                    </Field>
                                    {(testing || testRoute.isPending) && <p role="status" className="route-editor-hint">Chiamata in corso…</p>}
                                    {testError && <div data-testid="api-route-form-test-error" role="alert" className="route-editor-error">{testError}</div>}
                                    {saveNotice && <p role="status" className="route-editor-hint" data-testid="api-route-form-save-notice">{saveNotice}</p>}
                                    {testResult && <RouteTestResult result={testResult} />}
                                    {!testResult && !testing && !testRoute.isPending && !testError && <div className="route-editor-empty">Premi «Testa chiamata» per verificare accesso e risposta dell’endpoint.</div>}
                                </Section>
                            </div>
                        </Tabs.Content>
                    </Tabs.Root>
                    {submitError && <div data-testid="api-route-form-error" role="alert" className="route-editor-error" style={{ padding: '0 24px 12px' }}>{submitError}</div>}
                    <footer className="route-editor-footer">
                        <Button data-testid="api-route-form-test" busy={testing} disabled={saving || aiRunning} onClick={runTest} leadingIcon={<Play size={15} />}>Testa chiamata</Button>
                        <span className="route-editor-footer-note">{saving ? 'Salvataggio e verifica…' : dirty ? 'Modifiche non salvate' : isEdit ? 'Configurazione salvata' : 'Nuova configurazione'}</span>
                        <div className="route-editor-footer-actions">
                            <Button variant="quiet" onClick={onClose} disabled={busy}>Annulla</Button>
                            <Button variant="primary" data-testid="api-route-form-submit" busy={saving} disabled={testing || aiRunning} onClick={handleSave} leadingIcon={<Save size={15} />}>{isEdit ? 'Salva' : 'Crea rotta'}</Button>
                        </div>
                    </footer>
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}

function intOrNull(raw: string): number | null {
    const n = Number.parseInt(raw.trim(), 10);
    return Number.isNaN(n) ? null : n;
}
function Section({ title, note, children }: { title: string; note?: string; children: ReactNode }) {
    return <section className="route-editor-section"><h3 className="route-editor-section-title">{title}</h3>{note && <p className="route-editor-section-note">{note}</p>}{children}</section>;
}
function Field({ label, required, note, error, errorId, flash, children }: { label: string; required?: boolean; note?: string; error?: string; errorId?: string; flash?: boolean; children: ReactNode }) {
    return <div className={`route-editor-field ${flash ? 'route-editor-flash' : ''}`}><label><span className="route-editor-label">{label}{required && <span className="route-editor-required"> *</span>}</span>{children}</label>{note && <span className="route-editor-hint">{note}</span>}{error && <span data-testid={errorId} role="alert" className="route-editor-error">{error}</span>}</div>;
}
function Select({ testid, ariaLabel, value, onChange, options, labels }: { testid: string; ariaLabel?: string; value: string; onChange: (value: string) => void; options: string[]; labels?: Record<string, string> }) {
    return <div className="route-editor-select"><select className="route-editor-control" aria-label={ariaLabel} data-testid={testid} value={value} onChange={e => onChange(e.target.value)}>{options.map(o => <option key={o} value={o}>{labels?.[o] ?? o}</option>)}</select><ChevronDown size={15} aria-hidden="true" /></div>;
}
function PaginationCard({ pagination, setPagination, onDetect, detecting, detectSource }: {
    pagination: PaginationConfig | null; setPagination: (value: PaginationConfig | null) => void;
    onDetect: () => void; detecting: boolean; detectSource: 'response' | null;
}) {
    const on = pagination != null && pagination.type !== 'none';
    const set = (p: Partial<PaginationConfig>) => setPagination({ ...(pagination ?? { type: 'cursor' }), ...p });
    return <div className="route-editor-pagination">
        <div className="route-editor-pagination-header">
            <label className="route-editor-checkbox" style={{ flex: 1 }}><input type="checkbox" data-testid="api-route-form-pagination-toggle" checked={on} onChange={() => setPagination(on ? { type: 'none' } : { type: 'cursor' })} />Paginazione</label>
            <Button size="sm" data-testid="api-route-form-pagination-detect" busy={detecting} onClick={onDetect}>Rileva dalla risposta</Button>
        </div>
        {on && pagination && <div className="route-editor-pagination-body">
            {detectSource && <span className="route-editor-hint" data-testid="api-route-form-pagination-source">Rilevata dalla risposta.</span>}
            <Field label="Tipo"><Select testid="api-route-form-pagination-type" value={pagination.type} options={['cursor', 'page']} labels={{ cursor: 'Cursore / token', page: 'Numero pagina' }} onChange={v => set({ type: v as PaginationConfig['type'] })} /></Field>
            {pagination.type === 'cursor' ? <>
                <div className="route-editor-grid">
                    <Field label="Parametro cursore"><input className="route-editor-control is-mono" data-testid="api-route-form-pagination-cursor_param" value={pagination.cursor_param ?? ''} onChange={e => set({ cursor_param: e.target.value })} placeholder="cursor" /></Field>
                    <Field label="Percorso del prossimo cursore"><input className="route-editor-control is-mono" data-testid="api-route-form-pagination-next_cursor_path" value={pagination.next_cursor_path ?? ''} onChange={e => set({ next_cursor_path: e.target.value })} placeholder="meta.next_cursor" /></Field>
                </div>
                <Field label="Percorso del prossimo URL" note="Opzionale"><input className="route-editor-control is-mono" data-testid="api-route-form-pagination-next_url_path" value={pagination.next_url_path ?? ''} onChange={e => set({ next_url_path: e.target.value })} placeholder="links.next" /></Field>
            </> : <div className="route-editor-grid">
                <Field label="Parametro pagina"><input className="route-editor-control is-mono" data-testid="api-route-form-pagination-page_param" value={pagination.page_param ?? ''} onChange={e => set({ page_param: e.target.value })} placeholder="page" /></Field>
                <Field label="Parametro dimensione"><input className="route-editor-control is-mono" data-testid="api-route-form-pagination-size_param" value={pagination.size_param ?? ''} onChange={e => set({ size_param: e.target.value })} placeholder="limit" /></Field>
            </div>}
        </div>}
    </div>;
}
