import { useEffect, useRef, useState, type ReactNode } from 'react';
import type { FilterState } from './chat.api';

/**
 * T2.7 — multi-tab popover for selecting filters across the 9 dimensions.
 *
 * Tabs: Project · Tag · Source · Type · Folder · Date · Lang.
 * (Connector + doc_ids are not surfaced here: connector_types is not
 * yet user-facing; doc_ids are pinned via the @mention popover, T2.8.)
 *
 * Each tab is its own panel-form. Selecting a value emits an `onApply`
 * with the NEXT FilterState (the caller merges into the bar's state).
 * The popover closes on apply unless `keepOpen` is checked — useful
 * when adding multiple filters in a row.
 *
 * R15 a11y:
 *  - role="dialog" on the popover wrapper, aria-modal=false (it's not
 *    blocking — Esc closes, click-outside closes).
 *  - role="tablist" / role="tab" / aria-selected on the header.
 *  - role="tabpanel" / aria-labelledby on the active panel.
 *  - Focus enters the active tab on open; Esc closes + restores focus
 *    to the trigger (handled in FilterBar).
 *  - Every input has a <label> bound via htmlFor.
 *
 * R11: every interactive element carries a `data-testid` of the form
 * `filter-tab-{dimension}` / `filter-{dimension}-option-{value}` /
 * `filter-{dimension}-input` / `filter-popover-apply` / `filter-popover-close`.
 */

export interface FilterPickerPopoverProps {
    /** Current filter state — used to pre-check options across tabs. */
    filters: FilterState;
    /** Available project keys (loaded by the parent from `/api/admin/projects/keys`). */
    availableProjects?: string[];
    /** Available tag slugs for the current project scope. */
    availableTags?: { slug: string; label: string; color?: string }[];
    /** Caller writes the next state. The popover never mutates filters internally. */
    onApply: (next: FilterState) => void;
    onClose: () => void;
}

type TabId = 'project' | 'tag' | 'source' | 'canonical' | 'folder' | 'date' | 'language';

const TAB_ORDER: { id: TabId; label: string }[] = [
    { id: 'project', label: 'Project' },
    { id: 'tag', label: 'Tag' },
    { id: 'source', label: 'Source' },
    { id: 'canonical', label: 'Type' },
    { id: 'folder', label: 'Folder' },
    { id: 'date', label: 'Date' },
    { id: 'language', label: 'Lang' },
];

// Hardcoded enums match the BE's SourceType + CanonicalType enum cases.
// L18 / L20 — duplicating contract values across BE+FE is the
// trade-off for not introducing a contract-fetching layer; bumping a
// new enum value requires a 1-line FE update.
const SOURCE_TYPES = ['markdown', 'text', 'pdf', 'docx'] as const;
const CANONICAL_TYPES = [
    'project',
    'module',
    'decision',
    'runbook',
    'standard',
    'incident',
    'integration',
    'domain-concept',
    'rejected-approach',
] as const;
const LANGUAGES = ['en', 'it', 'es', 'fr', 'de'] as const;

