import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { apiToolsApi } from './api-tools.api';
import { blankDefinition } from './model';
import type { ApiTool, ToolDetail } from './types';
import { useToolRunRefresh } from './use-tool-run-refresh';
vi.mock('./api-tools.api', () => ({ apiToolsApi: { show: vi.fn() } }));
const tool: ApiTool = { id: 3, name: 'Orders', description: 'Orders', project_key: 'sales', status: 'draft', failure_threshold: 3, definition: blankDefinition() };
const pending: ToolDetail = { tool, runs: [], revisions: [], endpoints: [], verifications: [{ id: 5, status: 'running' }] };
const terminal: ToolDetail = { ...pending, verifications: [{ id: 5, status: 'passed' }] };
beforeEach(() => { vi.useFakeTimers(); vi.clearAllMocks(); });
afterEach(() => { vi.useRealTimers(); });
it('delays polling, backs off while running and stops when the server returns terminal work', async () => {
    const onRefresh = vi.fn(); const onError = vi.fn(); const epoch = { current: 1 };
    vi.mocked(apiToolsApi.show).mockResolvedValueOnce(pending).mockResolvedValueOnce(terminal);
    const { unmount } = renderHook(() => useToolRunRefresh({ toolId: 3, detail: pending, selectionEpoch: 1, epochRef: epoch, onRefresh, onError }));
    await act(() => vi.advanceTimersByTimeAsync(1999)); expect(apiToolsApi.show).not.toHaveBeenCalled();
    await act(() => vi.advanceTimersByTimeAsync(1)); expect(apiToolsApi.show).toHaveBeenCalledTimes(1);
    await act(() => vi.advanceTimersByTimeAsync(2999)); expect(apiToolsApi.show).toHaveBeenCalledTimes(1);
    await act(() => vi.advanceTimersByTimeAsync(1)); expect(apiToolsApi.show).toHaveBeenCalledTimes(2);
    expect(onRefresh).toHaveBeenLastCalledWith(terminal);
    await act(() => vi.advanceTimersByTimeAsync(60000)); expect(apiToolsApi.show).toHaveBeenCalledTimes(2); expect(onError).not.toHaveBeenCalled();
    unmount();
});
it('ignores an in-flight response after changing selection and cancels timers on unmount', async () => {
    let resolve!: (detail: ToolDetail) => void;
    vi.mocked(apiToolsApi.show).mockReturnValueOnce(new Promise((done) => { resolve = done; }));
    const onRefresh = vi.fn(); const onError = vi.fn(); const epoch = { current: 1 };
    const { rerender, unmount } = renderHook(({ id, token }) => useToolRunRefresh({ toolId: id, detail: pending, selectionEpoch: token, epochRef: epoch, onRefresh, onError }), { initialProps: { id: 3, token: 1 } });
    await act(() => vi.advanceTimersByTimeAsync(2000)); expect(apiToolsApi.show).toHaveBeenCalledWith(3);
    epoch.current = 2; rerender({ id: 4, token: 2 });
    await act(async () => { resolve(terminal); }); expect(onRefresh).not.toHaveBeenCalled();
    unmount(); await act(() => vi.advanceTimersByTimeAsync(60000)); expect(apiToolsApi.show).toHaveBeenCalledTimes(1);
});
it('does not schedule requests when all runs and verifications are terminal', async () => {
    const onRefresh = vi.fn(); const onError = vi.fn(); const epoch = { current: 1 };
    const { unmount } = renderHook(() => useToolRunRefresh({ toolId: 3, detail: terminal, selectionEpoch: 1, epochRef: epoch, onRefresh, onError }));
    await act(() => vi.advanceTimersByTimeAsync(60000)); expect(apiToolsApi.show).not.toHaveBeenCalled(); unmount();
});
