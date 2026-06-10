import { Icon } from '../../../../components/Icons';
import type { KbTreeDocNode } from '../../admin.api';
import { PreviewTab } from '../PreviewTab';

/*
 * Right-hand aside in the explorer: a slim read-only preview of the
 * focused document. Reuses PreviewTab (same `useKbRaw` fetch + markdown
 * renderer as the full detail view) so there's a single rendering path.
 *
 * "Open full detail" hands off to the tree+detail layout with this doc
 * selected — that's where Source editing, History, Graph and PDF export
 * live. The preview pane stays intentionally lightweight.
 */
export function ExplorerPreviewPane({
    node,
    onClose,
    onOpenDetail,
}: {
    node: KbTreeDocNode;
    onClose: () => void;
    onOpenDetail: (docId: number) => void;
}) {
    const meta = node.meta;
    const title = meta.title ?? node.name;
    const trashed = meta.deleted_at !== null;

    return (
        <aside
            data-testid="kb-explorer-preview"
            data-doc-id={meta.id}
            style={{
                display: 'flex',
                flexDirection: 'column',
                gap: 12,
                minHeight: 0,
                border: '1px solid var(--hairline)',
                borderRadius: 10,
                background: 'var(--bg-1)',
                overflow: 'hidden',
            }}
        >
            <header
                style={{
                    display: 'flex',
                    alignItems: 'flex-start',
                    gap: 8,
                    padding: '12px 12px 0',
                }}
            >
                <div style={{ flex: 1, minWidth: 0 }}>
                    <div
                        style={{
                            fontSize: 11,
                            color: 'var(--fg-3)',
                            fontFamily: 'var(--font-mono)',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                            whiteSpace: 'nowrap',
                        }}
                    >
                        {node.path}
                    </div>
                    <div style={{ fontSize: 15, fontWeight: 600, color: 'var(--fg-0)', marginTop: 2 }}>
                        {title}
                    </div>
                </div>
                <button
                    type="button"
                    data-testid="kb-explorer-preview-close"
                    aria-label="Close preview"
                    className="focus-ring"
                    onClick={onClose}
                    style={iconBtnStyle}
                >
                    <Icon.Close size={14} />
                </button>
            </header>

            <div
                style={{
                    display: 'flex',
                    flexWrap: 'wrap',
                    gap: 6,
                    padding: '0 12px',
                    fontSize: 11,
                }}
            >
                <Chip label={`project: ${meta.project_key}`} />
                <Chip label={meta.is_canonical ? 'canonical' : 'raw'} accent={meta.is_canonical} />
                {meta.canonical_type ? <Chip label={`type: ${meta.canonical_type}`} /> : null}
                {meta.canonical_status ? <Chip label={`status: ${meta.canonical_status}`} /> : null}
                {trashed ? <Chip label="deleted" danger /> : null}
            </div>

            <div style={{ padding: '0 12px' }}>
                <button
                    type="button"
                    data-testid="kb-explorer-preview-open-detail"
                    className="focus-ring"
                    onClick={() => onOpenDetail(meta.id)}
                    style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 6,
                        padding: '6px 10px',
                        fontSize: 12,
                        border: '1px solid var(--hairline)',
                        background: 'var(--bg-0)',
                        color: 'var(--fg-1)',
                        borderRadius: 8,
                        cursor: 'pointer',
                    }}
                >
                    <Icon.Eye size={13} /> Open full detail
                </button>
            </div>

            <div style={{ flex: 1, minHeight: 0, overflow: 'auto', padding: 12 }}>
                <PreviewTab documentId={meta.id} project={meta.project_key} />
            </div>
        </aside>
    );
}

const iconBtnStyle = {
    display: 'inline-flex',
    padding: 5,
    border: '1px solid var(--hairline)',
    background: 'var(--bg-0)',
    color: 'var(--fg-2)',
    borderRadius: 8,
    cursor: 'pointer',
} as const;

function Chip({ label, accent, danger }: { label: string; accent?: boolean; danger?: boolean }) {
    return (
        <span
            style={{
                padding: '2px 8px',
                borderRadius: 999,
                border: '1px solid var(--hairline)',
                background: accent
                    ? 'var(--grad-accent-soft)'
                    : danger
                      ? 'var(--danger-soft, rgba(220, 38, 38, 0.08))'
                      : 'var(--bg-0)',
                color: danger ? 'var(--danger-fg, #b91c1c)' : 'var(--fg-2)',
                fontSize: 11,
                fontFamily: 'var(--font-mono)',
            }}
        >
            {label}
        </span>
    );
}
