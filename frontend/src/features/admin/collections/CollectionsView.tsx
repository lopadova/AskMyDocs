import { Button } from '../../../components/Button';
import { Icon } from '../../../components/Icons';
import { EditorialArtwork } from '../../../components/editorial/EditorialArtwork';
import { useUiTranslation } from "../../../lib/ui-locale";
import { useEffect, useMemo, useState } from 'react';
import {
  addCollectionMember,
  createCollection,
  deleteCollection,
  listCollectionMembers,
  listCollections,
  previewCollection,
  removeCollectionMember,
  type KbCollection,
  type KbCollectionMember,
  type KbCollectionPayload,
  updateCollection,
} from './collections.api';

const EMPTY_FORM: KbCollectionPayload = {
  slug: '',
  name: '',
  description: null,
  visibility: 'private',
  criteria: {},
  semantic_prompt: null,
  threshold: 0.75,
};

export function CollectionsView() {
    const { t: translateInterface } = useUiTranslation();
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  async function run(action: () => Promise<void>) {
    setBusy(true); setError(null);
    try { await action(); } catch { setError('The change could not be saved. Check the fields and try again.'); }
    finally { setBusy(false); }
  }
  const [rows, setRows] = useState<KbCollection[]>([]);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [form, setForm] = useState<KbCollectionPayload>(EMPTY_FORM);
  const [loading, setLoading] = useState(true);
  const [members, setMembers] = useState<KbCollectionMember[]>([]);
  const [memberDocId, setMemberDocId] = useState('');
  const [previewCount, setPreviewCount] = useState<number | null>(null);
  const [previewLoading, setPreviewLoading] = useState(false);

  useEffect(() => {
    void refresh();
  }, []);

  const selected = useMemo(() => rows.find((r) => r.id === selectedId) ?? null, [rows, selectedId]);

  useEffect(() => {
    if (selected === null) {
      setForm(EMPTY_FORM);
      return;
    }
    setForm({
      slug: selected.slug,
      name: selected.name,
      description: selected.description,
      visibility: selected.visibility,
      criteria: selected.criteria ?? {},
      semantic_prompt: selected.semantic_prompt,
      threshold: selected.threshold,
    });
  }, [selected]);

  useEffect(() => {
    if (selectedId === null) {
      setMembers([]);
      return;
    }
    void refreshMembers(selectedId);
  }, [selectedId]);

  useEffect(() => {
    if (selectedId === null) {
      setPreviewCount(null);
      return;
    }
    void refreshPreview();
  }, [selectedId, form.criteria, form.semantic_prompt, form.threshold]);

  async function refresh(): Promise<void> {
    setLoading(true);
    setError(null);
    try {
      const data = await listCollections();
      setRows(data);
      if (data.length === 0) {
        setSelectedId(null);
      } else if (selectedId === null || !data.some((r) => r.id === selectedId)) {
        setSelectedId(data[0].id);
      }
    } catch {
      setError('Collections could not be loaded. Please try again.');
    } finally {
      setLoading(false);
    }
  }

  async function onCreate(): Promise<void> {
    const created = await createCollection(form);
    setRows((prev) => [created, ...prev]);
    setSelectedId(created.id);
  }

  async function onSave(): Promise<void> {
    if (selectedId === null) return;
    const updated = await updateCollection(selectedId, form);
    setRows((prev) => prev.map((row) => (row.id === updated.id ? updated : row)));
  }

  async function onDelete(): Promise<void> {
    if (selectedId === null) return;
    await deleteCollection(selectedId);
    await refresh();
  }

  async function refreshMembers(collectionId: number): Promise<void> {
    try { const data = await listCollectionMembers(collectionId); setMembers(data); }
    catch { setError('Collection members could not be loaded. Please try again.'); }
  }

  async function onAddMember(): Promise<void> {
    if (selectedId === null) return;
    const knowledgeDocumentId = Number(memberDocId);
    if (!Number.isInteger(knowledgeDocumentId) || knowledgeDocumentId < 1) return;
    await addCollectionMember(selectedId, knowledgeDocumentId);
    setMemberDocId('');
    await refreshMembers(selectedId);
  }

  async function onRemoveMember(knowledgeDocumentId: number): Promise<void> {
    if (selectedId === null) return;
    await removeCollectionMember(selectedId, knowledgeDocumentId);
    await refreshMembers(selectedId);
  }

  async function refreshPreview(): Promise<void> {
    setPreviewLoading(true);
    try {
      const count = await previewCollection({
        criteria: form.criteria,
        semantic_prompt: form.semantic_prompt,
        threshold: form.threshold,
      });
      setPreviewCount(count);
    } catch {
      setError('The collection preview could not be loaded.');
    } finally {
      setPreviewLoading(false);
    }
  }

  const valid = form.name.trim() !== '' && form.name.length <= 160 && form.slug.length <= 120 && /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(form.slug) && form.threshold >= 0 && form.threshold <= 1;
  return (
    <div className="collections-workspace" data-testid="admin-collections-view" aria-busy={loading || busy}>
      <header className="collections-header">
        <div><h1>{translateInterface('Collections')}</h1><p>{translateInterface('Group documents around a topic or a shared purpose.')}</p></div>
        <Button variant="secondary" leadingIcon={<Icon.Plus size={16} />} onClick={() => { setSelectedId(null); setForm(EMPTY_FORM); setError(null); }}>
          {translateInterface('New collection')}
        </Button>
      </header>
      {error && <div role="alert" className="collections-error">{translateInterface(error)} {error === 'Collections could not be loaded. Please try again.' && <Button variant="quiet" size="sm" onClick={() => void refresh()}>{translateInterface('Retry')}</Button>}</div>}
      <div className="collections-columns">
        <section data-testid="admin-collections-list" aria-label={translateInterface('Collections')}>
          {loading ? <p data-testid="admin-collections-loading" role="status">{translateInterface('Loading…')}</p> :
            rows.length === 0 ? <div className="collections-empty">
              <EditorialArtwork artwork="archive" />
              <h2>{translateInterface('Your first collection')}</h2>
              <p>{translateInterface('Create a collection to bring related documents together.')}</p>
            </div> :
            rows.map((row) => <Button key={row.id} variant="quiet" className="collection-row" aria-current={selectedId === row.id ? 'true' : undefined} data-testid={`admin-collections-row-${row.id}`} onClick={() => setSelectedId(row.id)}>{row.name}<small>{row.slug}</small></Button>)}
        </section>
        <section data-testid="admin-collections-editor" className="collection-editor">
          <h2>{translateInterface(selectedId === null ? 'New collection' : 'Edit collection')}</h2>
          <label>{translateInterface('Name')}<input className="input" maxLength={160} data-testid="admin-collections-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
          <label>{translateInterface('Identifier')}<input className="input" maxLength={120} aria-describedby="collection-identifier-hint" data-testid="admin-collections-slug" value={form.slug} onChange={(e) => setForm({ ...form, slug: e.target.value })} placeholder="my-collection" /></label>
          <small id="collection-identifier-hint">{translateInterface('Use lowercase letters, numbers, and single hyphens between words.')}</small>
          <label>{translateInterface('Description')}<textarea className="input" data-testid="admin-collections-description" value={form.description ?? ''} onChange={(e) => setForm({ ...form, description: e.target.value || null })} /></label>
          <details><summary>{translateInterface('Advanced settings')}</summary>
            <label>{translateInterface('Semantic prompt (optional)')}<textarea className="input" data-testid="admin-collections-semantic-prompt" value={form.semantic_prompt ?? ''} onChange={(e) => setForm({ ...form, semantic_prompt: e.target.value || null })} /></label>
            <label>{translateInterface('Threshold')}<input className="input" data-testid="admin-collections-threshold" type="number" min={0} max={1} step={0.01} value={form.threshold} onChange={(e) => setForm({ ...form, threshold: Number(e.target.value) })} /></label>
            {selectedId !== null && <div data-testid="admin-collections-preview-count" role="status">{previewLoading ? translateInterface('Previewing…') : translateInterface('Would include {count} documents', { count: previewCount ?? 0 })}</div>}
          </details>
          <div className="collection-actions">
            {selectedId === null ?
              <Button variant="primary" data-testid="admin-collections-create" busy={busy} disabled={!valid || loading} onClick={() => void run(onCreate)}>{translateInterface('Create collection')}</Button> :
              <><Button variant="primary" data-testid="admin-collections-save" busy={busy} disabled={!valid || loading} onClick={() => void run(onSave)}>{translateInterface('Save changes')}</Button>
              <Button variant="danger" data-testid="admin-collections-delete" disabled={busy} onClick={() => void run(onDelete)}>{translateInterface('Delete')}</Button></>}
          </div>
          {selectedId !== null && <div data-testid="admin-collections-members" className="collection-members">
            <h3>{translateInterface('Documents')}</h3>
            <label>{translateInterface('Knowledge document id')}<input className="input" data-testid="admin-collections-member-doc-id" type="number" min={1} value={memberDocId} onChange={(e) => setMemberDocId(e.target.value)} /></label>
            <Button variant="secondary" data-testid="admin-collections-member-add" onClick={() => void run(onAddMember)} disabled={busy || !/^[1-9]\d*$/.test(memberDocId)}>{translateInterface('Add member')}</Button>
            {members.map((member) => <div key={member.id} data-testid={`admin-collections-member-row-${member.id}`} className="collection-member">
              <span>#{member.knowledge_document_id} {member.document?.title ?? member.document?.slug ?? translateInterface('Unknown')}<small>{translateInterface(member.reason)}{member.manually_excluded ? ' · ' + translateInterface('Excluded') : ''}</small></span>
              <Button variant="quiet" size="sm" data-testid={`admin-collections-member-remove-${member.knowledge_document_id}`} onClick={() => void run(() => onRemoveMember(member.knowledge_document_id))} disabled={busy}>{translateInterface('Remove')}</Button>
            </div>)}
          </div>}
        </section>
      </div>
    </div>
  );
}

