import { SelectField, SelectOption } from '@/components/ui/select-field';
import type { CSSProperties } from 'react';
import { Trash2 } from 'lucide-react';
import { Button } from '../../../components/Button';
import type { TriageOption, TriageRule } from './email-triage.api';
import { TriageRuleSummary, triageTypeLabels } from './TriageRuleSummary';

const input: CSSProperties = { width: '100%', minWidth: 0, boxSizing: 'border-box', padding: '8px 10px', borderRadius: 7, border: '1px solid var(--hairline)', background: 'var(--bg-1)', color: 'var(--fg-0)', font: 'inherit' };
const stack: CSSProperties = { display: 'grid', gap: 10 };
const row: CSSProperties = { display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' };
const option = (key: string, label: string): TriageOption => ({ key, label, criterion: label, text: label, tag: '' });

export function TriageRuleEditor({ rule, disabled, onChange, onRemove }: { rule: TriageRule; disabled: boolean; onChange: (changes: Partial<TriageRule>) => void; onRemove: () => void }) {
    const editOption = (index: number, changes: Partial<TriageOption>) => onChange({ options: rule.options.map((current, i) => i === index ? { ...current, ...changes } : current) });
    return <article style={{ ...stack, padding: 12, border: '1px solid var(--hairline)', borderRadius: 9, minWidth: 0 }}>
        <div style={row}>
            <label style={{ ...row, flex: 1 }}><input type="checkbox" checked={rule.enabled} disabled={disabled} onChange={(event) => onChange({ enabled: event.target.checked })} /><strong>{rule.label || 'Nuova caratteristica'}</strong></label>
            <span style={{ fontSize: 12, color: 'var(--fg-2)' }}>{triageTypeLabels[rule.type]}</span>
            <Button size="sm" variant="quiet" iconOnly title={`Rimuovi ${rule.label}`} aria-label={`Rimuovi ${rule.label}`} disabled={disabled} onClick={onRemove}><Trash2 size={15} /></Button>
        </div>
        <TriageRuleSummary rule={rule} />
        <details><summary style={{ cursor: 'pointer', fontSize: 12, color: 'var(--fg-1)' }}>Modifica caratteristica</summary>
            <fieldset disabled={disabled} style={{ ...stack, margin: '12px 0 0', padding: 0, border: 0 }}>
                <label>Nome<input style={input} value={rule.label} required maxLength={100} onChange={(event) => onChange({ label: event.target.value })} /></label>
                <label>Cosa deve riconoscere<textarea style={input} rows={2} value={rule.instructions} required maxLength={800} placeholder="Es.: il cliente esprime insoddisfazione per un ritardo o un disservizio." onChange={(event) => onChange({ instructions: event.target.value })} /></label>
                <label>Tipo di risultato<SelectField style={input} value={rule.type} onValueChange={(selectedValue) => {
                    const type = selectedValue as TriageRule['type'];
                    onChange({ type, options: type === 'noul' ? [option('true', 'Presente'), option('false', 'Assente')] : [option('low', 'Bassa'), option('high', 'Alta')] });
                }}><SelectOption value="noul">Presente o assente</SelectOption><SelectOption value="choice">Una categoria</SelectOption><SelectOption value="score">Livello, dal basso all’alto</SelectOption></SelectField></label>
                {rule.options.map((value, index) => <details key={index}><summary style={{ cursor: 'pointer', fontSize: 12 }}>Risultato: {value.label}</summary>
                    <div style={{ ...stack, padding: '10px 0' }}>
                        <label>Nome del risultato<input style={input} value={value.label} required maxLength={100} onChange={(event) => editOption(index, { label: event.target.value })} /></label>
                        <label>Quando riconoscerlo<textarea style={input} value={value.criterion} rows={2} required maxLength={500} placeholder="Es.: protesta, delusione o richiesta di risolvere un problema." onChange={(event) => editOption(index, { criterion: event.target.value })} /></label>
                        <label>Testo nella scheda<input style={input} value={value.text} required maxLength={500} placeholder="Es.: Lamentela presente" onChange={(event) => editOption(index, { text: event.target.value })} /></label>
                        <label>Tag di ricerca facoltativo<input style={input} value={value.tag ?? ''} maxLength={100} placeholder="Es.: lamentela" onChange={(event) => editOption(index, { tag: event.target.value })} /></label>
                        {rule.type !== 'noul' && rule.options.length > 2 && <Button size="sm" variant="quiet" onClick={() => onChange({ options: rule.options.filter((_, i) => i !== index) })}>Rimuovi risultato</Button>}
                    </div>
                </details>)}
                {rule.type !== 'noul' && <Button size="sm" disabled={disabled || rule.options.length >= 20} onClick={() => {
                    let next = rule.options.length + 1; while (rule.options.some((value) => value.key === `option_${next}`)) next++;
                    onChange({ options: [...rule.options, option(`option_${next}`, 'Nuovo risultato')] });
                }}>Aggiungi risultato</Button>}
                <details><summary style={{ cursor: 'pointer', fontSize: 12 }}>Parametri avanzati</summary><div style={{ ...stack, paddingTop: 10 }}>
                    <label>Chiave stabile<input style={input} value={rule.key} required maxLength={64} pattern="[a-z][a-z0-9_]{0,63}" onChange={(event) => onChange({ key: event.target.value })} /></label>
                    <label>Sicurezza minima (%)<input style={input} type="number" min={51} max={100} step={1} required value={Math.round(rule.threshold * 100)} onChange={(event) => onChange({ threshold: Number(event.target.value) / 100 })} /></label>
                    <p style={{ margin: 0, fontSize: 12, color: 'var(--fg-2)' }}>Parti da 85%. Se la risposta è meno sicura, la scheda riporta “non determinabile”.</p>
                    {rule.type !== 'noul' && rule.options.map((value, index) => <label key={index}>Chiave di {value.label}<input style={input} value={value.key} required maxLength={64} pattern="[a-z][a-z0-9_]{0,63}" onChange={(event) => editOption(index, { key: event.target.value })} /></label>)}
                </div></details>
            </fieldset>
        </details>
    </article>;
}
