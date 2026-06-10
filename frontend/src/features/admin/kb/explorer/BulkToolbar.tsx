import { useState } from 'react';
import { Icon } from '../../../../components/Icons';
import { ConfirmDialog } from '../ConfirmDialog';

/*
 * Bulk action bar shown above the explorer grid when ≥1 doc is
 * selected. Download ZIP is a native browser download (anchor href so
 * the session cookies ride along). Delete / Force delete / Restore go
 * through the shared ConfirmDialog (testIdPrefix=kb-explorer-confirm)
 * before firing the mutation — destructive ops always confirm (R11).
 *
 * Restore is enabled only when the selection contains ≥1 trashed doc;
 * ZIP / delete are always available with a non-empty selection.
 */

type BulkAction = 'soft' | 'force' | 'restore';

export function BulkToolbar({
    count,
    trashedCount,
    zipHref,
    isBusy,
    onDelete,
    onRestore,
    onClear,
}: {
    count: number;
    trashedCount: number;
    zipHref: string;
    isBusy: boolean;
    onDelete: (force: boolean) => void;
    onRestore: () => void;
    onClear: () => void;
}) {
    const [confirm, setConfirm] = useState<BulkAction | null>(null);

    if (count === 0) {
        return null;
    }

    const confirmCopy: Record<BulkAction, { title: string; body: string; label: string; tone: 'danger' | 'neutral' }> = {
        soft: {
            title: `Delete ${count} document${count === 1 ? '' : 's'}?`,
            body: 'The selected documents will be soft-deleted. They can be restored later from the trash view.',
            label: 'Delete',
            tone: 'danger',
        },
        force: {
            title: `Force delete ${count} document${count === 1 ? '' : 's'}?`,
            body: 'This permanently removes the rows, chunks, graph nodes, and the files on disk. Cannot be undone.',
            label: 'Force delete',
            tone: 'danger',
        },
        restore: {
            title: `Restore ${trashedCount} document${trashedCount === 1 ? '' : 's'}?`,
            body: 'The trashed documents in your selection will be un-deleted and reappear in the live tree.',
            label: 'Restore',
            tone: 'neutral',
        },
    };

    function runConfirmed() {
        if (confirm === 'soft') onDelete(false);
        else if (confirm === 'force') onDelete(true);
        else if (confirm === 'restore') onRestore();
        setConfirm(null);
    }

    return (
        <div
            data-testid="kb-explorer-bulk-toolbar"
            role="toolbar"
            aria-label="Bulk actions"
            style={{
                display: 'flex',
                alignItems: 'center',
                gap: 8,
                padding: '8px 12px',
                borderBottom: '1px solid var(--hairline)',
                background: 'var(--grad-accent-soft)',
            }}
        >
            <span
                data-testid="kb-explorer-bulk-count"
                style={{ fontSize: 12.5, color: 'var(--fg-1)', fontWeight: 600 }}
            >
                {count} selected
            </span>

            <div style={{ flex: 1 }} />

            <a
                data-testid="kb-explorer-bulk-zip"
                href={zipHref}
                style={btnStyle}
            >
                <Icon.Download size={13} /> Download ZIP
            </a>
            {trashedCount > 0 ? (
                <button
                    type="button"
                    data-testid="kb-explorer-bulk-restore"
                    className="focus-ring"
                    disabled={isBusy}
                    onClick={() => setConfirm('restore')}
                    style={btnStyle}
                >
                    Restore ({trashedCount})
                </button>
            ) : null}
            <button
                type="button"
                data-testid="kb-explorer-bulk-delete"
                className="focus-ring"
                disabled={isBusy}
                onClick={() => setConfirm('soft')}
                style={dangerBtnStyle}
            >
                <Icon.Trash size={13} /> Delete
            </button>
            <button
                type="button"
                data-testid="kb-explorer-bulk-force-delete"
                className="focus-ring"
                disabled={isBusy}
                onClick={() => setConfirm('force')}
                style={dangerBtnStyle}
            >
                Force delete
            </button>
            <button
                type="button"
                data-testid="kb-explorer-bulk-clear"
                aria-label="Clear selection"
                className="focus-ring"
                onClick={onClear}
                style={iconBtnStyle}
            >
                <Icon.Close size={13} />
            </button>

            {confirm !== null ? (
                <ConfirmDialog
                    title={confirmCopy[confirm].title}
                    body={confirmCopy[confirm].body}
                    confirmLabel={confirmCopy[confirm].label}
                    tone={confirmCopy[confirm].tone}
                    isSubmitting={isBusy}
                    onCancel={() => setConfirm(null)}
                    onConfirm={runConfirmed}
                    testIdPrefix="kb-explorer-confirm"
                    dataAttrs={{ 'data-action': confirm }}
                />
            ) : null}
        </div>
    );
}

const btnStyle = {
    display: 'inline-flex',
    alignItems: 'center',
    gap: 5,
    padding: '5px 10px',
    fontSize: 12,
    border: '1px solid var(--hairline)',
    background: 'var(--bg-0)',
    color: 'var(--fg-1)',
    borderRadius: 8,
    cursor: 'pointer',
    textDecoration: 'none',
} as const;

const dangerBtnStyle = {
    ...btnStyle,
    border: '1px solid var(--danger-fg, #b91c1c)',
    background: 'var(--danger-soft, rgba(220, 38, 38, 0.12))',
    color: 'var(--danger-fg, #b91c1c)',
} as const;

const iconBtnStyle = {
    ...btnStyle,
    padding: 6,
} as const;
