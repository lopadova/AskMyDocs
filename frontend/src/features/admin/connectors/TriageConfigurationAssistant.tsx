import { Sparkles } from 'lucide-react';
import { Button } from '../../../components/Button';
import type { TriageSuggestion } from './email-triage.api';
import { TriageRuleSummary, triageTypeLabels } from './TriageRuleSummary';

const examples = [
    { label: 'Assistenza clienti', prompt: 'Riconosci separatamente le lamentele e le richieste di reso, anche quando compaiono insieme. Valuta poi l’urgenza su tre livelli ordinati: ordinaria, da gestire presto oppure immediata. Una semplice richiesta di informazioni o un reso senza protesta non è una lamentela. Usa tag in italiano per ritrovare queste email.' },
    { label: 'Ordini e spedizioni', prompt: 'Distingui le email su nuovi ordini, tracking delle spedizioni, ritardi e resi. Segnala separatamente i clienti insoddisfatti. Un ritardo è urgente solo se sono indicate una scadenza vicina o conseguenze concrete.' },
    { label: 'Richieste commerciali', prompt: 'Distingui le richieste di preventivo, le domande sui prodotti e le conferme di acquisto. Segnala quando il mittente richiede esplicitamente di essere ricontattato. Usa tag preventivo, informazioni_prodotto, acquisto e ricontatto.' },
];

interface Props {
    prompt: string;
    onPrompt: (value: string) => void;
    onGenerate: () => void;
    onApply: () => void;
    onDiscard: () => void;
    suggestion: TriageSuggestion | null;
    busy: boolean;
    disabled: boolean;
    available: boolean;
    currentCount: number;
}

export function TriageConfigurationAssistant({ prompt, onPrompt, onGenerate, onApply, onDiscard, suggestion, busy, disabled, available, currentCount }: Props) {
    return <section aria-label="Assistente configurazione" style={{ display: 'grid', gap: 12, padding: 14, border: '1px solid var(--hairline)', borderRadius: 10, background: 'var(--bg-1)' }}>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}><Sparkles size={17} aria-hidden="true" /><strong>Configura con un prompt</strong></div>
        <form onSubmit={(event) => { event.preventDefault(); onGenerate(); }} style={{ display: 'grid', gap: 10 }}>
            <label style={{ display: 'grid', gap: 6 }}>Cosa vuoi riconoscere nelle email?
                <textarea value={prompt} onChange={(event) => onPrompt(event.target.value)} rows={3} maxLength={3000} minLength={3} required disabled={disabled}
                    placeholder="Per esempio: segnala i clienti insoddisfatti, distingui ordini e resi e indica le richieste urgenti."
                    style={{ width: '100%', minWidth: 0, resize: 'vertical', padding: '10px 12px', border: '1px solid var(--hairline)', borderRadius: 8, background: 'var(--panel-solid)', color: 'var(--fg-0)', font: 'inherit', boxSizing: 'border-box' }} />
            </label>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }} aria-label="Esempi di prompt">
                {examples.map((example) => <Button key={example.label} size="sm" variant="quiet" disabled={disabled} onClick={() => onPrompt(example.prompt)}>{example.label}</Button>)}
            </div>
            <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
                <Button type="submit" variant={suggestion ? 'secondary' : 'primary'} leadingIcon={<Sparkles size={15} />} busy={busy} disabled={disabled || !available || prompt.trim().length < 3}>Prepara le regole</Button>
                <span style={{ fontSize: 12, color: 'var(--fg-2)' }}>Potrai rivederle prima di salvarle.</span>
            </div>
            {!available && <p style={{ margin: 0, fontSize: 12 }}>Configura OpenRouter per usare l’assistente. Puoi già aggiungere e modificare le regole a mano.</p>}
        </form>
        {suggestion && <div role="region" style={{ display: 'grid', gap: 12, borderTop: '1px solid var(--hairline)', paddingTop: 12 }} aria-label="Proposta AI">
            <strong role="status">Proposta: {suggestion.rules.length} {suggestion.rules.length === 1 ? 'caratteristica' : 'caratteristiche'}</strong>
            <p style={{ margin: 0, fontSize: 13 }}>{suggestion.summary}</p>
            {suggestion.rules.map((rule) => <div key={rule.key} style={{ display: 'grid', gap: 6 }}><strong style={{ fontSize: 13 }}>{rule.label} <span style={{ color: 'var(--fg-2)', fontWeight: 400 }}>· {triageTypeLabels[rule.type]}</span></strong><TriageRuleSummary rule={rule} /></div>)}
            {suggestion.examples.length > 0 && <details><summary style={{ cursor: 'pointer', fontSize: 12 }}>Esempi illustrativi di email</summary>
                <p style={{ fontSize: 12, color: 'var(--fg-2)' }}>Esempi inventati con i tag attesi. Per verificare le regole, prova poi un’email importata.</p>
                {suggestion.examples.map((example, index) => <blockquote key={index} style={{ margin: '10px 0', paddingLeft: 10, borderLeft: '2px solid var(--hairline)', fontSize: 12 }}>
                    <p style={{ margin: '0 0 4px', overflowWrap: 'anywhere' }}>{example.email}</p><span style={{ color: 'var(--fg-2)' }}>Tag attesi: {example.expected_tags.join(', ') || 'nessuno'}</span>
                </blockquote>)}
            </details>}
            {currentCount > 0 && <p style={{ margin: 0, fontSize: 12 }}>Usando questa proposta sostituisci {currentCount === 1 ? 'la caratteristica' : `le ${currentCount} caratteristiche`} della bozza attuale.</p>}
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}><Button variant="primary" disabled={disabled} onClick={onApply}>Usa questa bozza</Button><Button variant="quiet" disabled={disabled} onClick={onDiscard}>Scarta proposta</Button></div>
        </div>}
    </section>;
}
