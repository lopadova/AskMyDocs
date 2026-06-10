import { describe, it, expect } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import type { MouseEvent } from 'react';
import { useExplorerSelection } from './useExplorerSelection';

function clickEvent(mods: Partial<Pick<MouseEvent, 'metaKey' | 'ctrlKey' | 'shiftKey'>> = {}): MouseEvent {
    return {
        metaKey: false,
        ctrlKey: false,
        shiftKey: false,
        ...mods,
    } as MouseEvent;
}

describe('useExplorerSelection', () => {
    it('plain click replaces the selection with a single id', () => {
        const { result } = renderHook(() => useExplorerSelection([1, 2, 3]));

        act(() => result.current.activate(1, clickEvent()));
        expect([...result.current.selectedIds]).toEqual([1]);

        // A second plain click REPLACES, it does not add.
        act(() => result.current.activate(3, clickEvent()));
        expect([...result.current.selectedIds]).toEqual([3]);
    });

    it('ctrl/cmd click toggles ids in and out', () => {
        const { result } = renderHook(() => useExplorerSelection([1, 2, 3]));

        act(() => result.current.activate(1, clickEvent({ metaKey: true })));
        act(() => result.current.activate(2, clickEvent({ ctrlKey: true })));
        expect(new Set(result.current.selectedIds)).toEqual(new Set([1, 2]));

        // Toggling 1 again removes it.
        act(() => result.current.activate(1, clickEvent({ metaKey: true })));
        expect([...result.current.selectedIds]).toEqual([2]);
    });

    it('shift click selects the range over the visible order from the anchor', () => {
        const { result } = renderHook(() => useExplorerSelection([10, 20, 30, 40, 50]));

        // Anchor on 20, then shift-click 40 → 20,30,40.
        act(() => result.current.activate(20, clickEvent()));
        act(() => result.current.activate(40, clickEvent({ shiftKey: true })));
        expect(new Set(result.current.selectedIds)).toEqual(new Set([20, 30, 40]));
    });

    it('shift click ranges work backwards too', () => {
        const { result } = renderHook(() => useExplorerSelection([10, 20, 30, 40, 50]));
        act(() => result.current.activate(40, clickEvent()));
        act(() => result.current.activate(10, clickEvent({ shiftKey: true })));
        expect(new Set(result.current.selectedIds)).toEqual(new Set([10, 20, 30, 40]));
    });

    it('selectAll selects every visible id; unchecking clears', () => {
        const { result } = renderHook(() => useExplorerSelection([1, 2, 3]));
        act(() => result.current.selectAll(true));
        expect(new Set(result.current.selectedIds)).toEqual(new Set([1, 2, 3]));
        act(() => result.current.selectAll(false));
        expect(result.current.selectedIds.size).toBe(0);
    });

    it('prunes selection to ids still visible when the visible set changes (R17)', () => {
        const { result, rerender } = renderHook(
            ({ ids }: { ids: number[] }) => useExplorerSelection(ids),
            { initialProps: { ids: [1, 2, 3] } },
        );

        act(() => result.current.selectAll(true));
        expect(result.current.selectedIds.size).toBe(3);

        // Navigate to a folder that only contains id 2 → 1 and 3 drop.
        rerender({ ids: [2] });
        expect([...result.current.selectedIds]).toEqual([2]);
    });

    it('clear empties the selection', () => {
        const { result } = renderHook(() => useExplorerSelection([1, 2]));
        act(() => result.current.selectAll(true));
        act(() => result.current.clear());
        expect(result.current.selectedIds.size).toBe(0);
    });
});
