import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useMentionSearch, type MentionResult } from './use-mention-search';

/**
 * T2.8 — Autocomplete popover triggered by `@` in the chat textarea.
 *
 * Architecture:
 * - Composer detects the `@` keystroke + tracks the query string from
 *   `@` to next whitespace, passing it down via `query`.
 * - This component renders a listbox below the textarea with up to N
 *   results, debounced via use-mention-search.
 * - Selecting a result fires `onSelect(doc)` — the Composer adds doc.id
 *   to filters.docIds and replaces the `@<query>` text.
 * - Esc closes via `onClose()`; arrow keys + Enter handled internally.
 *
 * The popover does NOT debounce the query itself — that's the
 * Composer's job (raw keystroke → debounced state → this prop). Keeps
 * the popover a pure render given a stable query.
 *
 * R15: `role="listbox"` + `aria-activedescendant` so screen readers
 * announce the highlighted option as the user navigates with arrows.
 * `role="option"` + `aria-selected` per item.
 *
 * R11: `data-testid="mention-popover"` + `mention-option-{id}` per item.
 */

export interface MentionPopoverProps {
    /** Trimmed query string (chars after `@` until whitespace). */
    query: string;
    /** Project keys scoping the search — usually `[currentProject]`. */
    projectKeys?: string[];
    /** Already-selected doc ids — exclude from results to prevent dupes. */
    excludeIds?: number[];
    /** Maximum visible results. Default 10 per plan §2216. */
    limit?: number;
    onSelect: (doc: MentionResult) => void;
    onClose: () => void;
    /** True when the parent decides the popover should be visible. */
    open: boolean;
}

export function MentionPopover({
    query,
    projectKeys,
    excludeIds = [],
    limit = 10,
    onSelect,
    onClose,
    open,
}: MentionPopoverProps): ReactNode {
    const { data, isLoading } = useMentionSearch({ query, projectKeys, enabled: open });
    const popoverRef = useRef<HTMLDivElement>(null);
    const [activeIndex, setActiveIndex] = useState(0);

    // Filter out already-selected docs and cap at the limit.
    const allResults = data?.data ?? [];
    const results = allResults
        .filter((d) => !excludeIds.includes(d.id))
        .slice(0, limit);

    // Reset highlight when the result set changes — index 0 might point
    // at a stale doc otherwise.
    useEffect(() => {
        setActiveIndex(0);
    }, [results.length, query]);

    // Keyboard handling lives at the document level so the user can
    // type in the textarea AND drive the popover with arrows. The
    // alternative — focus the popover — would steal cursor focus from
    // the composer and break inline typing.
    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                onClose();
                return;
            }
            if (results.length === 0) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActiveIndex((i) => (i + 1) % results.length);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActiveIndex((i) => (i - 1 + results.length) % results.length);
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                const selected = results[activeIndex];
                if (selected) {
                    onSelect(selected);
                }
            }
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [open, results, activeIndex, onSelect, onClose]);

    if (!open) return null;

    return (
        <div
            ref={popoverRef}
            data-testid="mention-popover"
            data-state={isLoading ? 'loading' : results.length === 0 ? 'empty' : 'ready'}
            role="listbox"
            aria-label="Document mention suggestions"
            aria-activedescendant={results[activeIndex] ? `mention-option-${results[activeIndex].id}` : undefined}
            style={{
                position: 'absolute',
                bottom: '100%',
                left: 0,
                right: 0,
                marginBottom: 6,
                background: 'var(--panel-solid, #1a1a22)',
                border: '1px solid var(--panel-border-strong, rgba(255,255,255,.12))',
                borderRadius: 10,
                boxShadow: 'var(--shadow, 0 8px 24px rgba(0,0,0,.35))',
                maxHeight: 280,
                overflowY: 'auto',
                zIndex: 30,
            }}
        >
            {isLoading && (
                <div
                    data-testid="mention-popover-loading"
                    style={{ padding: '10px 12px', color: 'var(--fg-3)', fontSize: 12 }}
                >
                    Searching documents…
                </div>
            )}
            {!isLoading && results.length === 0 && (
                <div
                    data-testid="mention-popover-empty"
                    style={{ padding: '10px 12px', color: 'var(--fg-3)', fontSize: 12 }}
                >
                    No documents match <code>{query}</code>.
                </div>
            )}
            {!isLoading && results.length > 0 && (
                <ul style={{ listStyle: 'none', padding: 4, margin: 0 }}>
                    {results.map((doc, i) => {
                        const isActive = i === activeIndex;
                        return (
                            <li
                                key={doc.id}
                                id={`mention-option-${doc.id}`}
                                data-testid={`mention-option-${doc.id}`}
                                data-active={isActive}
                                role="option"
                                aria-selected={isActive}
                                onMouseEnter={() => setActiveIndex(i)}
                                onMouseDown={(e) => {
                                    // mousedown not click — click would fire
                                    // AFTER blur, by which time the composer
                                    // has already lost the textarea focus
                                    // and the textarea-replacement breaks.
                                    e.preventDefault();
                                    onSelect(doc);
                                }}
                                style={{
                                    padding: '6px 10px',
                                    borderRadius: 6,
                                    cursor: 'pointer',
                                    background: isActive ? 'var(--bg-3, rgba(255,255,255,.08))' : 'transparent',
                                    color: 'var(--fg-1)',
                                    fontSize: 12.5,
                                    lineHeight: 1.4,
                                    display: 'flex',
                                    flexDirection: 'column',
                                    gap: 2,
                                }}
                            >
                                <span style={{ color: 'var(--fg-0)', fontWeight: 500 }}>{doc.title}</span>
                                <span
                                    className="mono"
                                    style={{ color: 'var(--fg-3)', fontSize: 10.5, fontFamily: 'var(--font-mono, monospace)' }}
                                >
                                    {doc.project_key} · {doc.source_path}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
