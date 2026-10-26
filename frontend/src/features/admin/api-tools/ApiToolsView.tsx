import { useEffect, useRef, useState } from 'react';
import { Download, Plus, Save, Upload, WandSparkles } from 'lucide-react';
import { Button } from '../../../components/Button';
import { adminProjectsApi, type AdminProject } from '../projects/admin-projects.api';
import { AdminShell } from '../shell/AdminShell';
import { toAdminError } from '../shared/errors';
import { apiToolsApi } from './api-tools.api';
import { latestRunSteps } from './CallDiagnostics';
import { useToolRunRefresh } from './use-tool-run-refresh';
import { DefinitionEditor } from './DefinitionEditor';
import { Diagnostics } from './Diagnostics';
import { JsonField } from './JsonField';
import { blankTool } from './model';
import { SearchPicker } from './SearchPicker';
import type { ApiTool, Endpoint, ToolDetail, ToolPayload } from './types';
import './api-tools.css';
const statuses: Record<ApiTool['status'], string> = { draft: 'Bozza', active: 'Attivo', deprecated: 'Deprecato', disabled_errors: 'Disabilitato per errori', disabled_manual: 'Disabilitato manualmente' };
export function ApiToolsView() {
    const [tools, setTools] = useState<ApiTool[]>([]);
    const [endpoints, setEndpoints] = useState<Endpoint[]>([]);
    const [projects, setProjects] = useState<AdminProject[]>([]);
    const [enabled, setEnabled] = useState(true);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState('');
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [lastRefreshed, setLastRefreshed] = useState('');
    const [selected, setSelected] = useState<ApiTool | null>(null);
    const [draft, setDraft] = useState<ToolPayload | null>(null);
    const [detail, setDetail] = useState<ToolDetail | null>(null);
    const [saved, setSaved] = useState('');
    const [result, setResult] = useState<Record<string, unknown> | null>(null);
    const [prompt, setPrompt] = useState('');
    const [filter, setFilter] = useState('');
    const [importPackage, setImportPackage] = useState<unknown>({});
    const [importProject, setImportProject] = useState('');
    const [importOpen, setImportOpen] = useState(false);
    const [liveConfirmed, setLiveConfirmed] = useState(false);
    const [exportPackage, setExportPackage] = useState<unknown>(null);
    const form = useRef<HTMLFormElement>(null);
    const importForm = useRef<HTMLFormElement>(null);
    const selectionEpoch = useRef(0);
    useEffect(() => {
        let active = true;
        void Promise.allSettled([apiToolsApi.list(), adminProjectsApi.list()]).then(([index, registry]) => {
            if (!active) return;
            if (index.status === 'fulfilled') { setTools(index.value.tools); setEndpoints(index.value.endpoints); setEnabled(index.value.enabled); }
            else setError(toAdminError(index.reason).message);
            if (registry.status === 'fulfilled') setProjects(registry.value);
            else setError((previous) => previous || 'Impossibile caricare i progetti. Ricarica la pagina per riprovare.');
            setLoading(false);
        });
        return () => { active = false; selectionEpoch.current++; };
    }, []);
    useEffect(() => {
        if (!draft?.project_key) return;
        let active = true;
        void apiToolsApi.list(draft.project_key).then((index) => { if (active) setEndpoints(index.endpoints); }).catch((reason: unknown) => { if (active) setError(toAdminError(reason).message); });
        return () => { active = false; };
    }, [draft?.project_key]);
    const dirty = !!draft && JSON.stringify(draft) !== saved;
    const pendingWork = useToolRunRefresh({
        toolId: busy === 'open' ? undefined : selected?.id,
        detail,
        selectionEpoch: selectionEpoch.current,
        epochRef: selectionEpoch,
        onRefresh: (response) => {
            setDetail(response);
            setSelected(response.tool);
            setTools((previous) => previous.map((tool) => tool.id === response.tool.id ? response.tool : tool));
            // Runtime refreshes update diagnostics and lifecycle only. The editor owns its draft.
            setLastRefreshed(new Date().toLocaleTimeString('it-IT'));
            setResult((previous) => previous ? { ...previous, runs: response.runs, verifications: response.verifications } : previous);
        },
        onError: (reason) => setError(`Aggiornamento temporaneamente non disponibile. ${toAdminError(reason).message}`),
    });
    const projectOptions = projects.map((project) => ({ value: project.project_key, label: project.name, detail: project.project_key }));
    async function open(tool: ApiTool) {
        const epoch = ++selectionEpoch.current; setBusy('open'); setLastRefreshed(''); setError(''); setResult(null); setExportPackage(null);
        try { const response = await apiToolsApi.show(tool.id); if (epoch !== selectionEpoch.current) return; setSelected(response.tool); setDraft(payload(response.tool)); setSaved(JSON.stringify(payload(response.tool))); setDetail(response); if (response.endpoints) setEndpoints(response.endpoints); setLiveConfirmed(false); }
        catch (reason) { if (epoch === selectionEpoch.current) setError(toAdminError(reason).message); }
        finally { if (epoch === selectionEpoch.current) setBusy(''); }
    }
    async function work(action: string, run: () => Promise<void>) { setBusy(action); setError(''); setNotice(''); try { await run(); } catch (reason) { setError(toAdminError(reason).message); } finally { setBusy(''); } }
    async function save() {
        if (!draft || !form.current?.reportValidity()) return;
        await work('save', async () => { const tool = await apiToolsApi.save(selected?.id ?? null, draft); setSelected(tool); setDraft(payload(tool)); setSaved(JSON.stringify(payload(tool))); setTools((previous) => [...previous.filter((candidate) => candidate.id !== tool.id), tool]); setNotice('Bozza salvata. Ora puoi validarla ed eseguire le prove.'); });
    }
    async function action(kind: 'validate' | 'publish' | 'disable' | 'enable' | 'test', mode?: 'simulation' | 'live') {
        if (!selected || dirty) return;
        await work(`${kind}-${mode ?? ''}`, async () => { const response = await apiToolsApi.action(selected.id, kind, mode ? { mode } : undefined); setResult(response); const responseDetail = await apiToolsApi.show(selected.id); setSelected(responseDetail.tool); setDetail(responseDetail); setTools((previous) => previous.map((tool) => tool.id === selected.id ? responseDetail.tool : tool)); setDraft(payload(responseDetail.tool)); setSaved(JSON.stringify(payload(responseDetail.tool))); setNotice(kind === 'publish' ? 'Tool pubblicato.' : kind === 'disable' ? 'Tool disabilitato.' : kind === 'enable' ? 'Tool abilitato.' : kind === 'test' && mode === 'live' ? 'Prova live avviata. I risultati si aggiornano automaticamente durante l’esecuzione.' : 'Operazione completata. Consulta la diagnostica.'); });
    }
    return <AdminShell section="api-tools"><main className="api-tools">
        <header className="api-tool-page-heading"><div><p className="api-tool-eyebrow">Integrazioni</p><h1>Tool API</h1><p>Componi strumenti riutilizzabili con chiamate, trasformazioni, output e prove.</p></div><div className="api-tool-actions"><Button disabled={!enabled || !!busy} leadingIcon={<Upload size={15} />} onClick={() => setImportOpen(!importOpen)}>Importa</Button><Button variant="primary" disabled={!enabled || !!busy} leadingIcon={<Plus size={15} />} onClick={() => { selectionEpoch.current++; setLastRefreshed(''); setSelected(null); setDraft(blankTool()); setSaved(''); setDetail(null); setResult(null); setError(''); setNotice(''); setPrompt(''); setExportPackage(null); }}>Nuovo tool</Button></div></header>
        {error && <p role="alert" className="api-tool-error">{error}</p>}{notice && <p role="status">{notice}</p>}{pendingWork && <p role="status" className="api-tool-muted">Esecuzioni o verifiche in corso. Aggiornamento automatico.{lastRefreshed ? ` Ultimo aggiornamento: ${lastRefreshed}.` : ''}</p>}{!pendingWork && lastRefreshed && <p role="status" className="api-tool-muted">Aggiornamento completato alle {lastRefreshed}.</p>}{loading && <p role="status">Caricamento tool API…</p>}{!loading && !enabled && <p role="status">I tool API sono disabilitati per questa installazione.</p>}
        {importOpen && <form ref={importForm} className="api-tool-section" onSubmit={(event) => { event.preventDefault(); if (!importProject || !importForm.current?.reportValidity()) return; void work('import', async () => { const tool = await apiToolsApi.import(importPackage, importProject); setTools((previous) => [...previous.filter((candidate) => candidate.id !== tool.id), tool]); setImportOpen(false); setNotice('Pacchetto importato come bozza.'); }); }}><h2>Importa pacchetto</h2><SearchPicker label="Progetto importazione" value={importProject} options={projectOptions} onChange={setImportProject} placeholder="Seleziona progetto destinazione" /><JsonField label="Pacchetto tool" value={importPackage} onChange={setImportPackage} objectOnly /><Button type="submit" disabled={!importProject || !!busy} busy={busy === 'import'}>Importa come bozza</Button></form>}
        {enabled && !loading && <div className="api-tool-workspace"><aside className="api-tool-catalog" aria-label="Catalogo tool"><label>Cerca tool<input type="search" value={filter} onChange={(event) => setFilter(event.target.value)} /></label>{tools.length === 0 && <p>Non hai ancora tool API. Crea un tool per collegare più endpoint.</p>}{tools.filter((tool) => `${tool.name} ${tool.description} ${tool.project_key}`.toLowerCase().includes(filter.toLowerCase())).map((tool) => <Button variant="quiet" key={tool.id} className="api-tool-catalog-item" aria-pressed={selected?.id === tool.id} disabled={!!busy} onClick={() => { if (!dirty || window.confirm('Ci sono modifiche non salvate. Aprire un altro tool?')) void open(tool); }}><strong>{tool.name}</strong><small>{statuses[tool.status]} · {tool.project_key}</small></Button>)}</aside>
            <div className="api-tool-editor">{!draft ? <div className="api-tool-empty"><h2>Il tuo prossimo tool</h2><p>Seleziona un tool oppure creane uno da zero o da una descrizione.</p></div> : <>
                <form ref={form} onSubmit={(event) => { event.preventDefault(); void save(); }}><fieldset className="api-tool-form-fields" disabled={!!busy}><section className="api-tool-section"><div className="api-tool-section-heading"><h2>{selected ? selected.name : 'Nuovo tool API'}</h2><span className="api-tool-status">{selected ? statuses[selected.status] : 'Bozza'}{dirty ? ' · modifiche non salvate' : ''}</span></div><div className="api-tool-grid"><label>Nome tool<input required value={draft.name} onChange={(event) => setDraft({ ...draft, name: event.target.value })} /></label><div className="api-tool-field"><span>Progetto</span><SearchPicker label="Progetto tool" value={draft.project_key} options={projectOptions} onChange={(project_key) => setDraft({ ...draft, project_key })} placeholder="Seleziona progetto" /></div></div><label>Descrizione tool<textarea rows={2} required value={draft.description} onChange={(event) => setDraft({ ...draft, description: event.target.value })} /></label>
                    <details><summary>Genera da una descrizione</summary><label>Cosa deve fare il tool?<textarea rows={3} value={prompt} onChange={(event) => setPrompt(event.target.value)} placeholder="Cerca clienti, recupera i loro ordini e restituisci un riepilogo…" /></label><Button leadingIcon={<WandSparkles size={15} />} disabled={!prompt.trim() || !draft.project_key || !!busy} busy={busy === 'generate'} onClick={() => void work('generate', async () => { const generated = await apiToolsApi.generate(prompt, draft.project_key); setDraft({ ...draft, ...generated }); setResult(null); setNotice('Proposta generata. Controlla mapping e prove prima di salvare.'); })}>Genera proposta</Button></details>
                    <details><summary>Limiti ed errori</summary><div className="api-tool-grid"><label>Richieste fisiche massime<input required type="number" min="1" value={draft.definition.limits.physical_requests} onChange={(event) => setDraft({ ...draft, definition: { ...draft.definition, limits: { ...draft.definition.limits, physical_requests: Number(event.target.value) } } })} /></label><label>Timeout in secondi<input required type="number" min="1" value={draft.definition.limits.timeout_seconds} onChange={(event) => setDraft({ ...draft, definition: { ...draft.definition, limits: { ...draft.definition.limits, timeout_seconds: Number(event.target.value) } } })} /></label><label>Errori consecutivi prima della disabilitazione<input required type="number" min="1" value={draft.failure_threshold} onChange={(event) => setDraft({ ...draft, failure_threshold: Number(event.target.value) })} /></label></div></details>
                </section>
                <DefinitionEditor key={selected?.id ?? 'new'} definition={draft.definition} endpoints={endpoints} latestSteps={result?.steps && Object.keys(result.steps).length ? latestRunSteps({ steps: result.steps }) : latestRunSteps(detail?.runs[0])} onChange={(definition) => { setDraft({ ...draft, definition }); setResult(null); }} />
                <div className="api-tool-save-bar"><span>{dirty ? 'Salva la bozza prima di validare o eseguire le prove.' : 'Tutte le modifiche sono salvate.'}</span><Button type="submit" variant="primary" leadingIcon={<Save size={15} />} busy={busy === 'save'} disabled={!!busy || !draft.project_key || !dirty}>Salva bozza</Button></div></fieldset></form>
                {selected && <section className="api-tool-section"><h2>Verifica e pubblicazione</h2><div className="api-tool-actions"><Button disabled={dirty || !!busy} onClick={() => void action('validate')}>Valida</Button><Button disabled={dirty || !!busy} onClick={() => void action('test', 'simulation')}>Prova simulata</Button><Button disabled={dirty || !!busy || !liveConfirmed} onClick={() => void action('test', 'live')}>Prova live</Button><Button variant="primary" disabled={dirty || !!busy} onClick={() => void action('publish')}>Pubblica</Button><Button disabled={dirty || !!busy} onClick={() => void action(selected.status.startsWith('disabled') ? 'enable' : 'disable')}>{selected.status.startsWith('disabled') ? 'Abilita' : 'Disabilita'}</Button><Button leadingIcon={<Download size={15} />} disabled={dirty || !!busy} onClick={() => void work('export', async () => { setExportPackage(await apiToolsApi.export(selected.id)); })}>Esporta</Button></div><label className="api-tool-checkbox"><input type="checkbox" checked={liveConfirmed} onChange={(event) => setLiveConfirmed(event.target.checked)} />La prova live effettua richieste reali agli endpoint configurati.</label></section>}
                {exportPackage != null && <section className="api-tool-section"><h2>Pacchetto esportato</h2><a className="api-tool-download" href={`data:application/json;charset=utf-8,${encodeURIComponent(JSON.stringify(exportPackage, null, 2))}`} download={`${selected?.name ?? 'api-tool'}.json`}>Scarica pacchetto JSON</a><details><summary>Anteprima pacchetto</summary><pre>{JSON.stringify(exportPackage, null, 2)}</pre></details></section>}
                {result && <Diagnostics result={result} onCancel={(id) => void work('cancel', async () => { await apiToolsApi.cancel(id); setNotice('Annullamento richiesto.'); if (selected) setDetail(await apiToolsApi.show(selected.id)); })} />}
                {detail && <section className="api-tool-section"><h2>Storico e versioni</h2><Diagnostics result={{ runs: detail.runs }} onCancel={(id) => void work('cancel', async () => { await apiToolsApi.cancel(id); setDetail(await apiToolsApi.show(detail.tool.id)); })} /><details><summary>Revisioni ({detail.revisions.length}) e verifiche ({detail.verifications.length})</summary><pre>{JSON.stringify({ revisions: detail.revisions, verifications: detail.verifications }, null, 2)}</pre></details></section>}
            </>}</div>
        </div>}
    </main></AdminShell>;
}
function payload(tool: ApiTool): ToolPayload { return { name: tool.name, description: tool.description, project_key: tool.project_key, failure_threshold: tool.failure_threshold, definition: tool.definition }; }
