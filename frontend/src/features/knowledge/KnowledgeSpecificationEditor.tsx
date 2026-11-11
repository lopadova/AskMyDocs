import { KnowledgeBlockEditor } from './KnowledgeBlockEditor';
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, FileSearch, RotateCw, Save, WandSparkles } from 'lucide-react';
import { Button } from '../../components/Button';
import { SelectField, SelectOption } from '../../components/ui/select-field';
import { api } from '../../lib/api';
import { useUiTranslation } from '../../lib/ui-locale';
import { useTeamStore } from '../../lib/team-store';
import { knowledgeApi, knowledgeBlocks, type Blocks, type BlockKey, type Revision } from './knowledge.api';
import './knowledge.css';

export function KnowledgeSpecificationEditor({ projects }: { projects: string[] }) {
    const { t } = useUiTranslation();
    const tenant = useTeamStore(s => s.currentTeam);
    const qc = useQueryClient();
    const [project, setProject] = useState('*');
    const [description, setDescription] = useState<string | null>(null);
    const [dirty, setDirty] = useState(false);
    const [edit, setEdit] = useState<Partial<Record<BlockKey, string>>>({});
    const [revision, setRevision] = useState<Revision | null>(null);
    const [documentQuery, setDocumentQuery] = useState('');
    const [selectedDocuments, setSelectedDocuments] = useState<{ id: number; title: string }[]>([]);
    const [sourceTypes, setSourceTypes] = useState('');
    const [prefix, setPrefix] = useState('');
    const [reprocessing, setReprocessing] = useState<{ cursor: number; selection: Record<string, unknown> } | null>(null);
    const [result, setResult] = useState<unknown>(null);
    const query = useQuery({ queryKey: ['knowledge-specifications', tenant, project], queryFn: () => knowledgeApi.specifications(project) });
    const runs = useQuery({ queryKey: ['knowledge-runs', tenant, project], queryFn: () => knowledgeApi.runs(project), enabled: project !== '*', refetchInterval: 10000 });
    const documents = useQuery({ queryKey: ['knowledge-preview-documents', tenant, project, documentQuery], enabled: project !== '*' && documentQuery.trim().length >= 2,
        queryFn: async () => (await api.get<{ documents?: { id: number; title: string; project_key: string }[]; data?: { id: number; title: string; project_key: string }[] }>('/api/kb/documents/search', { params: { q: documentQuery, 'project_keys[]': project } })).data });
    const action = useMutation({ mutationFn: async (work: () => Promise<unknown>) => work(), onSuccess: (value) => { setResult(value); void qc.invalidateQueries({ queryKey: ['knowledge-specifications', tenant, project] }); void qc.invalidateQueries({ queryKey: ['knowledge-runs', tenant, project] }); } });
    const current = revision ?? query.data?.versions[0];
    const descriptionValue = description ?? current?.description ?? '';
    const blocks = query.data?.template;
    const values = Object.fromEntries(knowledgeBlocks.map(key => [key, edit[key] ?? JSON.stringify(current?.blocks[key] ?? (project === '*' ? blocks?.[key] ?? {} : null), null, 2)])) as Record<BlockKey, string>;
    const load = (version: Revision) => { setRevision(version); setDescription(version.description); setEdit(Object.fromEntries(knowledgeBlocks.map(key => [key, JSON.stringify(version.blocks[key], null, 2)]))); setResult(null); setDirty(false); };
    const pendingEdits = dirty;
    const save = async () => {
        const parsed = Object.fromEntries(knowledgeBlocks.map(key => [key, JSON.parse(values[key])])) as Blocks;
        const saved = await knowledgeApi.draft(project, descriptionValue, parsed);
        load(saved); return { revision_id: saved.id };
    };
    const reprocess = async (continuation = false) => {
        const selection = continuation && reprocessing ? reprocessing.selection : {
            ...(selectedDocuments.length ? { document_ids: selectedDocuments.map(d => d.id) } : {}),
            ...(sourceTypes.trim() ? { source_types: sourceTypes.split(',').map(s => s.trim()).filter(Boolean) } : {}),
            ...(prefix ? { path_prefix: prefix } : {}),
        };
        const response = await knowledgeApi.reprocess(project, { ...selection, ...(continuation && reprocessing ? { after_id: reprocessing.cursor } : {}) });
        setReprocessing(typeof response.next_cursor === 'number' ? { cursor: response.next_cursor, selection } : null);
        return response;
    };
    return <section className="knowledge-panel" aria-label={t('Knowledge specification')} data-testid="knowledge-specification-editor">
        <h2>{t('Knowledge specification')}</h2>
        <p>{t('Describe how your company organizes knowledge, review each block, try selected sources and activate a revision. Projects inherit each company block unless explicitly replaced. Activation never reprocesses history.')}</p>
        <label>{t('Scope')}<SelectField aria-label={t('Specification scope')} value={project} disabled={action.isPending} onValueChange={p => { setProject(p); setEdit({}); setRevision(null); setDescription(null); setDirty(false); setSelectedDocuments([]); setReprocessing(null); setResult(null); }}>
            <SelectOption value="*">{t('Company defaults')}</SelectOption>{projects.map(p => <SelectOption value={p} key={p}>{p}</SelectOption>)}
        </SelectField></label>
        {query.isPending && <p role="status">{t('Loading…')}</p>}
        {query.isError && <p role="alert">{t('Could not load knowledge specifications.')}</p>}
        {query.data && <>
            <p>{query.data.scope?.enabled ? t('Active revision') + ': ' + query.data.scope.active_revision_id : t('Legacy behavior: no active project specification.')}</p>
            <label>{t('Description in Italian')}<textarea rows={4} value={descriptionValue} onChange={e => { setDescription(e.target.value); setDirty(true); }} /></label>
            <Button variant="secondary" leadingIcon={<WandSparkles size={15} />} busy={action.isPending} disabled={!descriptionValue.trim()} onClick={() => action.mutate(async () => { const generated = await knowledgeApi.generate(descriptionValue); setEdit(Object.fromEntries(knowledgeBlocks.map(key => [key, JSON.stringify(generated[key], null, 2)]))); setDirty(true); return { generated: true }; })}>{t('Generate draft')}</Button>
            <div className="knowledge-blocks">{knowledgeBlocks.map(key => <fieldset key={key}>
                <legend>{t(({ types: 'Types, properties and relations', identity: 'Identifiers and recognition', admission: 'Admission and extraction rules', contributions: 'Contribution destinations' })[key])}</legend>
                {project !== '*' && <label className="knowledge-inline"><input type="checkbox" checked={values[key] === 'null'} onChange={e => { setEdit(v => ({ ...v, [key]: e.target.checked ? 'null' : JSON.stringify(query.data?.effective?.blocks[key] ?? blocks?.[key], null, 2) })); setDirty(true); }} />{t('Inherit company block')}</label>}
                <KnowledgeBlockEditor block={key} disabled={project !== '*' && values[key] === 'null'} value={values[key]} onChange={value => { setEdit(v => ({ ...v, [key]: value })); setDirty(true); }} />
            </fieldset>)}</div>
            <p>{t('Semantic publication requires a threshold measured on labeled examples and its calibration reference in admission.calibration. Uncertain or unavailable judgments abstain; documents remain searchable.')}</p>
            <div className="knowledge-actions">
                <Button variant="primary" leadingIcon={<Save size={15} />} busy={action.isPending} onClick={() => action.mutate(save)}>{t('Save new revision')}</Button>
                <Button variant="secondary" leadingIcon={<Check size={15} />} busy={action.isPending} disabled={!current || pendingEdits} onClick={() => action.mutate(() => knowledgeApi.activate(project, current!.id))}>{t('Activate revision')}</Button>
                <Button variant="quiet" busy={action.isPending} disabled={!query.data.scope?.enabled} onClick={() => action.mutate(() => knowledgeApi.disable(project))}>{t('Stop enrichment')}</Button>
            </div>
            <details><summary>{t('Revision history')}</summary><ul>{query.data.versions.map(v => <li key={v.id}><Button variant="quiet" onClick={() => load(v)}>{t('Revision')} {v.id}{v.activated_at ? ' · ' + t('Previously activated') : ''}</Button></li>)}</ul></details>
            {project !== '*' && <>
                <h3>{t('Try selected sources')}</h3>
                <label>{t('Find documents')}<input value={documentQuery} onChange={e => setDocumentQuery(e.target.value)} /></label>
                <div className="knowledge-actions">{(documents.data?.documents ?? documents.data?.data ?? []).slice(0, 10).map(d => <Button key={d.id} variant="quiet" disabled={selectedDocuments.some(v => v.id === d.id) || selectedDocuments.length >= 5} onClick={() => setSelectedDocuments(v => [...v, d])}>{d.title}</Button>)}</div>
                <ul>{selectedDocuments.map(d => <li key={d.id}><Button variant="quiet" aria-label={t('Remove source') + ': ' + d.title} onClick={() => setSelectedDocuments(v => v.filter(x => x.id !== d.id))}>{d.title} · {t('Remove')}</Button></li>)}</ul>
                <Button variant="secondary" leadingIcon={<FileSearch size={15} />} busy={action.isPending} disabled={!current || pendingEdits || selectedDocuments.length === 0} onClick={() => action.mutate(() => knowledgeApi.preview(project, current!.id, selectedDocuments.map(d => d.id)))}>{t('Preview contributions')}</Button>
                <h3>{t('Selective reprocessing')}</h3>
                <label>{t('Source types, separated by commas')}<input value={sourceTypes} onChange={e => setSourceTypes(e.target.value)} /></label>
                <label>{t('Source path prefix')}<input value={prefix} onChange={e => setPrefix(e.target.value)} /></label>
                <p>{t('Processes up to 100 selected sources per batch. With no source filter, processes the selected project. Existing originals are preserved.')}</p>
                <Button variant="secondary" leadingIcon={<RotateCw size={15} />} busy={action.isPending} disabled={!query.data.scope?.enabled} onClick={() => action.mutate(() => reprocess())}>{t('Reprocess selected sources')}</Button>
                {reprocessing && <Button variant="secondary" busy={action.isPending} disabled={!query.data.scope?.enabled} onClick={() => action.mutate(() => reprocess(true))}>{t('Continue with next source batch')}</Button>}
                {runs.isError && <p role="alert">{t('Could not load processing runs.')}</p>}
                {runs.data && <div className="knowledge-table"><table><caption>{t('Recent processing runs')}</caption><thead><tr><th>{t('Source')}</th><th>{t('Status')}</th><th>{t('Coverage')}</th><th>{t('Abstentions')}</th></tr></thead><tbody>{runs.data.map(r => <tr key={r.id}><td>{r.knowledge_document_id}</td><td>{r.status}{r.error && <span role="status"> · {r.error}</span>}</td><td>{r.processed_sections}/{r.total_sections}</td><td>{r.abstained_sections}</td></tr>)}</tbody></table></div>}
            </>}
        </>}
        {action.isError && <p role="alert">{action.error.message}</p>}
        {result !== null && <details open><summary>{t('Result')}</summary><pre data-testid="knowledge-operation-result">{JSON.stringify(result, null, 2)}</pre></details>}
    </section>;
}
