import { useEffect, useId, useRef, useState } from 'react';
import { Button } from '../../../components/Button';
import { bindingLabel, bindingSourceSchema, compatibleFieldBinding, schemaAtPath, schemaCompatible, schemaPaths, stepOutput } from './model';
import { SearchPicker } from './SearchPicker';
import type { Binding, Endpoint, Schema, ToolStep } from './types';
interface Props {
    label: string;
    binding?: Binding;
    onChange: (binding: Binding) => void;
    inputSchema: Schema;
    steps: ToolStep[];
    endpoints: Endpoint[];
    allowItem?: boolean;
    itemSchema?: Schema;
    targetSchema?: Schema;
    onRemove?: () => void;
}
export function BindingEditor({ label, binding, onChange, inputSchema, steps, endpoints, allowItem = false, itemSchema, targetSchema, onRemove }: Props) {
    const id = useId();
    const [constant, setConstant] = useState(binding?.source === 'constant' ? JSON.stringify(binding.value) ?? '' : '');
    const [constantError, setConstantError] = useState('');
    const editingConstant = useRef(false);
    const serializedConstant = binding?.source === 'constant' ? JSON.stringify(binding.value) ?? '' : '';
    useEffect(() => { if (!editingConstant.current) { setConstant(serializedConstant); setConstantError(''); } }, [serializedConstant]);
    const choices: { value: string; label: string; detail?: string; binding: Binding }[] = [
        { value: 'constant', label: 'Valore costante', detail: targetSchema?.type ? `Tipo richiesto: ${targetSchema.type}` : 'Testo, numero, booleano, oggetto o lista', binding: { source: 'constant', value: '', mode: 'value' } },
    ];
    function addFields(source: 'input' | 'step' | 'item', name: string, schema?: Schema, example?: unknown, step?: string) {
        const fields = schemaPaths(schema, example);
        if (source !== 'input') fields.unshift({ path: '', type: schema?.type ?? 'unknown', schema, example, required: false });
        for (const field of fields) {
            const next = compatibleFieldBinding(source, field, targetSchema, step);
            if (!next) continue;
            choices.push({ value: `${source}:${step ?? ''}:${field.path}`, label: `${name} · ${field.path || 'risultato completo'}${next.mode === 'index' ? ' · indice richiesto' : ''}`, detail: `${field.type}${field.example !== undefined ? ` · es. ${JSON.stringify(field.example)}` : ''}`, binding: next });
        }
    }
    addFields('input', 'Input', inputSchema);
    for (const step of steps) { const output = stepOutput(step, endpoints); addFields('step', step.name, output.schema, output.example, step.id); }
    if (allowItem) addFields('item', 'Elemento iterazione', itemSchema);
    const current = choices.find((choice) => choice.binding.source === binding?.source && choice.binding.step === binding?.step && choice.binding.path === binding?.path && (choice.binding.project_path ?? '') === (binding?.project_path ?? ''))?.value ?? bindingLabel(binding);
    const sourceSchema = binding ? bindingSourceSchema(binding, inputSchema, steps, endpoints, itemSchema) : undefined;
    const itemValueSchema = schemaAtPath(sourceSchema?.items, binding?.project_path);
    const availableModes = [
        ...(!targetSchema || schemaCompatible(targetSchema, sourceSchema) ? ['value' as const] : []),
        ...((!targetSchema || sourceSchema?.type === 'array') && (!targetSchema || schemaCompatible(targetSchema, itemValueSchema)) ? ['index' as const] : []),
        ...((!targetSchema || sourceSchema?.type === 'array') && (!targetSchema || schemaCompatible(targetSchema, { type: 'array', items: itemValueSchema })) ? ['project' as const] : []),
    ];
    function setMode(mode: Binding['mode']) {
        if (!binding) return;
        const { index: _index, project_path: _project, ...rest } = binding;
        onChange({ ...rest, mode, ...(mode !== 'value' && binding.project_path ? { project_path: binding.project_path } : {}) });
    }
    return <div className="api-tool-binding">
        <div className="api-tool-binding-heading"><strong>{label}</strong>{onRemove && <Button variant="quiet" size="sm" onClick={onRemove} aria-label={`Rimuovi mapping ${label}`}>Rimuovi</Button>}</div>
        <SearchPicker label={`Sorgente ${label}`} value={binding ? current : ''} options={choices} onChange={(value) => { const choice = choices.find((candidate) => candidate.value === value); if (choice) { onChange(choice.binding); setConstant(''); setConstantError(''); } }} placeholder="Seleziona input, costante o risultato" />
        {binding?.source === 'constant' && <label htmlFor={`${id}-constant`}>Valore costante<input id={`${id}-constant`} value={constant} onFocus={() => { editingConstant.current = true; }} onBlur={() => { editingConstant.current = false; }} placeholder={'Testo oppure JSON: 42, true, [1, 2]'} onChange={(event) => {
            const raw = event.target.value; setConstant(raw); event.target.setCustomValidity('');
            let value: unknown = raw;
            try { value = JSON.parse(raw); }
            catch { if (/^[\[{"]/.test(raw)) { setConstantError('JSON incompleto o non valido.'); event.target.setCustomValidity('JSON incompleto o non valido.'); return; } }
            const type = Array.isArray(value) ? 'array' : value === null ? 'null' : typeof value === 'number' && Number.isInteger(value) ? 'integer' : typeof value;
            if (targetSchema?.type && !schemaCompatible({ type: targetSchema.type }, { type })) { const message = `Il valore deve essere di tipo ${targetSchema.type}.`; setConstantError(message); event.target.setCustomValidity(message); return; }
            onChange({ source: 'constant', value, mode: 'value' }); setConstantError('');
        }} />{constantError && <small role="alert">{constantError}</small>}</label>}
        {binding && binding.source !== 'constant' && <>
            {targetSchema && !availableModes.includes(binding.mode ?? 'value') && <p role="alert">Questa sorgente non produce il tipo richiesto ({targetSchema.type}). Seleziona un mapping compatibile.</p>}
            {binding.mode === 'index' && <label htmlFor={`${id}-index`}>Indice (da 0)<input id={`${id}-index`} type="number" min="0" required value={binding.index ?? ''} onChange={(event) => onChange({ ...binding, index: event.target.value === '' ? undefined : Number(event.target.value) })} /></label>}
            <details className="api-tool-binding-advanced"><summary>Dettagli mapping</summary><div className="api-tool-grid">
                <label htmlFor={`${id}-path`}>Percorso<input id={`${id}-path`} value={binding.path ?? ''} placeholder="data.items" onChange={(event) => onChange({ ...binding, path: event.target.value })} /></label>
                <label htmlFor={`${id}-mode`}>Uso del valore<select id={`${id}-mode`} value={availableModes.includes(binding.mode ?? 'value') ? binding.mode ?? 'value' : ''} onChange={(event) => setMode(event.target.value as Binding['mode'])}>{!availableModes.includes(binding.mode ?? 'value') && <option value="" disabled>Mapping incompatibile con il tipo richiesto</option>}{availableModes.map((mode) => <option key={mode} value={mode}>{mode === 'value' ? 'Valore completo' : mode === 'index' ? 'Elemento a indice esplicito' : 'Proiezione di tutti gli elementi'}</option>)}</select></label>
                {(binding.mode === 'project' || binding.mode === 'index') && <label htmlFor={`${id}-project`}>{binding.mode === 'index' ? 'Percorso nell’elemento selezionato' : 'Percorso in ogni elemento'}<input id={`${id}-project`} value={binding.project_path ?? ''} placeholder="customer.id" onChange={(event) => onChange({ ...binding, project_path: event.target.value })} /></label>}
            </div><small className="api-tool-muted">{bindingLabel(binding)}</small></details>
        </>}
    </div>;
}
