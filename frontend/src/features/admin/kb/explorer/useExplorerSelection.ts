import { useCallback, useEffect, useRef, useState } from 'react';
import type { MouseEvent } from 'react';

/*
 * Selection model for the explorer grid, doc-only (folders aren't
 * selectable in v1). Mirrors a file-manager:
 *   - plain click           → replace selection with just this doc
 *   - ctrl/cmd click        → toggle this doc in/out
 *   - shift click           → range-select from the anchor over the
 *                             current VISIBLE order
 *
 * The anchor + selection are keyed by document id. `visibleIds` is the
 * ordered id list currently on screen; when it changes (folder
 * navigation, filter, mode switch) the selection is pruned to ids that
 * still exist so a stale id can't survive into a bulk action (R17).
 */
export interface ExplorerSelection {
    selectedIds: Set<number>;
    isSelected: (id: number) => boolean;
    /** Click handler carrying the modifier keys. */
    activate: (id: number, e: MouseEvent) => void;
    /** Checkbox toggle (single id in/out, anchor moves to it). */
    toggle: (id: number) => void;
    /** Header select-all checkbox over the visible ids. */
    selectAll: (checked: boolean) => void;
    clear: () => void;
}

export function useExplorerSelection(visibleIds: number[]): ExplorerSelection {
    const [selectedIds, setSelectedIds] = useState<Set<number>>(() => new Set());
    const anchorRef = useRef<number | null>(null);

    // Prune the selection to ids still visible whenever the visible set
    // changes. Keyed on a stable join so this only fires on real changes,
    // not on every render (the array identity differs each render).
    const visibleKey = visibleIds.join(',');
    useEffect(() => {
        setSelectedIds((prev) => {
            if (prev.size === 0) {
                return prev;
            }
            const visible = new Set(visibleIds);
            let changed = false;
            const next = new Set<number>();
            for (const id of prev) {
                if (visible.has(id)) {
                    next.add(id);
                } else {
                    changed = true;
                }
            }
            return changed ? next : prev;
        });
        if (anchorRef.current !== null && !visibleIds.includes(anchorRef.current)) {
            anchorRef.current = null;
        }
        // visibleKey captures the meaningful change; visibleIds identity
        // alone would re-run every render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [visibleKey]);

    const isSelected = useCallback((id: number) => selectedIds.has(id), [selectedIds]);

    const activate = useCallback(
        (id: number, e: MouseEvent) => {
            const toggleKey = e.metaKey || e.ctrlKey;
            const rangeKey = e.shiftKey;

            if (rangeKey && anchorRef.current !== null) {
                const start = visibleIds.indexOf(anchorRef.current);
                const end = visibleIds.indexOf(id);
                if (start !== -1 && end !== -1) {
                    const [lo, hi] = start <= end ? [start, end] : [end, start];
                    const range = visibleIds.slice(lo, hi + 1);
                    setSelectedIds((prev) => {
                        const next = new Set(prev);
                        for (const rid of range) {
                            next.add(rid);
                        }
                        return next;
                    });
                    return;
                }
            }

            if (toggleKey) {
                setSelectedIds((prev) => {
                    const next = new Set(prev);
                    if (next.has(id)) {
                        next.delete(id);
                    } else {
                        next.add(id);
                    }
                    return next;
                });
                anchorRef.current = id;
                return;
            }

            // Plain click → single selection.
            setSelectedIds(new Set([id]));
            anchorRef.current = id;
        },
        [visibleIds],
    );

    const toggle = useCallback((id: number) => {
        setSelectedIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
        anchorRef.current = id;
    }, []);

    const selectAll = useCallback(
        (checked: boolean) => {
            setSelectedIds(checked ? new Set(visibleIds) : new Set());
            anchorRef.current = null;
        },
        [visibleIds],
    );

    const clear = useCallback(() => {
        setSelectedIds(new Set());
        anchorRef.current = null;
    }, []);

    return { selectedIds, isSelected, activate, toggle, selectAll, clear };
}
