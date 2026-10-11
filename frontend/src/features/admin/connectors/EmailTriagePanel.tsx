import { useEffect, useState, type CSSProperties } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Trash2 } from 'lucide-react';
import { Button } from '../../../components/Button';
import { toAdminError } from '../shared/errors';
import { emailTriageApi, type CampaignFilters, type CampaignPreview, type TriageConfig, type TriageOption, type TriagePreview, type TriageRule } from './email-triage.api';

const input: CSSProperties = { width: '100%', minWidth: 0, padding: '8px 10px', borderRadius: 7, border: '1px solid var(--hairline)', background: 'var(--bg-1)', color: 'var(--fg-0)', font: 'inherit' };
const stack: CSSProperties = { display: 'grid', gap: 12 };
const row: CSSProperties = { display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' };
const option = (key: string, label: string): TriageOption => ({ key, label, criterion: label, text: label, tag: '' });
function newRule(index: number): TriageRule {
    return { key: `characteristic_${index}`, label: 'Nuova caratteristica', type: 'noul', enabled: true, threshold: 0.85,
        instructions: 'Indica se la caratteristica è presente nel contenuto.', options: [option('true', 'Presente'), option('false', 'Assente')] };
}
function complaint(): TriageRule {
    return { ...newRule(1), key: 'complaint', label: 'Lamentela', instructions: 'La persona esprime insoddisfazione, protesta o segnala un disservizio, anche senza usare la parola lamentela?',
        options: [{ ...option('true', 'Presente'), criterion: 'Insoddisfazione, protesta o disservizio riconoscibile nel contenuto.', text: 'Lamentela presente', tag: 'lamentela' },
            { ...option('false', 'Assente'), criterion: 'Richiesta ordinaria senza insoddisfazione o protesta.', text: 'Lamentela assente' }] };
}
const money = (value: number | null | undefined) => value == null ? 'Non disponibile: manca una misura o tariffa configurata' : `$${value.toFixed(6)}`;

export function EmailTriagePanel({ installationId }: { installationId: number }) {
    const client = useQueryClient();
    const queryKey = ['email-triage', installationId];
    const status = useQuery({ queryKey, queryFn: () => emailTriageApi.status(installationId), refetchInterval: 15000 });
    const [draft, setDraft] = useState<TriageConfig | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [documentId, setDocumentId] = useState('');
    const [preview, setPreview] = useState<TriagePreview | null>(null);
    const [filters, setFilters] = useState<CampaignFilters>({ project: '', mailbox: '', from: '', to: '', state: 'never' });
    const [campaignPreview, setCampaignPreview] = useState<CampaignPreview | null>(null);
    useEffect(() => {
        if (status.data && draft === null) setDraft(status.data.profile ? { enabled: status.data.profile.enabled, rules: status.data.profile.rules } : { enabled: false, rules: [] });
    }, [status.data, draft]);
    async function run(action: string, fn: () => Promise<void>) {
        setBusy(action); setError(null); setNotice(null);
        try { await fn(); } catch (e) {
            const parsed = toAdminError(e);
            setError([parsed.message, ...Object.values(parsed.fieldErrors)].filter((v, i, all) => all.indexOf(v) === i).join(' '));
        } finally { setBusy(null); }
    }
    function updateRule(index: number, changes: Partial<TriageRule>) {
        setDraft((current) => current ? { ...current, rules: current.rules.map((rule, i) => i === index ? { ...rule, ...changes } : rule) } : current);
        setPreview(null);
    }
    function changeFilters(changes: Partial<CampaignFilters>) { setFilters({ ...filters, ...changes }); setCampaignPreview(null); }
    if (status.isLoading || !draft) return status.isError ? <div role="alert">{toAdminError(status.error).message}<Button onClick={() => status.refetch()}>Riprova</Button></div> : <p role="status">Caricamento triage…</p>;
    const profile = status.data?.profile;
    const readiness = status.data?.readiness;
    const ready = readiness?.credentials && readiness?.worker && readiness?.asynchronous_queue;
    const modelChanged = !!profile && !!status.data?.current_model && profile.model !== status.data.current_model;
    const dirty = modelChanged || JSON.stringify(draft) !== JSON.stringify(profile ? { enabled: profile.enabled, rules: profile.rules } : { enabled: false, rules: [] });
    const active = draft.rules.filter((rule) => rule.enabled).length;
    const campaign = status.data?.campaign;
    return <div style={stack} data-testid="email-triage-panel">
        <p style={{ margin: 0 }}>Ogni email viene analizzata dopo il download e prima dell’ingest. Il corpo ha priorità; degli allegati importati vengono lette soltanto brevi introduzioni.</p>
        <p style={{ margin: 0, fontSize: 12, color: 'var(--fg-2)' }}>L’analisi usa Jev su OpenRouter. Email, telefoni e segreti riconoscibili vengono mascherati; il restante contenuto selezionato viene inviato al servizio esterno.</p>
        <div role="status" style={{ fontSize: 12, color: 'var(--fg-2)' }}>OpenRouter: {readiness?.credentials ? 'configurato' : 'da configurare'} · Worker: {readiness?.worker ? 'disponibile' : 'non rilevato'} · Coda asincrona: {readiness?.asynchronous_queue ? 'disponibile' : 'da configurare'}{profile && ` · Regole v${profile.revision}`}</div>
        {modelChanged && <p role="status">Il modello decisionale è cambiato. Salva una nuova versione delle regole prima di avviare altre analisi.</p>}
        {error && <div role="alert" style={{ color: 'var(--danger, #dc2626)' }}>{error}</div>}
        {status.isError && <div role="alert">Aggiornamento stato fallito. <Button size="sm" onClick={() => status.refetch()}>Riprova</Button></div>}
        {notice && <p role="status">{notice}</p>}
        <form style={stack} onSubmit={(e) => { e.preventDefault(); void run('save', async () => {
            const saved = await emailTriageApi.save(installationId, draft);
            setDraft({ enabled: saved.enabled, rules: saved.rules }); setCampaignPreview(null);
            await client.invalidateQueries({ queryKey });
            setNotice(`Regole v${saved.revision} salvate. Le email precedenti si aggiornano avviando una campagna.`);
        }); }}>
            <label style={row}><input type="checkbox" checked={draft.enabled} disabled={!!busy || (!draft.enabled && !ready)} onChange={(e) => setDraft({ ...draft, enabled: e.target.checked })} /> Attiva triage sulle nuove email</label>
            <p style={{ margin: 0, fontSize: 12 }}>Caratteristiche attive: {active}/10. La soglia iniziale 0,85 va verificata su esempi reali; sotto soglia il risultato è “non determinabile”.</p>
            {draft.rules.map((rule, index) => <details key={index} open={draft.rules.length === 1} style={{ border: '1px solid var(--hairline)', padding: 12, borderRadius: 9 }}>
                <summary style={{ cursor: 'pointer', fontWeight: 600 }}>{rule.label || rule.key} · {rule.enabled ? 'attiva' : 'disattivata'}</summary>
                <fieldset disabled={!!busy} style={{ ...stack, border: 0, margin: '12px 0 0', padding: 0 }}>
                    <div style={row}><label><input type="checkbox" checked={rule.enabled} onChange={(e) => updateRule(index, { enabled: e.target.checked })} /> Attiva</label><Button size="sm" variant="quiet" leadingIcon={<Trash2 size={14} />} onClick={() => setDraft({ ...draft, rules: draft.rules.filter((_, i) => i !== index) })}>Rimuovi caratteristica</Button></div>
                    <label>Nome<input style={input} value={rule.label} required maxLength={100} onChange={(e) => updateRule(index, { label: e.target.value })} /></label>
                    <label>Chiave stabile<input style={input} value={rule.key} required pattern="[a-z][a-z0-9_]{0,63}" onChange={(e) => updateRule(index, { key: e.target.value })} /></label>
                    <label>Tipo<select style={input} value={rule.type} onChange={(e) => {
                        const type = e.target.value as TriageRule['type'];
                        updateRule(index, { type, options: type === 'noul' ? [option('true', 'Presente'), option('false', 'Assente')] : [option('low', 'Bassa'), option('high', 'Alta')] });
                    }}><option value="noul">Tag sì/no</option><option value="choice">Categoria</option><option value="score">Livelli ordinati</option></select></label>
                    <label>Istruzioni<textarea style={input} rows={2} value={rule.instructions} required maxLength={800} onChange={(e) => updateRule(index, { instructions: e.target.value })} /></label>
                    <label>Soglia di confidenza<input style={input} type="number" min={0.51} max={1} step={0.01} value={rule.threshold} required onChange={(e) => updateRule(index, { threshold: Number(e.target.value) })} /></label>
                    {rule.options.map((value, optionIndex) => {
                        const edit = (changes: Partial<TriageOption>) => updateRule(index, { options: rule.options.map((existing, i) => i === optionIndex ? { ...existing, ...changes } : existing) });
                        return <fieldset key={optionIndex} style={{ ...stack, border: '1px solid var(--hairline)', padding: 10 }}>
                            <legend>{value.label || `Opzione ${optionIndex + 1}`}</legend>
                            <label>Chiave opzione<input style={input} value={value.key} required disabled={rule.type === 'noul'} onChange={(e) => edit({ key: e.target.value })} /></label>
                            <label>Etichetta<input style={input} value={value.label} required onChange={(e) => edit({ label: e.target.value })} /></label>
                            <label>Criterio<textarea style={input} rows={2} value={value.criterion} required maxLength={500} onChange={(e) => edit({ criterion: e.target.value })} /></label>
                            <label>Testo nella scheda<input style={input} value={value.text} required maxLength={500} onChange={(e) => edit({ text: e.target.value })} /></label>
                            <label>Tag di ricerca facoltativo<input style={input} value={value.tag ?? ''} maxLength={100} onChange={(e) => edit({ tag: e.target.value })} /></label>
                            {rule.type !== 'noul' && rule.options.length > 2 && <Button size="sm" variant="quiet" onClick={() => updateRule(index, { options: rule.options.filter((_, i) => i !== optionIndex) })}>Rimuovi opzione</Button>}
                        </fieldset>;
                    })}
                    {rule.type !== 'noul' && <Button size="sm" disabled={rule.options.length >= 20} onClick={() => {
                        let next = rule.options.length + 1; while (rule.options.some((value) => value.key === `option_${next}`)) next++;
                        updateRule(index, { options: [...rule.options, option(`option_${next}`, 'Nuova opzione')] });
                    }}>Aggiungi opzione</Button>}
                </fieldset>
            </details>)}
            <div style={row}><Button size="sm" leadingIcon={<Plus size={14} />} disabled={!!busy || active >= 10} onClick={() => {
                let next = draft.rules.length + 1; while (draft.rules.some((r) => r.key === `characteristic_${next}`)) next++;
                setDraft({ ...draft, rules: [...draft.rules, newRule(next)] });
            }}>Aggiungi caratteristica</Button>
                {!draft.rules.some((rule) => rule.key === 'complaint') && <Button size="sm" disabled={!!busy || active >= 10} onClick={() => setDraft({ ...draft, rules: [...draft.rules, complaint()] })}>Aggiungi esempio lamentela</Button>}
                <Button type="submit" variant="primary" busy={busy === 'save'} disabled={!!busy || !dirty || (draft.enabled && active === 0)}>Salva regole</Button>
            </div>
        </form>
        {!!status.data?.versions.length && <details><summary>Versioni delle regole</summary><ol style={{ fontSize: 12 }}>
            {status.data.versions.map((version) => <li key={version.id}>v{version.revision} · {version.enabled ? 'attiva' : 'disattivata'} · {version.model}</li>)}
        </ol></details>}
        <section style={stack} aria-label="Prova triage">
            <h3 style={{ marginBottom: 0 }}>Prova su un’email</h3>
            <label>Email<select style={input} value={documentId} onChange={(e) => setDocumentId(e.target.value)}><option value="">Scegli un’email importata</option>{status.data?.sample_documents.map((doc) => <option key={doc.id} value={doc.id}>{doc.title || `Email ${doc.id}`}</option>)}</select></label>
            <Button busy={busy === 'preview'} disabled={!!busy || !documentId || active === 0 || !readiness?.credentials} onClick={() => void run('preview', async () => setPreview(await emailTriageApi.preview(installationId, Number(documentId), draft)))}>Analizza senza aggiornare l’ingest</Button>
            {preview && <div style={stack}><div>Costo: {money(preview.usage.cost)} · Durata: {(preview.latency_ms / 1000).toFixed(2)} s · Copertura: {preview.coverage.partial ? 'parziale' : 'completa nel perimetro'}</div><pre style={{ margin: 0, whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', fontSize: 12, border: '1px solid var(--hairline)', borderRadius: 8, padding: 12 }}>{preview.card}</pre></div>}
        </section>
        <section style={stack} aria-label="Triage retroattivo">
            <h3 style={{ marginBottom: 0 }}>Email già importate</h3>
            <label>Stato<select style={input} value={filters.state} onChange={(e) => changeFilters({ state: e.target.value as CampaignFilters['state'] })}><option value="never">Mai analizzate</option><option value="stale">Regole obsolete</option><option value="failed">In errore</option><option value="all">Tutte</option></select></label>
            <label>Progetto<input style={input} value={filters.project} onChange={(e) => changeFilters({ project: e.target.value })} placeholder="Tutti i progetti dell’account" /></label>
            <label>Cartella<input style={input} value={filters.mailbox} onChange={(e) => changeFilters({ mailbox: e.target.value })} placeholder="Tutte le cartelle" /></label>
            <div style={row}><label style={{ flex: 1 }}>Dal<input style={input} type="date" value={filters.from} onChange={(e) => changeFilters({ from: e.target.value })} /></label><label style={{ flex: 1 }}>Al<input style={input} type="date" value={filters.to} onChange={(e) => changeFilters({ to: e.target.value })} /></label></div>
            <Button busy={busy === 'campaign-preview'} disabled={!!busy || dirty} onClick={() => void run('campaign-preview', async () => setCampaignPreview(await emailTriageApi.campaignPreview(installationId, filters)))}>Calcola anteprima</Button>
            {dirty && <p style={{ margin: 0, fontSize: 12 }}>Salva le regole prima di preparare una campagna.</p>}
            {campaignPreview && <div style={stack}>
                <div>{campaignPreview.total.toLocaleString()} email selezionate. Sorgenti verificate: {campaignPreview.availability_sample.checked}; disponibili: {campaignPreview.availability_sample.available}; mancanti: {campaignPreview.availability_sample.unavailable}; ancora da verificare: {campaignPreview.availability_sample.unchecked}.</div>
                <div>Stima triage: {money(campaignPreview.estimated_decision_usd)}<br />Stima reindicizzazione: {money(campaignPreview.estimated_reindex_usd)}</div>
                <Button variant="primary" busy={busy === 'start'} disabled={!!busy || dirty || !profile?.enabled || campaignPreview.total === 0 || ['running', 'paused'].includes(campaign?.status ?? '')} onClick={() => void run('start', async () => {
                    await emailTriageApi.start(installationId, filters, campaignPreview.profile_id); setCampaignPreview(null); await client.invalidateQueries({ queryKey });
                })}>Avvia triage e aggiorna ingest</Button>
            </div>}
            {campaign && <div style={stack}><div>Campagna #{campaign.id}: {campaign.status} · selezionate: {campaign.selected} · sorgenti mancanti: {campaign.unavailable} · indicizzate: {campaign.counts.completed ?? 0} · errori: {campaign.counts.failed ?? 0}</div>
                {campaign.items?.map((item) => <div key={item.id} style={row}><span>Email #{item.knowledge_document_id}: {item.status} · {item.error_code}</span><Button size="sm" busy={busy === `item-${item.id}`} disabled={!!busy} onClick={() => void run(`item-${item.id}`, async () => { await emailTriageApi.retryItem(installationId, campaign.id, item.id); await client.invalidateQueries({ queryKey }); })}>Riprova email #{item.knowledge_document_id}</Button></div>)}
                {['running', 'paused'].includes(campaign.status) && <Button busy={busy === 'control'} disabled={!!busy} onClick={() => void run('control', async () => {
                    await emailTriageApi.control(installationId, campaign.id, campaign.status === 'running' ? 'pause' : 'resume'); await client.invalidateQueries({ queryKey });
                })}>{campaign.status === 'running' ? 'Metti in pausa' : 'Riprendi'}</Button>}
            </div>}
        </section>
        <section style={stack} aria-label="Stato triage"><h3 style={{ marginBottom: 0 }}>Ultime email</h3>
            <div style={{ fontSize: 12 }}>{Object.entries(status.data?.counts ?? {}).map(([key, count]) => `${key}: ${count}`).join(' · ') || 'Nessuna email analizzata'}</div>
            {status.data?.messages.map((message) => <div key={message.id} style={{ ...stack, borderTop: '1px solid var(--hairline)', paddingTop: 10 }}>
                <strong style={{ overflowWrap: 'anywhere' }}>{message.subject || `Email ${message.id}`}</strong><span>{message.status}{message.stale ? ' · regole obsolete' : ''} · ingest: {message.index_status ?? 'in attesa'}{message.indexed_revision != null ? ` · scheda indicizzata v${message.indexed_revision}` : ''}{message.coverage?.partial ? ' · analisi parziale' : ''}{message.error_code ? ` · ${message.error_code}` : ''}</span>
                {(message.status === 'failed' || message.index_status === 'failed') && <Button size="sm" busy={busy === `retry-${message.id}`} disabled={!!busy} onClick={() => void run(`retry-${message.id}`, async () => { await emailTriageApi.retry(installationId, message.id); await client.invalidateQueries({ queryKey }); })}>Riprova questa email</Button>}
            </div>)}
        </section>
    </div>;
}
