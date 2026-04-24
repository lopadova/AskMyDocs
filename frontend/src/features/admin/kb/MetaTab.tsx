import type { KbDocument } from '../admin.api';

/*
 * Phase G2 — Meta tab. Shows every canonical column plus the cheap
 * aggregates returned by the detail endpoint. Purely presentational
 * — no mutations here, the header actions in DocumentDetail own
 * restore / delete / force-delete.
 *
 * Tag chips come from either:
 *   - `metadata_tags` (scalar strings copied from the `metadata` JSON
 *     blob on `knowledge_documents`, R9 — never renamed), OR
 *   - `tags` pivot rows from `knowledge_document_tags`.
 * Both surfaces render here so whichever path the admin used to
 * tag the doc, the UI shows the full set.
 */

export interface MetaTabProps {
    doc: KbDocument;
}

export function MetaTab({ doc }: MetaTabProps) {
    const rows: Array<{ label: string; value: React.ReactNode; testId?: string }> = [
        { label: 'Project', value: doc.project_key, testId: 'kb-meta-project' },
        { label: 'Slug', value: doc.slug ?? '—', testId: 'kb-meta-slug' },
        { label: 'Doc ID', value: doc.doc_id ?? '—', testId: 'kb-meta-doc-id' },
        {
            label: 'Canonical type',
            value: doc.canonical_type ?? '—',
            testId: 'kb-meta-canonical-type',
        },
        {
            label: 'Canonical status',
            value: doc.canonical_status ?? '—',
            testId: 'kb-meta-canonical-status',
        },
        {
            label: 'Is canonical',
            value: doc.is_canonical ? 'yes' : 'no',
            testId: 'kb-meta-is-canonical',
        },
        {
            label: 'Retrieval priority',
            value: typeof doc.retrieval_priority === 'number'
                ? String(doc.retrieval_priority)
                : '—',
            testId: 'kb-meta-retrieval-priority',
        },
        {
            label: 'Source of truth',
            value: doc.source_of_truth ? 'yes' : 'no',
            testId: 'kb-meta-source-of-truth',
        },
        {
            label: 'Indexed at',
            value: doc.indexed_at ?? '—',
            testId: 'kb-meta-indexed-at',
        },
        {
            label: 'Deleted at',
            value: doc.deleted_at ?? '—',
            testId: 'kb-meta-deleted-at',
        },
        {
            label: 'Chunks',
            value: String(doc.chunks_count),
            testId: 'kb-meta-chunks-count',
        },
        {
            label: 'Audit events',
            value: String(doc.audits_count),
            testId: 'kb-meta-audits-count',
        },
    ];

    const tagList: string[] = [
        ...doc.metadata_tags,
        ...doc.tags.map((t) => t.name),
    ];
    const uniqueTags = Array.from(new Set(tagList));

    return (
        <div
            data-testid="kb-meta"
            style={{ display: 'flex', flexDirection: 'column', gap: 14 }}
        >
            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: '160px 1fr',
                    gap: '6px 12px',
                    fontSize: 12.5,
                }}
            >
                {rows.map((row) => (
                    <div
                        key={row.label}
                        data-testid={row.testId}
                        style={{ display: 'contents' }}
                    >
                        <div
                            style={{
                                color: 'var(--fg-3)',
                                fontFamily: 'var(--font-mono)',
                                fontSize: 11,
                                padding: '3px 0',
                            }}
                        >
                            {row.label}
                        </div>
                        <div style={{ color: 'var(--fg-1)', padding: '3px 0' }}>{row.value}</div>
                    </div>
                ))}
            </div>

            <div>
                <div
                    style={{
                        fontSize: 11,
                        color: 'var(--fg-3)',
                        fontFamily: 'var(--font-mono)',
                        marginBottom: 6,
                    }}
                >
                    Tags
                </div>
                {uniqueTags.length === 0 ? (
                    <div
                        data-testid="kb-meta-tags-empty"
                        style={{ fontSize: 12, color: 'var(--fg-3)' }}
                    >
                        No tags.
                    </div>
                ) : (
                    <div
                        data-testid="kb-meta-tags"
                        style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}
                    >
                        {uniqueTags.map((name) => (
                            <span
                                key={name}
                                data-testid={`kb-meta-tag-${name}`}
                                style={{
                                    padding: '2px 8px',
                                    borderRadius: 999,
                                    border: '1px solid var(--hairline)',
                                    background: 'var(--bg-0)',
                                    fontSize: 11,
                                    fontFamily: 'var(--font-mono)',
                                    color: 'var(--fg-2)',
                                }}
                            >
                                #{name}
                            </span>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