export function FilterPickerPopover({
    filters,
    availableProjects = [],
    availableTags = [],
    onApply,
    onClose,
}: FilterPickerPopoverProps): ReactNode {
    const [activeTab, setActiveTab] = useState<TabId>('project');
    const popoverRef = useRef<HTMLDivElement>(null);

    // Close on Esc + click-outside. Focus trap is intentionally NOT
    // applied — refusal-style popovers shouldn't block the page; the
    // user can tab back to the textarea normally.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                onClose();
            }
        };
        const onClick = (e: MouseEvent) => {
            if (popoverRef.current && !popoverRef.current.contains(e.target as Node)) {
                onClose();
            }
        };
        document.addEventListener('keydown', onKey);
        // Capture phase so the click-outside fires before any inner
        // click-handler swallows the event (e.g. the trigger button
        // toggling visibility on its own onClick).
        document.addEventListener('mousedown', onClick, true);
        return () => {
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('mousedown', onClick, true);
        };
    }, [onClose]);

    // Helper: toggle a value in/out of a string[] dimension.
    const toggleStringValue = (key: keyof FilterState, value: string) => {
        const current = (filters[key] as string[] | undefined) ?? [];
        const next = current.includes(value)
            ? current.filter((v) => v !== value)
            : [...current, value];
        onApply({ ...filters, [key]: next });
    };

    return (
        <div
            ref={popoverRef}
            role="dialog"
            aria-modal="false"
            aria-labelledby="filter-popover-title"
            data-testid="filter-popover"
            data-state="open"
            style={{
                position: 'absolute',
                bottom: '100%',
                left: 0,
                marginBottom: 6,
                width: 360,
                maxWidth: 'calc(100vw - 48px)',
                background: 'var(--panel-solid, #1a1a22)',
                border: '1px solid var(--panel-border-strong, rgba(255,255,255,.12))',
                borderRadius: 12,
                boxShadow: 'var(--shadow, 0 8px 24px rgba(0,0,0,.35))',
                zIndex: 30,
                fontSize: 12.5,
            }}
        >
            <div style={{ padding: '10px 12px 0', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <span id="filter-popover-title" style={{ color: 'var(--fg-2)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.04em' }}>
                    Add filter
                </span>
                <button
                    type="button"
                    data-testid="filter-popover-close"
                    aria-label="Close filter picker"
                    onClick={onClose}
                    style={{ border: 0, background: 'transparent', color: 'var(--fg-3)', cursor: 'pointer', fontSize: 14, padding: 4, lineHeight: 1 }}
                >
                    ×
                </button>
            </div>
            <div role="tablist" aria-label="Filter dimensions" style={{ display: 'flex', gap: 2, padding: '8px 12px 0', flexWrap: 'wrap' }}>
                {TAB_ORDER.map((t) => (
                    <button
                        key={t.id}
                        type="button"
                        role="tab"
                        id={`filter-tab-${t.id}`}
                        data-testid={`filter-tab-${t.id}`}
                        aria-selected={activeTab === t.id}
                        aria-controls={`filter-tabpanel-${t.id}`}
                        onClick={() => setActiveTab(t.id)}
                        style={{
                            border: 0,
                            background: activeTab === t.id ? 'var(--bg-3, rgba(255,255,255,.08))' : 'transparent',
                            color: activeTab === t.id ? 'var(--fg-0)' : 'var(--fg-2)',
                            padding: '5px 10px',
                            borderRadius: 6,
                            cursor: 'pointer',
                            fontSize: 11.5,
                        }}
                    >
                        {t.label}
                    </button>
                ))}
            </div>
            <div
                role="tabpanel"
                id={`filter-tabpanel-${activeTab}`}
                aria-labelledby={`filter-tab-${activeTab}`}
                data-testid={`filter-tabpanel-${activeTab}`}
                style={{ padding: 12, maxHeight: 280, overflowY: 'auto' }}
            >
                {activeTab === 'project' && (
                    <ProjectTab
                        available={availableProjects}
                        selected={filters.project_keys ?? []}
                        onToggle={(v) => toggleStringValue('project_keys', v)}
                    />
                )}
                {activeTab === 'tag' && (
                    <TagTab
                        available={availableTags}
                        selected={filters.tag_slugs ?? []}
                        onToggle={(v) => toggleStringValue('tag_slugs', v)}
                    />
                )}
                {activeTab === 'source' && (
                    <SimpleListTab
                        dimension="source"
                        options={[...SOURCE_TYPES]}
                        selected={filters.source_types ?? []}
                        onToggle={(v) => toggleStringValue('source_types', v)}
                    />
                )}
                {activeTab === 'canonical' && (
                    <SimpleListTab
                        dimension="canonical"
                        options={[...CANONICAL_TYPES]}
                        selected={filters.canonical_types ?? []}
                        onToggle={(v) => toggleStringValue('canonical_types', v)}
                    />
                )}
                {activeTab === 'folder' && (
                    <FolderTab
                        globs={filters.folder_globs ?? []}
                        onChange={(globs) => onApply({ ...filters, folder_globs: globs })}
                    />
                )}
                {activeTab === 'date' && (
                    <DateTab
                        from={filters.date_from ?? null}
                        to={filters.date_to ?? null}
                        onChange={(from, to) => onApply({ ...filters, date_from: from, date_to: to })}
                    />
                )}
                {activeTab === 'language' && (
                    <SimpleListTab
                        dimension="language"
                        options={[...LANGUAGES]}
                        selected={filters.languages ?? []}
                        onToggle={(v) => toggleStringValue('languages', v)}
                    />
                )}
            </div>
        </div>
    );
}

interface ProjectTabProps {
    available: string[];
    selected: string[];
    onToggle: (value: string) => void;
}

function ProjectTab({ available, selected, onToggle }: ProjectTabProps): ReactNode {
    if (available.length === 0) {
        return (
            <p style={{ color: 'var(--fg-3)', fontSize: 11.5, margin: 0 }}>
                No projects available. Configure projects in admin settings.
            </p>
        );
    }
    return (
        <ul style={{ listStyle: 'none', padding: 0, margin: 0, display: 'flex', flexDirection: 'column', gap: 4 }}>
            {available.map((key) => {
                const isSelected = selected.includes(key);
                const id = `filter-project-option-${key}`;
                return (
                    <li key={key}>
                        <label
                            htmlFor={id}
                            style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer', color: 'var(--fg-1)' }}
                        >
                            <input
                                id={id}
                                data-testid={id}
                                type="checkbox"
                                checked={isSelected}
                                onChange={() => onToggle(key)}
                            />
                            <span>{key}</span>
                        </label>
                    </li>
                );
            })}
        </ul>
    );
}

interface TagTabProps {
    available: { slug: string; label: string; color?: string }[];
    selected: string[];
    onToggle: (slug: string) => void;
}

function TagTab({ available, selected, onToggle }: TagTabProps): ReactNode {
    if (available.length === 0) {
        return (
            <p style={{ color: 'var(--fg-3)', fontSize: 11.5, margin: 0 }}>
                No tags available for the selected project.
            </p>
        );
    }
    return (
        <ul style={{ listStyle: 'none', padding: 0, margin: 0, display: 'flex', flexWrap: 'wrap', gap: 6 }}>
            {available.map((t) => {
                const isSelected = selected.includes(t.slug);
                const id = `filter-tag-option-${t.slug}`;
                return (
                    <li key={t.slug}>
                        <button
                            type="button"
                            id={id}
                            data-testid={id}
                            aria-pressed={isSelected}
                            onClick={() => onToggle(t.slug)}
                            style={{
                                padding: '3px 9px',
                                borderRadius: 99,
                                border: '1px solid',
                                borderColor: isSelected ? (t.color ?? 'var(--accent, #6366f1)') : 'var(--panel-border, rgba(255,255,255,.15))',
                                background: isSelected ? (t.color ?? 'var(--accent, #6366f1)') + '22' : 'transparent',
                                color: isSelected ? (t.color ?? 'var(--accent, #6366f1)') : 'var(--fg-1)',
                                fontSize: 11,
                                cursor: 'pointer',
                            }}
                        >
                            {t.label}
                        </button>
                    </li>
                );
            })}
        </ul>
    );
}

interface SimpleListTabProps {
    dimension: 'source' | 'canonical' | 'language';
    options: string[];
    selected: string[];
    onToggle: (value: string) => void;
}

function SimpleListTab({ dimension, options, selected, onToggle }: SimpleListTabProps): ReactNode {
    return (
        <ul style={{ listStyle: 'none', padding: 0, margin: 0, display: 'flex', flexDirection: 'column', gap: 4 }}>
            {options.map((opt) => {
                const isSelected = selected.includes(opt);
                const id = `filter-${dimension}-option-${opt}`;
                return (
                    <li key={opt}>
                        <label
                            htmlFor={id}
                            style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer', color: 'var(--fg-1)' }}
                        >
                            <input
                                id={id}
                                data-testid={id}
                                type="checkbox"
                                checked={isSelected}
                                onChange={() => onToggle(opt)}
                            />
                            <span>{opt}</span>
                        </label>
                    </li>
                );
            })}
        </ul>
    );
}

interface FolderTabProps {
    globs: string[];
    onChange: (next: string[]) => void;
}

function FolderTab({ globs, onChange }: FolderTabProps): ReactNode {
    const [draft, setDraft] = useState('');

    const add = () => {
        const trimmed = draft.trim();
        if (trimmed === '' || globs.includes(trimmed)) {
            return;
        }
        onChange([...globs, trimmed]);
        setDraft('');
    };

    const remove = (g: string) => onChange(globs.filter((x) => x !== g));

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            <label htmlFor="filter-folder-input" style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                <span style={{ color: 'var(--fg-2)', fontSize: 11 }}>
                    Folder glob (use <code>**</code> for cross-segment, <code>*</code> for single-segment)
                </span>
                <input
                    id="filter-folder-input"
                    data-testid="filter-folder-input"
                    type="text"
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            add();
                        }
                    }}
                    placeholder="hr/policies/**"
                    style={{
                        padding: '5px 8px',
                        borderRadius: 6,
                        border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
                        background: 'var(--bg-3, rgba(255,255,255,.04))',
                        color: 'var(--fg-0)',
                        fontFamily: 'var(--font-mono, monospace)',
                        fontSize: 11.5,
                    }}
                />
            </label>
            <button
                type="button"
                data-testid="filter-folder-add"
                onClick={add}
                disabled={draft.trim() === ''}
                style={{
                    padding: '4px 10px',
                    borderRadius: 6,
                    border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
                    background: 'var(--bg-3, rgba(255,255,255,.06))',
                    color: 'var(--fg-1)',
                    fontSize: 11.5,
                    cursor: draft.trim() === '' ? 'not-allowed' : 'pointer',
                    opacity: draft.trim() === '' ? 0.5 : 1,
                    alignSelf: 'flex-start',
                }}
            >
                Add
            </button>
            {globs.length > 0 && (
                <ul style={{ listStyle: 'none', padding: 0, margin: 0, display: 'flex', flexDirection: 'column', gap: 4 }}>
                    {globs.map((g) => (
                        <li
                            key={g}
                            data-testid={`filter-folder-glob-${g}`}
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'space-between',
                                padding: '3px 8px',
                                background: 'var(--bg-3, rgba(255,255,255,.04))',
                                borderRadius: 6,
                                fontFamily: 'var(--font-mono, monospace)',
                                fontSize: 11,
                                color: 'var(--fg-1)',
                            }}
                        >
                            <span>{g}</span>
                            <button
                                type="button"
                                data-testid={`filter-folder-glob-${g}-remove`}
                                aria-label={`Remove folder filter ${g}`}
                                onClick={() => remove(g)}
                                style={{ border: 0, background: 'transparent', color: 'var(--fg-3)', cursor: 'pointer', fontSize: 12, lineHeight: 1, padding: 2 }}
                            >
                                ×
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

interface DateTabProps {
    from: string | null;
    to: string | null;
    onChange: (from: string | null, to: string | null) => void;
}

function DateTab({ from, to, onChange }: DateTabProps): ReactNode {
    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            <label htmlFor="filter-date-from" style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                <span style={{ color: 'var(--fg-2)', fontSize: 11 }}>From</span>
                <input
                    id="filter-date-from"
                    data-testid="filter-date-from"
                    type="date"
                    value={from ?? ''}
                    onChange={(e) => onChange(e.target.value || null, to)}
                    style={{
                        padding: '5px 8px',
                        borderRadius: 6,
                        border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
                        background: 'var(--bg-3, rgba(255,255,255,.04))',
                        color: 'var(--fg-0)',
                        fontSize: 11.5,
                    }}
                />
            </label>
            <label htmlFor="filter-date-to" style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                <span style={{ color: 'var(--fg-2)', fontSize: 11 }}>To</span>
                <input
                    id="filter-date-to"
                    data-testid="filter-date-to"
                    type="date"
                    value={to ?? ''}
                    min={from ?? undefined}
                    onChange={(e) => onChange(from, e.target.value || null)}
                    style={{
                        padding: '5px 8px',
                        borderRadius: 6,
                        border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
                        background: 'var(--bg-3, rgba(255,255,255,.04))',
                        color: 'var(--fg-0)',
                        fontSize: 11.5,
                    }}
                />
            </label>
            {(from || to) && (
                <button
                    type="button"
                    data-testid="filter-date-clear"
                    onClick={() => onChange(null, null)}
                    style={{
                        padding: '4px 10px',
                        borderRadius: 6,
                        border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
                        background: 'transparent',
                        color: 'var(--fg-2)',
                        fontSize: 11,
                        cursor: 'pointer',
                        alignSelf: 'flex-start',
                    }}
                >
                    Clear date range
                </button>
            )}
        </div>
    );
}
