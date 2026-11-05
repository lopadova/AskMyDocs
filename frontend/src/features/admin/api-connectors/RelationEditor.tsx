import { useRef, useState, type FormEvent, type ReactNode } from 'react';
import { ArrowRight, Plus, RefreshCw, X } from 'lucide-react';
import { Button } from '@/components/Button';
import { SelectField, SelectOption } from '@/components/ui/select-field';
import { SearchPicker } from '../api-tools/SearchPicker';
import '../api-tools/api-tools.css';
import { useUiTranslation } from '../../../lib/ui-locale';
import { toAdminError } from '../shared/errors';
import { apiConnectorsApi, type ApiRoute, type ApiRouteRelation, type ApiRouteSummary, type RelationFieldMap, type RelationPayload } from './api-connectors.api';
import { compatibleField, detailLlmParams, hasSuccessfulResponse, listItemFields, listResponseItems, parseRelationArgument } from './relation-fields';
import { routeToConfig } from './route-config';
import { errorTextStyle, fieldCaptionStyle, fieldLabelStyle, inputStyle, modalBackdropStyle, modalPanelStyle } from './styles';

export interface RelationEditorProps {
    routes: ApiRouteSummary[];
    relation?: ApiRouteRelation | null;
    listRouteFull?: ApiRoute | null;
    detailRouteFull?: ApiRoute | null;
    listRouteError?: string | null;
    detailRouteError?: string | null;
    onSelectListRoute: (routeId: number | null) => void;
    onSelectDetailRoute: (routeId: number | null) => void;
    onSubmit: (payload: RelationPayload) => void;
    onClose: () => void;
    submitError?: string | null;
    isSubmitting?: boolean;
}

interface MapRow extends RelationFieldMap { _key: number }
let keySequence = 0;

