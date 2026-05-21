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
    try {
      const data = await listCollections();
      setRows(data);
      if (data.length === 0) {
        setSelectedId(null);
      } else if (selectedId === null || !data.some((r) => r.id === selectedId)) {
        setSelectedId(data[0].id);
      }
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
    const data = await listCollectionMembers(collectionId);
    setMembers(data);
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
    } finally {
      setPreviewLoading(false);
    }
  }

  return (
    <div data-testid="admin-collections-view" style={{ display: 'grid', gridTemplateColumns: '300px 1fr', gap: 18 }}>
      <section data-testid="admin-collections-list">
        <h2>Collections</h2>
        {loading ? (
          <p data-testid="admin-collections-loading">Loading...</p>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            {rows.map((row) => (
              <button
                key={row.id}
                type="button"
                data-testid={`admin-collections-row-${row.id}`}
                onClick={() => setSelectedId(row.id)}
              >
                {row.name} ({row.slug})
              </button>
            ))}
          </div>
        )}
      </section>
      <section data-testid="admin-collections-editor" style={{ display: 'grid', gap: 10 }}>
        <h2>{selectedId === null ? 'New collection' : `Edit collection #${selectedId}`}</h2>
        <input data-testid="admin-collections-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Name" />
        <input data-testid="admin-collections-slug" value={form.slug} onChange={(e) => setForm({ ...form, slug: e.target.value })} placeholder="slug" />
        <textarea data-testid="admin-collections-description" value={form.description ?? ''} onChange={(e) => setForm({ ...form, description: e.target.value || null })} placeholder="Description" />
        <textarea data-testid="admin-collections-semantic-prompt" value={form.semantic_prompt ?? ''} onChange={(e) => setForm({ ...form, semantic_prompt: e.target.value || null })} placeholder="Semantic prompt (optional)" />
        <label>
          Threshold
          <input
            data-testid="admin-collections-threshold"
            type="number"
            min={0}
            max={1}
            step={0.01}
            value={form.threshold}
            onChange={(e) => setForm({ ...form, threshold: Number(e.target.value) })}
          />
        </label>
        <div data-testid="admin-collections-preview-count">
          {previewLoading ? 'Previewing...' : `Would include ${previewCount ?? 0} document(s)`}
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button type="button" data-testid="admin-collections-create" onClick={() => void onCreate()}>Create</button>
          <button type="button" data-testid="admin-collections-save" onClick={() => void onSave()} disabled={selectedId === null}>Save</button>
          <button type="button" data-testid="admin-collections-delete" onClick={() => void onDelete()} disabled={selectedId === null}>Delete</button>
        </div>
        <div data-testid="admin-collections-members" style={{ display: 'grid', gap: 8, marginTop: 8 }}>
          <h3>Members</h3>
          <div style={{ display: 'flex', gap: 8 }}>
            <input
              data-testid="admin-collections-member-doc-id"
              type="number"
              min={1}
              value={memberDocId}
              onChange={(e) => setMemberDocId(e.target.value)}
              placeholder="Knowledge document id"
              disabled={selectedId === null}
            />
            <button type="button" data-testid="admin-collections-member-add" onClick={() => void onAddMember()} disabled={selectedId === null}>
              Add member
            </button>
          </div>
          <div style={{ display: 'grid', gap: 6 }}>
            {members.map((member) => (
              <div key={member.id} data-testid={`admin-collections-member-row-${member.id}`} style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
                <span>
                  #{member.knowledge_document_id} {member.document?.slug ?? member.document?.title ?? 'unknown'} [{member.reason}]
                  {member.manually_excluded ? ' (excluded)' : ''}
                </span>
                <button
                  type="button"
                  data-testid={`admin-collections-member-remove-${member.knowledge_document_id}`}
                  onClick={() => void onRemoveMember(member.knowledge_document_id)}
                  disabled={selectedId === null}
                >
                  Remove
                </button>
              </div>
            ))}
          </div>
        </div>
      </section>
    </div>
  );
}

