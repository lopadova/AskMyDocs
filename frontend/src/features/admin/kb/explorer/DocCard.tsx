import type { MouseEvent } from 'react';
import { Icon } from '../../../../components/Icons';
import type { KbTreeDocNode } from '../../admin.api';
import type { ExplorerLayout, ExplorerTileSize } from './explorer-prefs';

const SIZE_PX: Record<ExplorerTileSize, number> = { sm: 96, md: 128, lg: 168 };

/**
 * A document card in the explorer grid (or row in list layout).
 *
 * Interaction is supplied by the parent so the selection model lives in
 * one place: `onActivate` fires on click (carrying the shift / meta
 * modifiers so the parent can range- or toggle-select), `onOpen` fires
 * on double-click to focus the preview pane.
 *
 * The selection checkbox uses the visually-hidden pattern (not
 * display:none) so screen readers still perceive it (R15). `data-state`
 * mirrors the selected flag for Playwright (R11).
 */
export function DocCard({
    doc,
    layout,
    size,
    selectable = false,
    selected,
    focused,
    onActivate,
    onToggle,
    onOpen,
}: {
    doc: KbTreeDocNode;
    layout: ExplorerLayout;
    size: ExplorerTileSize;
    /** When false the selection checkbox is hidden (no multi-select wired). */
    selectable?: boolean;
    selected: boolean;
    focused: boolean;
    onActivate: (doc: KbTreeDocNode, e: MouseEvent) => void;
    onToggle: (doc: KbTreeDocNode) => void;
    onOpen: (doc: KbTreeDocNode) => void;
}) {
    const meta = doc.meta;
    const trashed = meta.deleted_at !== null;
    const label = meta.title ?? doc.name;

    const common = {
        'data-testid': `kb-explorer-doc-${meta.id}`,
        'data-type': 'doc',
        'data-selected': selected ? 'true' : 'false',
        'data-focused': focused ? 'true' : 'false',
        'data-canonical': meta.is_canonical ? 'true' : 'false',
        'data-trashed': trashed ? 'true' : 'false',
        'aria-selected': selected,
        onClick: (e: MouseEvent) => onActivate(doc, e),
        onDoubleClick: () => onOpen(doc),
    };

    const border = selected
        ? 'var(--accent)'
        : focused
          ? 'var(--accent)'
          : 'var(--hairline)';
    const bg = selected ? 'var(--grad-accent-soft)' : 'var(--bg-1)';

    if (layout === 'list') {
        return (
            <div
                {...common}
                role="option"
                tabIndex={0}
                className="focus-ring"
                onKeyDown={(e) => {
                    if (e.key === 'Enter') onOpen(doc);
                }}
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 10,
                    width: '100%',
                    padding: '7px 10px',
                    borderBottom: '1px solid var(--hairline)',
                    border: `1px solid ${selected ? 'var(--accent)' : 'transparent'}`,
                    background: selected ? 'var(--grad-accent-soft)' : 'transparent',
                    cursor: 'pointer',
                    color: trashed ? 'var(--fg-3)' : 'var(--fg-1)',
                    textDecoration: trashed ? 'line-through' : 'none',
                }}
            >
                {selectable ? (
                    <SelectBox selected={selected} label={label} onToggle={() => onToggle(doc)} />
                ) : null}
                <Icon.File size={14} />
                <span style={{ flex: 1, fontSize: 13, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                    {label}
                </span>
                {meta.is_canonical ? <CanonicalChip type={meta.canonical_type} /> : null}
                <span style={{ fontSize: 10.5, color: 'var(--fg-3)', fontFamily: 'var(--font-mono)' }}>
                    {doc.name}
                </span>
            </div>
        );
    }

    const px = SIZE_PX[size];
    return (
        <div
            {...common}
            role="option"
            tabIndex={0}
            className="focus-ring"
            onKeyDown={(e) => {
                if (e.key === 'Enter') onOpen(doc);
            }}
            style={{
                position: 'relative',
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
                gap: 8,
                width: px,
                height: px,
                padding: 10,
                border: `1px solid ${border}`,
                borderRadius: 12,
                background: bg,
                cursor: 'pointer',
                color: trashed ? 'var(--fg-3)' : 'var(--fg-1)',
                textDecoration: trashed ? 'line-through' : 'none',
            }}
        >
            {selectable ? (
                <div style={{ position: 'absolute', top: 6, left: 6 }}>
                    <SelectBox selected={selected} label={label} onToggle={() => onToggle(doc)} />
                </div>
            ) : null}
            <Icon.File size={size === 'sm' ? 26 : size === 'md' ? 34 : 42} />
            <span
                title={label}
                style={{
                    fontSize: 11.5,
                    textAlign: 'center',
                    overflow: 'hidden',
                    textOverflow: 'ellipsis',
                    whiteSpace: 'nowrap',
                    maxWidth: '100%',
                }}
            >
                {label}
            </span>
            {meta.is_canonical ? <CanonicalChip type={meta.canonical_type} /> : null}
        </div>
    );
}

function SelectBox({
    selected,
    label,
    onToggle,
}: {
    selected: boolean;
    label: string;
    onToggle: () => void;
}) {
    return (
        <input
            type="checkbox"
            checked={selected}
            aria-label={`Select ${label}`}
            data-testid="kb-explorer-doc-checkbox"
            onChange={onToggle}
            // Stop the change-click from also bubbling to the card's
            // onActivate (which would re-toggle / clear the range).
            onClick={(e) => e.stopPropagation()}
            style={{ cursor: 'pointer' }}
        />
    );
}

function CanonicalChip({ type }: { type: string | null }) {
    return (
        <span
            style={{
                fontSize: 9.5,
                padding: '1px 6px',
                borderRadius: 999,
                background: 'var(--grad-accent-soft)',
                color: 'var(--accent-fg)',
                textTransform: 'uppercase',
                letterSpacing: '0.04em',
            }}
        >
            {type ?? 'canonical'}
        </span>
    );
}
