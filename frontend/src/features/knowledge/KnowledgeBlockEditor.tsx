import { useEffect, useState } from 'react';
import { Button } from '../../components/Button';
import { SelectField, SelectOption } from '../../components/ui/select-field';
import { useUiTranslation } from '../../lib/ui-locale';
import type { BlockKey } from './knowledge.api';

type Row = Record<string, unknown>;
type Field = { key: string; label: string; list?: boolean; long?: boolean; choices?: string[] };
const schemas: Record<BlockKey, { key: string; title: string; fields: Field[] }[]> = {
    types: [
        { key: 'entities', title: 'Entity types', fields: [{ key: 'key', label: 'Type key' }, { key: 'properties', label: 'Properties', list: true }] },
        { key: 'relations', title: 'Relations', fields: [{ key: 'key', label: 'Relation key' }, { key: 'from', label: 'From type' }, { key: 'to', label: 'To type' }] },
    ],
    identity: [{ key: 'namespaces', title: 'Identifier namespaces', fields: [{ key: 'key', label: 'Namespace' }, { key: 'type', label: 'Entity type' }, { key: 'pattern', label: 'Recognition pattern' }, { key: 'strategy', label: 'Identity strategy', choices: ['explicit', 'source'] }] }],
    admission: [{ key: 'rules', title: 'Admission rules', fields: [{ key: 'key', label: 'Rule key' }, { key: 'instructions', label: 'Admission instructions', long: true }] }],
    contributions: [{ key: 'destinations', title: 'Destinations', fields: [{ key: 'rule', label: 'Admission rule' }, { key: 'types', label: 'Destination types', list: true }] }],
};

function StringList({ value, onCommit, label, disabled }: { value: unknown; onCommit: (value: string[]) => void; label: string; disabled: boolean }) {
    const text = Array.isArray(value) ? value.join(', ') : '';
    const [draft, setDraft] = useState(text);
    useEffect(() => setDraft(text), [text]);
    return <input aria-label={label} disabled={disabled} value={draft} onChange={e => setDraft(e.target.value)} onBlur={() => onCommit(draft.split(',').map(v => v.trim()).filter(Boolean))} />;
}

/** Structured fields cover the contract; JSON remains available for precise advanced edits. */
export function KnowledgeBlockEditor({ block, value, disabled, onChange }: { block: BlockKey; value: string; disabled: boolean; onChange: (value: string) => void }) {
    const { t } = useUiTranslation();
    let data: Row | null = null;
    try { const parsed: unknown = JSON.parse(value); if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) data = parsed as Row; } catch { /* The advanced editor keeps invalid text editable. */ }
    const update = (patch: Row) => onChange(JSON.stringify({ ...data, ...patch }, null, 2));
    return <div>
        {data && schemas[block].map(group => {
            const rows: Row[] = Array.isArray(data![group.key]) ? (data![group.key] as unknown[]).filter((r): r is Row => !!r && typeof r === 'object' && !Array.isArray(r)) : [];
            const changeRow = (index: number, field: string, next: unknown) => update({ [group.key]: rows.map((row, i) => i === index ? { ...row, [field]: next } : row) });
            return <div className="knowledge-contract-group" key={group.key}>
                <h4>{t(group.title)}</h4>
                {rows.map((row, index) => <div className="knowledge-contract-row" key={index}>
                    {group.fields.map(field => <label key={field.key}>{t(field.label)}
                        {field.list ? <StringList value={row[field.key]} disabled={disabled} label={t(group.title) + ' ' + (index + 1) + ' · ' + t(field.label)} onCommit={next => changeRow(index, field.key, next)} />
                            : field.choices ? <SelectField aria-label={t(group.title) + ' ' + (index + 1) + ' · ' + t(field.label)} disabled={disabled} value={String(row[field.key] ?? field.choices[0])} onValueChange={next => changeRow(index, field.key, next)}>{field.choices.map(v => <SelectOption key={v} value={v}>{t(v === 'source' ? 'Source identity' : 'Explicit identifier')}</SelectOption>)}</SelectField>
                                : field.long ? <textarea rows={3} disabled={disabled} value={String(row[field.key] ?? '')} onChange={e => changeRow(index, field.key, e.target.value)} />
                                    : <input disabled={disabled} value={String(row[field.key] ?? '')} onChange={e => changeRow(index, field.key, e.target.value)} />}
                    </label>)}
                    <Button size="sm" variant="quiet" disabled={disabled} aria-label={t('Remove') + ' ' + t(group.title) + ' ' + (index + 1)} onClick={() => update({ [group.key]: rows.filter((_, i) => i !== index) })}>{t('Remove')}</Button>
                </div>)}
                <Button size="sm" variant="secondary" disabled={disabled} onClick={() => update({ [group.key]: [...rows, Object.fromEntries(group.fields.map(field => [field.key, field.list ? [] : field.choices?.[0] ?? '']))] })}>{t('Add')} · {t(group.title)}</Button>
            </div>;
        })}
        {data && (block === 'identity' || block === 'contributions') && <label>{t('Instructions')}<textarea rows={3} disabled={disabled} value={String(data.instructions ?? '')} onChange={e => update({ instructions: e.target.value })} /></label>}
        {data && block === 'admission' && <fieldset>
            <legend>{t('Measured calibration')}</legend>
            <label>{t('Calibration reference')}<input disabled={disabled} value={String((data.calibration as Row | null)?.reference ?? '')} onChange={e => update({ calibration: e.target.value === '' ? null : { ...(data!.calibration as Row ?? {}), reference: e.target.value } })} /></label>
            <label>{t('Measured threshold')}<input type="number" step="0.01" min="0.51" max="1" disabled={disabled} value={String((data.calibration as Row | null)?.threshold ?? '')} onChange={e => update({ calibration: { ...(data!.calibration as Row ?? {}), threshold: e.target.value === '' ? null : Number(e.target.value) } })} /></label>
            <label>{t('Judge version')}<input disabled={disabled} value={String((data.calibration as Row | null)?.judge_version ?? '')} onChange={e => update({ calibration: { ...(data!.calibration as Row ?? {}), judge_version: e.target.value } })} /></label>
            <label>{t('Calibrated model')}<input disabled={disabled} value={String((data.calibration as Row | null)?.model ?? '')} onChange={e => update({ calibration: { ...(data!.calibration as Row ?? {}), model: e.target.value } })} /></label>
        </fieldset>}
        <details open={!data && value !== 'null'}><summary>{t('Advanced JSON')}</summary><textarea aria-label={block} rows={10} spellCheck={false} disabled={disabled} value={value} onChange={e => onChange(e.target.value)} /></details>
    </div>;
}
