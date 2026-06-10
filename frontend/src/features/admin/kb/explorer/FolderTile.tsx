import { Icon } from '../../../../components/Icons';
import type { KbTreeFolderNode } from '../../admin.api';
import { descendantDocCount } from './explorer-utils';
import type { ExplorerLayout, ExplorerTileSize } from './explorer-prefs';

const SIZE_PX: Record<ExplorerTileSize, number> = { sm: 96, md: 128, lg: 168 };

/**
 * A folder tile in the explorer grid. Folders are NOT part of the
 * selection model in v1 — a click navigates into them (both single
 * click and Enter, since it's a real <button>). The doc-count badge
 * uses the recursive descendant count so a folder of folders still
 * reads as non-empty.
 */
export function FolderTile({
    folder,
    layout,
    size,
    onOpen,
}: {
    folder: KbTreeFolderNode;
    layout: ExplorerLayout;
    size: ExplorerTileSize;
    onOpen: (path: string) => void;
}) {
    const count = descendantDocCount(folder);
    const label = `${folder.name}, folder, ${count} document${count === 1 ? '' : 's'}`;

    if (layout === 'list') {
        return (
            <button
                type="button"
                className="focus-ring"
                data-testid={`kb-explorer-folder-${folder.path}`}
                data-type="folder"
                aria-label={label}
                onClick={() => onOpen(folder.path)}
                style={listRowStyle}
            >
                <Icon.Folder size={16} />
                <span style={{ flex: 1, textAlign: 'left', fontSize: 13, color: 'var(--fg-1)' }}>
                    {folder.name}
                </span>
                <span style={countStyle}>{count}</span>
            </button>
        );
    }

    const px = SIZE_PX[size];
    return (
        <button
            type="button"
            className="focus-ring"
            data-testid={`kb-explorer-folder-${folder.path}`}
            data-type="folder"
            aria-label={label}
            onClick={() => onOpen(folder.path)}
            style={{
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
                gap: 8,
                width: px,
                height: px,
                padding: 10,
                border: '1px solid var(--hairline)',
                borderRadius: 12,
                background: 'var(--bg-1)',
                color: 'var(--fg-1)',
                cursor: 'pointer',
            }}
        >
            <Icon.Folder size={size === 'sm' ? 28 : size === 'md' ? 36 : 44} />
            <span
                style={{
                    fontSize: 12,
                    textAlign: 'center',
                    overflow: 'hidden',
                    textOverflow: 'ellipsis',
                    whiteSpace: 'nowrap',
                    maxWidth: '100%',
                }}
            >
                {folder.name}
            </span>
            <span style={countStyle}>{count}</span>
        </button>
    );
}

const listRowStyle = {
    display: 'flex',
    alignItems: 'center',
    gap: 10,
    width: '100%',
    padding: '7px 10px',
    border: '1px solid transparent',
    borderBottom: '1px solid var(--hairline)',
    background: 'transparent',
    cursor: 'pointer',
} as const;

const countStyle = {
    fontSize: 10.5,
    padding: '1px 7px',
    borderRadius: 999,
    background: 'var(--bg-0)',
    border: '1px solid var(--hairline)',
    color: 'var(--fg-3)',
    fontFamily: 'var(--font-mono)',
} as const;
