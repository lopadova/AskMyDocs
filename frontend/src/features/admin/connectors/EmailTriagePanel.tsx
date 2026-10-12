import { useEffect, useRef, useState, type CSSProperties } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
import { Button } from '../../../components/Button';
import { toAdminError } from '../shared/errors';
import { emailTriageApi, type CampaignFilters, type CampaignPreview, type TriageConfig, type TriageOption, type TriagePreview, type TriageRule, type TriageSuggestion } from './email-triage.api';

import { TriageConfigurationAssistant } from './TriageConfigurationAssistant';
import { TriageRuleEditor } from './TriageRuleEditor';

const input: CSSProperties = { width: '100%', minWidth: 0, boxSizing: 'border-box', padding: '8px 10px', borderRadius: 7, border: '1px solid var(--hairline)', background: 'var(--bg-1)', color: 'var(--fg-0)', font: 'inherit' };
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
    const [assistantPrompt, setAssistantPrompt] = useState('');
    const [suggestion, setSuggestion] = useState<TriageSuggestion | null>(null);
    const rulesHeading = useRef<HTMLHeadingElement>(null);
    const focusAfterApply = useRef(false);
    useEffect(() => {
        if (status.data && draft === null) setDraft(status.data.profile ? { enabled: status.data.profile.enabled, rules: status.data.profile.rules } : { enabled: false, rules: [] });
    }, [status.data, draft]);
    useEffect(() => {
        if (focusAfterApply.current) {
            rulesHeading.current?.focus();
            rulesHeading.current?.scrollIntoView?.({ block: 'nearest' });
            focusAfterApply.current = false;
        }
    }, [draft]);
    async function run(action: string, fn: () => Promise<void>) {
        setBusy(action); setError(null); setNotice(null);
        try { await fn(); } catch (e) {
            const parsed = toAdminError(e);
            setError([parsed.message, ...Object.values(parsed.fieldErrors)].filter((v, i, all) => all.indexOf(v) === i).join(' '));
        } finally { setBusy(null); }
    }
    function updateRule(index: number, changes: Partial<TriageRule>) {
        setDraft((current) => current ? { ...current, rules: current.rules.map((rule, i) => i === index ? { ...rule, ...changes } : rule) } : current);
        setPreview(null); setCampaignPreview(null); setSuggestion(null);
    }
    function changeDraft(next: TriageConfig) { setDraft(next); setPreview(null); setCampaignPreview(null); setSuggestion(null); }
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
        <p style={{ margin: 0 }}>Riconosci le caratteristiche delle email e aggiungi una scheda con risultati e tag.</p>
        {modelChanged && <p role="status">Il modello decisionale è cambiato. Salva una nuova versione delle regole prima di avviare altre analisi.</p>}
        {error && <div role="alert" style={{ color: 'var(--danger, #dc2626)' }}>{error}</div>}
        {status.isError && <div role="alert">Aggiornamento stato fallito. <Button size="sm" onClick={() => status.refetch()}>Riprova</Button></div>}
        {notice && <p role="status">{notice}</p>}
        <TriageConfigurationAssistant prompt={assistantPrompt} onPrompt={(value) => { setAssistantPrompt(value); setSuggestion(null); }}
            available={!!readiness?.credentials} busy={busy === 'assistant'} disabled={!!busy} suggestion={suggestion} currentCount={draft.rules.length}
            onGenerate={() => void run('assistant', async () => {
                setSuggestion(null);
                setSuggestion(await emailTriageApi.suggest(installationId, assistantPrompt, draft));
            })}
            onApply={() => {
                if (!suggestion) return;
                focusAfterApply.current = true;
                changeDraft({ ...draft, rules: suggestion.rules });
                setNotice('Bozza aggiornata. Puoi modificare le caratteristiche o salvare le regole.');
            }} onDiscard={() => setSuggestion(null)} />
        <form style={stack} onInvalidCapture={(event) => {
            let node: HTMLElement | null = event.target as HTMLElement;
            while (node) { if (node instanceof HTMLDetailsElement) node.open = true; node = node.parentElement; }
        }} onSubmit={(e) => { e.preventDefault(); void run('save', async () => {
            const saved = await emailTriageApi.save(installationId, draft);
            setDraft({ enabled: saved.enabled, rules: saved.rules }); setCampaignPreview(null);
            await client.invalidateQueries({ queryKey });
            setNotice(`Regole v${saved.revision} salvate. Le email precedenti si aggiornano avviando una campagna.`);
        }); }}>
            <div style={{ ...row, justifyContent: 'space-between' }}><h3 ref={rulesHeading} tabIndex={-1} style={{ margin: 0, fontSize: 14 }}>Le tue caratteristiche</h3><span style={{ fontSize: 12, color: 'var(--fg-2)' }}>{active}/10 attive{dirty ? ' · modifiche da salvare' : profile ? ` · regole v${profile.revision}` : ''}</span></div>
            {draft.rules.length === 0 && <p style={{ margin: 0, fontSize: 13, color: 'var(--fg-2)' }}>Parti dal prompt qui sopra oppure aggiungi l’esempio “Lamentela”: riconosce un cliente insoddisfatto e aggiunge il tag lamentela.</p>}
            {draft.rules.map((rule, index) => <TriageRuleEditor key={index} rule={rule} disabled={!!busy}
                onChange={(changes) => updateRule(index, changes)} onRemove={() => changeDraft({ ...draft, rules: draft.rules.filter((_, i) => i !== index) })} />)}
            <div style={row}><Button size="sm" leadingIcon={<Plus size={14} />} disabled={!!busy || active >= 10 || draft.rules.length >= 50} onClick={() => {
                let next = draft.rules.length + 1; while (draft.rules.some((r) => r.key === `characteristic_${next}`)) next++;
                changeDraft({ ...draft, rules: [...draft.rules, newRule(next)] });
            }}>Aggiungi caratteristica</Button>
                {!draft.rules.some((rule) => rule.key === 'complaint') && <Button size="sm" disabled={!!busy || active >= 10 || draft.rules.length >= 50} onClick={() => changeDraft({ ...draft, rules: [...draft.rules, complaint()] })}>Aggiungi esempio lamentela</Button>}
            </div>
            <label style={row}><input type="checkbox" checked={draft.enabled} disabled={!!busy || (!draft.enabled && !ready)} onChange={(event) => changeDraft({ ...draft, enabled: event.target.checked })} /> Attiva triage sulle nuove email</label>
            {!ready && <p style={{ margin: 0, fontSize: 12, color: 'var(--fg-2)' }}>Per attivare il triage servono OpenRouter configurato e il servizio di analisi disponibile.</p>}
            <div style={row}>
                <Button type="submit" variant="primary" busy={busy === 'save'} disabled={!!busy || !dirty || (draft.enabled && active === 0)}>Salva regole</Button>
            </div>
        </form>
        <details><summary style={{ cursor: 'pointer', fontSize: 12 }}>Come funziona e stato del servizio</summary>
            <p style={{ fontSize: 12 }}>Il triage con Jev analizza il corpo e brevi introduzioni degli allegati dopo il download e prima dell’ingest. La scheda distingue la classificazione dal testo del mittente.</p>
            <p style={{ fontSize: 12, color: 'var(--fg-2)' }}>L’assistente invia a OpenRouter il prompt e le regole della bozza, senza leggere la casella. Per il triage delle email, email, telefoni e segreti riconoscibili vengono mascherati; il restante contenuto selezionato viene inviato al servizio esterno.</p>
            <p style={{ fontSize: 12 }}>OpenRouter: {readiness?.credentials ? 'configurato' : 'da configurare'} · Worker: {readiness?.worker ? 'disponibile' : 'non rilevato'} · Coda: {readiness?.asynchronous_queue ? 'disponibile' : 'da configurare'}</p>
        </details>
        {!!status.data?.versions.length && <details><summary>Versioni delle regole</summary><ol style={{ fontSize: 12 }}>
            {status.data.versions.map((version) => <li key={version.id}>v{version.revision} · {version.enabled ? 'attiva' : 'disattivata'} · {version.model}</li>)}
        </ol></details>}
        <details><summary style={{ cursor: 'pointer', fontWeight: 600 }}>Prova le regole su un’email</summary><section style={{ ...stack, paddingTop: 12 }} aria-label="Prova triage">
            <label>Email<select style={input} disabled={!!busy} value={documentId} onChange={(e) => setDocumentId(e.target.value)}><option value="">Scegli un’email importata</option>{status.data?.sample_documents.map((doc) => <option key={doc.id} value={doc.id}>{doc.title || `Email ${doc.id}`}</option>)}</select></label>
            <Button busy={busy === 'preview'} disabled={!!busy || !documentId || active === 0 || !readiness?.credentials} onClick={() => void run('preview', async () => setPreview(await emailTriageApi.preview(installationId, Number(documentId), draft)))}>Analizza senza aggiornare l’ingest</Button>
            {preview && <div style={stack}><div>Costo: {money(preview.usage.cost)} · Durata: {(preview.latency_ms / 1000).toFixed(2)} s · Copertura: {preview.coverage.partial ? 'parziale' : 'completa nel perimetro'}</div><pre style={{ margin: 0, whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', fontSize: 12, border: '1px solid var(--hairline)', borderRadius: 8, padding: 12 }}>{preview.card}</pre></div>}
        </section></details>
        <details open={['running', 'paused'].includes(campaign?.status ?? '')}><summary style={{ cursor: 'pointer', fontWeight: 600 }}>Analizza le email già importate{campaign ? ` · ${campaign.status === 'running' ? 'in corso' : campaign.status === 'paused' ? 'in pausa' : 'completata'}` : ''}</summary><section style={{ ...stack, paddingTop: 12 }} aria-label="Triage retroattivo">
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
        </section></details>
        <details><summary style={{ cursor: 'pointer', fontWeight: 600 }}>Stato delle ultime email</summary><section style={{ ...stack, paddingTop: 12 }} aria-label="Stato triage">
            <div style={{ fontSize: 12 }}>{Object.entries(status.data?.counts ?? {}).map(([key, count]) => `${key}: ${count}`).join(' · ') || 'Nessuna email analizzata'}</div>
            {status.data?.messages.map((message) => <div key={message.id} style={{ ...stack, borderTop: '1px solid var(--hairline)', paddingTop: 10 }}>
                <strong style={{ overflowWrap: 'anywhere' }}>{message.subject || `Email ${message.id}`}</strong><span>{message.status}{message.stale ? ' · regole obsolete' : ''} · ingest: {message.index_status ?? 'in attesa'}{message.indexed_revision != null ? ` · scheda indicizzata v${message.indexed_revision}` : ''}{message.coverage?.partial ? ' · analisi parziale' : ''}{message.error_code ? ` · ${message.error_code}` : ''}</span>
                {(message.status === 'failed' || message.index_status === 'failed') && <Button size="sm" busy={busy === `retry-${message.id}`} disabled={!!busy} onClick={() => void run(`retry-${message.id}`, async () => { await emailTriageApi.retry(installationId, message.id); await client.invalidateQueries({ queryKey }); })}>Riprova questa email</Button>}
            </div>)}
        </section></details>
    </div>;
}
