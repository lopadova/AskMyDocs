import { bindingLabel } from './model';
import type { Binding, StepRun } from './types';
export function latestRunSteps(run?: Record<string, unknown>): StepRun[] {
    return Array.isArray(run?.steps) ? run.steps.filter((step): step is StepRun => step !== null && typeof step === 'object' && typeof step.step_id === 'string') : [];
}
export function CallDiagnostics({ run, name, isToolOutput = false }: { run: StepRun; name: string; isToolOutput?: boolean }) {
    const diagnostic = run.diagnostics ?? {};
    const inputNames = [...new Set([...Object.keys(diagnostic.inputs ?? {}), ...Object.keys(diagnostic.origins ?? {})])];
    return <section className="api-tool-call-result" aria-label={`Ultima esecuzione ${name}`}>
        <div className="api-tool-section-heading"><h3>{isToolOutput ? 'Ultimo output del tool' : 'Ultima esecuzione della chiamata'}</h3><span className="api-tool-status">{run.status}</span></div>
        {inputNames.length > 0 ? <div className="api-tool-effective-inputs"><table><thead><tr><th>Parametro</th><th>Valore effettivo</th><th>Origine</th></tr></thead><tbody>{inputNames.map((key) => <tr key={key}><th scope="row"><code>{key}</code></th><td><ValuePreview value={diagnostic.inputs?.[key]} /></td><td><OriginPreview value={diagnostic.origins?.[key]} /></td></tr>)}</tbody></table></div> : !isToolOutput && <p className="api-tool-muted">Input effettivi non ancora disponibili.</p>}
        {diagnostic.error !== undefined && <div className="api-tool-call-error" role="alert"><strong>Errore della chiamata</strong><ValuePreview value={diagnostic.error} /></div>}
        {Object.hasOwn(diagnostic, 'output') && <div className="api-tool-call-output"><h4>{isToolOutput ? 'Risultato restituito all’assistente' : 'Risultato della chiamata'}</h4><ValuePreview value={diagnostic.output} /></div>}
        <details><summary>Diagnostica completa (JSON)</summary><pre>{JSON.stringify(run, null, 2)}</pre></details>
    </section>;
}
function OriginPreview({ value }: { value: unknown }) {
    if (value && typeof value === 'object' && 'source' in value && ['input', 'constant', 'step', 'item'].includes(String(value.source))) return <span className="api-tool-origin">{bindingLabel(value as Binding)}</span>;
    return <ValuePreview value={value} />;
}
function ValuePreview({ value, depth = 0 }: { value: unknown; depth?: number }) {
    if (value === undefined) return <span className="api-tool-muted">Non disponibile</span>;
    if (value === null || typeof value !== 'object') return <span className="api-tool-value">{String(value).slice(0, 180)}{String(value).length > 180 ? '…' : ''}</span>;
    if (Array.isArray(value)) return <div className="api-tool-value"><small>{value.length} {value.length === 1 ? 'elemento' : 'elementi'}</small>{depth < 2 && value.length > 0 && <ol>{value.slice(0, 3).map((item, index) => <li key={index}><ValuePreview value={item} depth={depth + 1} /></li>)}</ol>}{depth < 2 && value.length > 3 && <small>Altri {value.length - 3} elementi nella diagnostica completa.</small>}</div>;
    const fields = Object.entries(value);
    if (depth >= 2) return <span className="api-tool-muted">Oggetto · {fields.length} campi</span>;
    return <dl className="api-tool-value-object">{fields.slice(0, 5).map(([key, item]) => <div key={key}><dt>{key}</dt><dd><ValuePreview value={item} depth={depth + 1} /></dd></div>)}{fields.length > 5 && <div><dt>Altri campi</dt><dd>{fields.length - 5}</dd></div>}</dl>;
}
