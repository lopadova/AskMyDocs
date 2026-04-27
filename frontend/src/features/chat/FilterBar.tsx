import { useState, type ReactNode } from 'react';
import { FilterChip } from './FilterChip';
import { FilterPickerPopover } from './FilterPickerPopover';
import { countSelectedFilters, type FilterState } from './chat.api';

/**
 * T2.7 — Persistent filter bar above the chat composer textarea.
 *
 * Renders one chip per active filter dimension/value pair plus a
 * "+ Filter" trigger that opens the FilterPickerPopover. Designed to
 * be unconditionally visible (no hide-when-empty), so the user
 * always knows the filter UI exists and where to find it.
 *
 * Props pattern: stateless. The parent owns the FilterState and the
 * lookup data (project keys, tag list). The bar mutates only via
 * onChange callbacks. Keeps the bar reusable across chat surfaces
 * (KbChatPage, ConversationView, future MCP-tool config UIs) and
 * keeps the test surface narrow.
 *
 * R11: every interactive surface carries a `data-testid`. R15: the
 * trigger button is keyboard-reachable; the popover handles its own
 * Esc/click-outside.
 */

export interface FilterBarProps {
    filters: FilterState;
    onChange: (next: FilterState) => void;
    /** Available project keys — fetched by the parent from `/api/admin/projects/keys`. */
    availableProjects?: string[];
    /** Available tag slugs (with display label + color) for the current project scope. */
    availableTags?: { slug: string; label: string; color?: string }[];
    /** Map of doc_id → display title for chip labels. Used for the @mention pinning surface. */
    docLabels?: Record<number, string>;
}

export function FilterBar({
    filters,
    onChange,
    availableProjects = [],
    availableTags = [],
    docLabels = {},
}: FilterBarProps): ReactNode {
    const [popoverOpen, setPopoverOpen] = useState(false);
    const selectedCount = countSelectedFilters(filters);

    const removeFromArray = <K extends keyof FilterState>(key: K, value: string | number) => {
        const current = (filters[key] as Array<string | number> | undefined) ?? [];
        const next = current.filter((v) => v !== value);
        onChange({ ...filters, [key]: next });
    };

    const clearAll = () => {
        onChange({});
    };

    return (
        <div
            data-testid="chat-filter-bar"
            data-filters-count={selectedCount}
            style={{
                position: 'relative',
                display: 'flex',
                alignItems: 'center',
                flexWrap: 'wrap',
                gap: 6,
                padding: '6px 12px',
                borderBottom: '1px solid var(--panel-border, rgba(255,255,255,.08))',
            }}
        >
            <button
                type="button"
                data-testid="chat-filter-bar-add"
                aria-label="Add chat filter"
                aria-expanded={popoverOpen}
                aria-haspopup="dialog"
                onClick={() => setPopoverOpen((v) => !v)}
                style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 4,
                    padding: '3px 9px',
                    border: '1px dashed var(--panel-border, rgba(255,255,255,.30))',
                    borderRadius: 99,
                    background: 'transparent',
                    color: 'var(--fg-2)',
                    cursor: 'pointer',
                    fontSize: 11.5,
                    lineHeight: 1.4,
                }}
            >
                <span aria-hidden="true">+</span>
                Filter
                {selectedCount > 0 && (
                    <span
                        data-testid="chat-filter-bar-count"
                        aria-label={`${selectedCount} filters selected`}
                        style={{
                            display: 'inline-flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            minWidth: 16,
                            padding: '0 5px',
                            background: 'var(--accent, #6366f1)',
                            color: 'white',
                            borderRadius: 99,
                            fontSize: 10,
                            marginLeft: 2,
                            fontWeight: 600,
                        }}
                    >
                        {selectedCount}
                    </span>
                )}
            </button>

            {(filters.project_keys ?? []).map((p) => (
                <FilterChip
                    key={`project-${p}`}
                    dimension="project"
                    value={p}
                    onRemove={() => removeFromArray('project_keys', p)}
                />
            ))}
            {(filters.tag_slugs ?? []).map((t) => (
                <FilterChip
                    key={`tag-${t}`}
                    dimension="tag"
                    value={t}
                    label={availableTags.find((tag) => tag.slug === t)?.label ?? t}
                    onRemove={() => removeFromArray('tag_slugs', t)}
                />
            ))}
            {(filters.source_types ?? []).map((s) => (
                <FilterChip
                    key={`source-${s}`}
                    dimension="source"
                    value={s}
                    onRemove={() => removeFromArray('source_types', s)}
                />
            ))}
            {(filters.canonical_types ?? []).map((c) => (
                <FilterChip
                    key={`canonical-${c}`}
                    dimension="canonical"
                    value={c}
                    onRemove={() => removeFromArray('canonical_types', c)}
                />
            ))}
            {(filters.connector_types ?? []).map((c) => (
                <FilterChip
                    key={`connector-${c}`}
                    dimension="connector"
                    value={c}
                    onRemove={() => removeFromArray('connector_types', c)}
                />
            ))}
            {(filters.doc_ids ?? []).map((d) => (
                <FilterChip
                    key={`doc-${d}`}
                    dimension="doc"
                    value={String(d)}
                    label={docLabels[d] ?? `#${d}`}
                    onRemove={() => removeFromArray('doc_ids', d)}
                />
            ))}
            {(filters.folder_globs ?? []).map((g) => (
                <FilterChip
                    key={`folder-${g}`}
                    dimension="folder"
                    value={g}
                    onRemove={() => removeFromArray('folder_globs', g)}
                />
            ))}
            {(filters.languages ?? []).map((l) => (
                <FilterChip
                    key={`language-${l}`}
                    dimension="language"
                    value={l}
                    onRemove={() => removeFromArray('languages', l)}
                />
            ))}
            {filters.date_from && (
                <FilterChip
                    dimension="date"
                    value="from"
                    label={`from ${filters.date_from}`}
                    onRemove={() => onChange({ ...filters, date_from: null })}
                />
            )}
            {filters.date_to && (
                <FilterChip
                    dimension="date"
                    value="to"
                    label={`to ${filters.date_to}`}
                    onRemove={() => onChange({ ...filters, date_to: null })}
                />
            )}

            {selectedCount > 0 && (
                <button
                    type="button"
                    data-testid="chat-filter-bar-clear"
                    onClick={clearAll}
                    style={{
                        marginLeft: 'auto',
                        border: 0,
                        background: 'transparent',
                        color: 'var(--fg-3)',
                        fontSize: 11,
                        cursor: 'pointer',
                        textDecoration: 'underline',
                    }}
                >
                    Clear all
                </button>
            )}

            {popoverOpen && (
                <FilterPickerPopover
                    filters={filters}
                    availableProjects={availableProjects}
                    availableTags={availableTags}
                    onApply={onChange}
                    onClose={() => setPopoverOpen(false)}
                />
            )}
        </div>
    );
}
