import type { ReactNode } from 'react';
import { ChevronDown, FileText, Info } from 'lucide-react';
import type { KbDocument } from '../admin.api';
import { useKbRaw } from './kb-document.api';
import { extractFrontmatterPills } from './PreviewTab';
import { WikiLink } from '../../chat/WikilinkHover';

function display(value: unknown): string {
    if (value == null || value === '') return '—';
    if (Array.isArray(value)) return value.map(display).join(', ');
    return typeof value === 'object' ? JSON.stringify(value) : String(value);
}

export function formatDocumentDate(value: unknown): string {
    if (value == null || value === '') return '—';
    const numeric = typeof value === 'number' || (typeof value === 'string' && /^\d{10,13}$/.test(value));
    const date = numeric ? new Date(Number(value) * (Number(value) < 1e12 ? 1000 : 1))
        : typeof value === 'string' && /^\d{4}-\d{2}-\d{2}(T| |$)/.test(value) ? new Date(value) : null;
    if (!date || Number.isNaN(date.getTime())) return display(value);
    return new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(date);
}

function Properties({ rows }: { rows: Array<[string, unknown]> }) {
    return <dl className="kb-document-properties">{rows.map(([label, value]) => (
        <div key={label}><dt>{label}</dt><dd>{display(value)}</dd></div>
    ))}</dl>;
}

export function DocumentSidebar({ doc, children }: { doc: KbDocument; children: ReactNode }) {
    const raw = useKbRaw(doc.frontmatter ? null : doc.id);
    const meta = doc.frontmatter ?? Object.fromEntries(extractFrontmatterPills(raw.data?.content ?? '').pills.map(({ key, value }) => [key, value]));
    const tags = [...new Set([...(Array.isArray(meta.tags) ? meta.tags.filter((tag): tag is string => typeof tag === 'string') : []), ...doc.metadata_tags, ...doc.tags.map(tag => tag.label || tag.slug)])];
    const status = display(doc.canonical_status ?? meta.status);
    const type = display(doc.canonical_type ?? meta.type);
    const known = new Set(['id', 'slug', 'type', 'status', 'project', 'owners', 'retrieval_priority', 'created_at', 'updated_at', 'summary', 'tags', 'related']);
    const extra: Array<[string, unknown]> = Object.entries(meta).filter(([key]) => !known.has(key) && !key.startsWith('_'));
    const related = [...new Set(Array.isArray(meta.related) ? meta.related.filter((item): item is string => typeof item === 'string') : [])];
    return (
        <aside className="kb-document-sidebar" aria-label="Document details">
            <header className="kb-sidebar-heading">
                <h3><Info size={16} aria-hidden />Document details</h3>
                <div className="kb-sidebar-badges">
                    {type !== '—' && <span>{type.replaceAll('-', ' ')}</span>}
                    {status !== '—' && <span data-status={status}>{status}</span>}
                </div>
            </header>
            {meta.summary != null && <section className="kb-sidebar-summary"><h4>Summary</h4><p>{display(meta.summary)}</p></section>}
            <section className="kb-sidebar-section">
                <h4>Organization</h4>
                <Properties rows={[
                    ['Project', doc.project_key], ['Owners', meta.owners],
                ]} />
                {tags.length > 0 && <div className="kb-sidebar-tags"><h4>Tags</h4><div className="kb-property-tags">{tags.map(tag => <span key={tag}>{tag}</span>)}</div></div>}
            </section>
            <section className="kb-sidebar-section">
                <h4>Activity</h4>
                <Properties rows={[
                    ['Created', formatDocumentDate(meta.created_at ?? doc.created_at)],
                    ['Updated', formatDocumentDate(meta.updated_at ?? doc.updated_at)],
                ]} />
            </section>
            {related.length > 0 && (
                <section className="kb-document-related">
                    <h4>Related documents <span className="kb-sidebar-count">{related.length}</span></h4>
                    {related.map(link => {
                        const [slug, label] = link.replace(/^\[\[|\]\]$/g, '').split('|');
                        return <div key={link}><FileText size={16} aria-hidden /><WikiLink slug={slug} label={label} project={doc.project_key} /></div>;
                    })}
                </section>
            )}
            <details className="kb-sidebar-technical" key={doc.id}>
                <summary>Technical details <ChevronDown size={14} aria-hidden /></summary>
                <Properties rows={[
                    ['Document ID', doc.doc_id ?? meta.id ?? doc.id], ['Slug', doc.slug ?? meta.slug],
                    ['Retrieval priority', doc.retrieval_priority ?? meta.retrieval_priority], ...extra,
                ]} />
            </details>
            <section className="kb-sidebar-management">
                <h4>Manage document</h4>
                <div className="kb-document-danger-actions">{children}</div>
            </section>
        </aside>
    );
}