export function RelationEditor({ routes, relation, listRouteFull, detailRouteFull, listRouteError, detailRouteError, onSelectListRoute, onSelectDetailRoute, onSubmit, onClose, submitError, isSubmitting }: RelationEditorProps): ReactNode {
    const { t } = useUiTranslation();
    const [listRouteId, setListRouteId] = useState(relation ? String(relation.list_route_id) : '');
    const [detailRouteId, setDetailRouteId] = useState(relation ? String(relation.detail_route_id) : '');
    const [name, setName] = useState(relation?.name ?? '');
    const [rows, setRows] = useState<MapRow[]>(() => (relation?.field_map.length ? relation.field_map : [{ from: '', to_param: '' }]).map((row) => ({ ...row, _key: ++keySequence })));
    const [args, setArgs] = useState<Record<string, string>>({});
    const [sample, setSample] = useState<{ routeId: number; body: unknown; at: string } | null>(null);
    const [refreshError, setRefreshError] = useState<string | null>(null);
    const [refreshing, setRefreshing] = useState(false);
    const generation = useRef(0);
    // Ignore parent responses for an earlier selection while the next route loads.
    const list = listRouteFull?.id === Number(listRouteId) ? listRouteFull : null;
    const detail = detailRouteFull?.id === Number(detailRouteId) ? detailRouteFull : null;
    const currentSample = sample?.routeId === list?.id ? sample : null;
    const successful = !!list && (!!currentSample || hasSuccessfulResponse(list));
    const body = currentSample ? currentSample.body : list?.last_test_payload;
    const fields = list && successful ? listItemFields(list, body) : [];
    const items = list && successful ? listResponseItems(list, body) : null;
    const params = detail ? detailLlmParams(detail) : [];
    const callParams = list ? detailLlmParams(list) : [];
    const rowErrors = rows.map((row) => {
        if (!row.from || !row.to_param) return 'Seleziona entrambi i campi.';
        const field = fields.find((field) => field.path === row.from);
        const param = params.find((param) => param.name === row.to_param);
        if (!field) return `Da correggere: il campo ${row.from} non è presente nella risposta reale.`;
        if (!param) return `Da correggere: ${row.to_param} non è un parametro LLM del dettaglio.`;
        if (params.filter((candidate) => candidate.name === row.to_param).length > 1) return 'Da correggere: nome parametro ambiguo tra più posizioni.';
        if (row.to_location && row.to_location !== param.location) return `Da correggere: ${row.to_param} ora si trova in ${param.location}. Seleziona di nuovo il parametro.`;
        if (!compatibleField(field, param.type)) return `Da correggere: tipo o cardinalità incompatibile (${field.types.join(' / ')} e ${param.type}).`;
        if (rows.filter((candidate) => candidate.to_param === row.to_param).length > 1) return 'Da correggere: parametro destinazione duplicato.';
        return null;
    });
    const missingRequired = params.filter((param) => param.required && !rows.some((row) => row.to_param === param.name));
    const taxonomyError = list && list.endpoint_type !== 'list' ? 'La sorgente deve essere classificata come lista nella configurazione endpoint.' : detail && detail.endpoint_type !== 'detail' ? 'Il dettaglio selezionato è classificato come lista. Imposta il tipo Dettaglio nella configurazione endpoint prima di salvare la relazione.' : null;
    const canSave = !!list && !!detail && !taxonomyError && successful && rows.length > 0 && rowErrors.every((error) => !error) && missingRequired.length === 0 && !refreshing;

    function updateRow(key: number, patch: Partial<MapRow>) {
        setRows((previous) => previous.map((row) => row._key === key ? { ...row, ...patch } : row));
    }
    function selectList(value: string) {
        generation.current += 1;
        setListRouteId(value); setSample(null); setArgs({}); setRefreshError(null); setRefreshing(false);
        onSelectListRoute(value ? Number(value) : null);
    }
    async function refresh() {
        if (!list) return;
        const requestGeneration = ++generation.current;
        setRefreshError(null);
        try {
            const exampleArgs: Record<string, unknown> = {};
            for (const param of callParams) {
                const value = args[param.name] ?? '';
                if (!value.trim()) {
                    if (param.required) throw new Error(`Inserisci ${param.name} (${param.location}, ${param.type}).`);
                    continue;
                }
                try { exampleArgs[param.name] = parseRelationArgument(value, param.type); }
                catch { throw new Error(`Valore non valido per ${param.name}: atteso ${param.type}.`); }
            }
            setRefreshing(true);
            const response = await apiConnectorsApi.testConfig(list.api_connector_id, routeToConfig(list), exampleArgs);
            if (requestGeneration !== generation.current) return;
            if (!response.test.ok || !response.test.is_json) throw new Error(response.test.error || `Chiamata fallita o risposta non JSON (HTTP ${response.test.status ?? 'non disponibile'}).`);
            setSample({ routeId: list.id, body: response.test.body, at: new Date().toISOString() });
        } catch (error) {
            if (requestGeneration === generation.current) setRefreshError(error instanceof Error && !('isAxiosError' in error) ? error.message : toAdminError(error).message);
        } finally {
            if (requestGeneration === generation.current) setRefreshing(false);
        }
    }
    function submit(event: FormEvent) {
        event.preventDefault();
        if (!canSave || isSubmitting) return;
        onSubmit({ list_route_id: Number(listRouteId), detail_route_id: Number(detailRouteId), field_map: rows.map(({ from, to_param }) => ({ from, to_param, to_location: params.find((param) => param.name === to_param)!.location })), name: name.trim() || null });
    }
    return <div data-testid="api-route-relation-form-backdrop" style={modalBackdropStyle()}>
        <form role="dialog" aria-modal="true" aria-labelledby="api-route-relation-form-title" aria-busy={isSubmitting || refreshing} data-testid="api-route-relation-form" onSubmit={submit} style={{ ...modalPanelStyle(700), minWidth: 0 }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
                <h2 id="api-route-relation-form-title" style={{ margin: 0, fontSize: 15 }}>{relation ? 'Edit relation' : 'New list / detail relation'}</h2>
                <Button size="sm" variant="quiet" iconOnly aria-label="Chiudi relazione" onClick={onClose}><X size={16} /></Button>
            </div>
            <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                <label style={{ ...fieldLabelStyle(), flex: '1 1 240px' }}>
                    <span style={fieldCaptionStyle()}>List route</span>
                    <SelectField aria-label="List route" data-testid="api-route-relation-form-list_route" value={listRouteId} onValueChange={selectList}>
                        <SelectOption value="">Select a list endpoint…</SelectOption>
                        {routes.filter((route) => route.endpoint_type === 'list').map((route) => <SelectOption key={route.id} value={route.id}>{route.name} ({route.slug})</SelectOption>)}
                    </SelectField>
                </label>
                <label style={{ ...fieldLabelStyle(), flex: '1 1 240px' }}>
                    <span style={fieldCaptionStyle()}>Detail route</span>
                    <SelectField aria-label="Detail route" data-testid="api-route-relation-form-detail_route" value={detailRouteId} onValueChange={(value) => { setDetailRouteId(value); onSelectDetailRoute(value ? Number(value) : null); }}>
                        <SelectOption value="">Select a detail endpoint…</SelectOption>
                        {routes.filter((route) => route.endpoint_type === 'detail' || String(route.id) === detailRouteId).map((route) => <SelectOption key={route.id} value={route.id}>{route.name} ({route.slug})</SelectOption>)}
                    </SelectField>
                </label>
            </div>
            {listRouteError && <p role="alert" style={errorTextStyle()}>{listRouteError}</p>}
            {detailRouteError && <p role="alert" style={errorTextStyle()}>{detailRouteError}</p>}
            {taxonomyError && <p role="alert" style={errorTextStyle()}>{taxonomyError}</p>}
            {listRouteId && !list && !listRouteError && <p role="status">Caricamento endpoint lista…</p>}
            {detailRouteId && !detail && !detailRouteError && <p role="status">Caricamento parametri dettaglio…</p>}
            {list && <section aria-label="Risposta reale lista" style={{ display: 'grid', gap: 8 }}>
                <p style={{ ...fieldCaptionStyle(), margin: 0 }}>
                    {successful ? `Risposta reale: ${currentSample?.at ?? list.last_test_at ?? 'data non disponibile'} · ${items?.length ?? 0} elementi · items_path: ${list.items_path || '(radice)'}` : 'Nessuna risposta reale riuscita disponibile. Esegui una chiamata per caricare i campi.'}
                </p>
                {callParams.length > 0 && <details open={!successful}>
                    <summary style={fieldCaptionStyle()}>Argomenti della chiamata</summary>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginTop: 8 }}>
                        {callParams.map((param) => <label key={`${param.location}:${param.name}`} style={{ ...fieldLabelStyle(), flex: '1 1 200px' }}>
                            <span style={fieldCaptionStyle()}>{param.name}{param.required ? ' *' : ''} · {param.location} · {param.type}</span>
                            <input aria-label={`Argomento ${param.name}`} value={args[param.name] ?? ''} onChange={(event) => setArgs((previous) => ({ ...previous, [param.name]: event.target.value }))} placeholder={param.description ?? param.type} style={inputStyle()} />
                        </label>)}
                    </div>
                </details>}
                <Button size="sm" leadingIcon={<RefreshCw size={14} />} busy={refreshing} onClick={() => void refresh()} style={{ justifySelf: 'start' }}>Aggiorna dalla chiamata reale</Button>
                {refreshError && <p role="alert" style={errorTextStyle()}>{refreshError}{successful ? ' La precedente risposta riuscita resta disponibile.' : ''}</p>}
                {successful && items === null && <p role="alert" style={errorTextStyle()}>items_path non identifica un elenco nella risposta. Correggi la configurazione della lista.</p>}
                {successful && items?.length === 0 && <p role="status">La chiamata è riuscita ma l’elenco è vuoto. Cambia gli argomenti e aggiorna per trovare campi reali.</p>}
                {successful && !!items?.length && fields.length === 0 && <p role="status">Gli elementi non contengono campi utilizzabili per la relazione.</p>}
            </section>}
            {detail && params.length === 0 && <p role="status">Il dettaglio non ha parametri LLM configurati. Parametri fissi, segreti e autenticazione non sono destinazioni.</p>}
            <div style={{ display: 'grid', gap: 8 }}>
                <span style={fieldCaptionStyle()}>Campo dell’elemento / parametro del dettaglio</span>
                {rows.map((row, index) => {
                    const selectedField = fields.find((field) => field.path === row.from);
                    const sourceOptions = fields.map((field) => ({ value: field.path, label: field.path, detail: `${field.types.join(' / ')} · ${field.cardinality === 'many' ? 'collezione' : 'singolo'} · ${field.present}/${field.total} elementi` }));
                    const targetOptions = params.filter((param) => !selectedField || compatibleField(selectedField, param.type) || param.name === row.to_param).map((param) => ({ value: param.name, label: param.name, detail: `${param.location} · ${param.type}${param.required ? ' · obbligatorio' : ''}` }));
                    if (row.from && !fields.some((field) => field.path === row.from)) sourceOptions.unshift({ value: row.from, label: `${row.from} · da correggere`, detail: 'Assente dalla risposta reale' });
                    if (row.to_param && !params.some((param) => param.name === row.to_param)) targetOptions.unshift({ value: row.to_param, label: `${row.to_param} · da correggere`, detail: 'Parametro LLM non disponibile' });
                    return <div key={row._key} data-testid={`api-route-relation-form-map-${index}`}>
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, alignItems: 'center' }}>
                            <div style={{ flex: '1 1 220px', minWidth: 0 }}><SearchPicker label={`Campo lista ${index + 1}`} value={row.from} options={sourceOptions} onChange={(from) => updateRow(row._key, { from })} placeholder="Seleziona campo reale" /></div>
                            <ArrowRight size={16} aria-hidden="true" />
                            <div style={{ flex: '1 1 220px', minWidth: 0 }}><SearchPicker label={`Parametro dettaglio ${index + 1}`} value={row.to_param} options={targetOptions} onChange={(to_param) => updateRow(row._key, { to_param, to_location: params.find((param) => param.name === to_param)?.location })} placeholder="Seleziona parametro LLM" /></div>
                            <Button size="sm" variant="quiet" iconOnly data-testid={`api-route-relation-form-map-${index}-remove`} aria-label={`Remove field map row ${index + 1}`} onClick={() => setRows((previous) => previous.filter((candidate) => candidate._key !== row._key))}><X size={14} /></Button>
                        </div>
                        {rowErrors[index] && (row.from || row.to_param) && <p style={errorTextStyle()}>{rowErrors[index]}</p>}
                    </div>;
                })}
                <Button size="sm" data-testid="api-route-relation-form-map-add" leadingIcon={<Plus size={14} />} onClick={() => setRows((previous) => [...previous, { _key: ++keySequence, from: '', to_param: '' }])} style={{ justifySelf: 'start' }}>Add mapping</Button>
                {missingRequired.length > 0 && <p style={errorTextStyle()}>Parametri obbligatori da collegare: {missingRequired.map((param) => param.name).join(', ')}.</p>}
            </div>
            <label style={fieldLabelStyle()}><span style={fieldCaptionStyle()}>Label (optional)</span><input data-testid="api-route-relation-form-name" value={name} onChange={(event) => setName(event.target.value)} style={inputStyle()} /></label>
            {submitError && <p data-testid="api-route-relation-form-error" role="alert" style={errorTextStyle()}>{submitError}</p>}
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
                <Button size="sm" data-testid="api-route-relation-form-cancel" onClick={onClose}>{t('Cancel')}</Button>
                <Button size="sm" variant="primary" type="submit" data-testid="api-route-relation-form-submit" disabled={!canSave} busy={isSubmitting}>{relation ? 'Save relation' : 'Create relation'}</Button>
            </div>
        </form>
    </div>;
}
