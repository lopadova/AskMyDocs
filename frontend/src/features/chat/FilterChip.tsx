import { type ReactNode } from 'react';

/**
 * T2.7 — single removable filter chip rendered inside FilterBar.
 *
 * Visual contract: rounded pill, dimension label + value, "×" remove
 * affordance on the right. The remove button is its own focusable
 * element (R15 — the chip wrapper is not the focus target; the
 * button is). Click anywhere on the chip body is a no-op so a stray
 * click doesn't accidentally delete the filter.
 *
 * R11 testid convention: `filter-chip-{dimension}-{value}` for the
 * chip; `filter-chip-{dimension}-{value}-remove` for the × button.
 * The dimension is the BE field name (snake_case) so the testid maps
 * directly to the backend payload key — easy to grep when debugging
 * a filter that didn't apply.
 */

export interface FilterChipProps {
    /** Filter dimension — snake_case to match the BE payload key. */
    dimension: 'project' | 'tag' | 'source' | 'canonical' | 'connector' | 'doc' | 'folder' | 'language' | 'date';
    /** Stable identifier for testid + remove key. Always a string. */
    value: string;
    /** Visible value rendered in the chip body (may differ from `value`, e.g. document title vs id). */
    label?: string;
    onRemove: () => void;
}

const DIMENSION_GLYPH: Record<FilterChipProps['dimension'], string> = {
    project: '⚐',
    tag: '#',
    source: '📄',
    canonical: '⚙',
    connector: '🔌',
    doc: '@',
    folder: '/',
    language: '🌐',
    date: '⌛',
};

const DIMENSION_LABEL: Record<FilterChipProps['dimension'], string> = {
    project: 'Project',
    tag: 'Tag',
    source: 'Source',
    canonical: 'Type',
    connector: 'Connector',
    doc: 'Doc',
    folder: 'Folder',
    language: 'Lang',
    date: 'Date',
};

export function FilterChip({ dimension, value, label, onRemove }: FilterChipProps): ReactNode {
    const display = label ?? value;
    const testId = `filter-chip-${dimension}-${value}`;
    const removeTestId = `${testId}-remove`;
    const ariaLabel = `${DIMENSION_LABEL[dimension]} filter: ${display}. Remove`;

    return (
        <span
            data-testid={testId}
            data-dimension={dimension}
            data-value={value}
            style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: 5,
                padding: '3px 4px 3px 9px',
                background: 'var(--bg-3, rgba(120,120,135,.10))',
                border: '1px solid var(--panel-border, rgba(120,120,135,.30))',
                borderRadius: 99,
                fontSize: 11,
                color: 'var(--fg-1)',
                whiteSpace: 'nowrap',
                lineHeight: 1.4,
            }}
        >
            <span aria-hidden="true" style={{ color: 'var(--fg-3)' }}>{DIMENSION_GLYPH[dimension]}</span>
            <span style={{ color: 'var(--fg-2)' }}>{DIMENSION_LABEL[dimension]}:</span>
            <span style={{ color: 'var(--fg-0)', fontWeight: 500 }}>{display}</span>
            <button
                type="button"
                data-testid={removeTestId}
                aria-label={ariaLabel}
                onClick={(e) => {
                    e.stopPropagation();
                    onRemove();
                }}
                style={{
                    border: 0,
                    background: 'transparent',
                    color: 'var(--fg-3)',
                    cursor: 'pointer',
                    padding: '2px 4px',
                    lineHeight: 1,
                    fontSize: 13,
                    borderRadius: 99,
                    marginLeft: 2,
                }}
            >
                ×
            </button>
        </span>
    );
}
