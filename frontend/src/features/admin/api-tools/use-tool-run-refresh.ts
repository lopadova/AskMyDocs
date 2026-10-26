import { useEffect, useRef, type RefObject } from 'react';
import { apiToolsApi } from './api-tools.api';
import type { ToolDetail } from './types';
const activeStatuses = new Set(['pending', 'queued', 'running', 'waiting']);
export function hasPendingToolWork(detail: Pick<ToolDetail, 'runs' | 'verifications'> | null): boolean {
    return [...(detail?.runs ?? []), ...(detail?.verifications ?? [])].some((item) => item !== null && typeof item === 'object' && 'status' in item && activeStatuses.has(String(item.status)));
}
/** One delayed request at a time; no worker polling and no draft mutations here. */
export function useToolRunRefresh({ toolId, detail, selectionEpoch, epochRef, onRefresh, onError }: {
    toolId?: number;
    detail: ToolDetail | null;
    selectionEpoch: number;
    epochRef: RefObject<number>;
    onRefresh: (detail: ToolDetail) => void;
    onError: (error: unknown) => void;
}) {
    const callbacks = useRef({ onRefresh, onError });
    callbacks.current = { onRefresh, onError };
    const pending = hasPendingToolWork(detail);
    useEffect(() => {
        if (!toolId || !pending) return;
        let disposed = false;
        let delay = 2000;
        let timer: ReturnType<typeof setTimeout>;
        const current = () => !disposed && epochRef.current === selectionEpoch;
        async function refresh() {
            if (!current()) return;
            let scheduleAgain = true;
            try {
                const next = await apiToolsApi.show(toolId!);
                if (!current()) return;
                callbacks.current.onRefresh(next);
                scheduleAgain = hasPendingToolWork(next);
            } catch (error) { if (!current()) return; callbacks.current.onError(error); }
            if (current() && scheduleAgain) { delay = Math.min(Math.round(delay * 1.5), 10000); timer = setTimeout(() => void refresh(), delay); }
        }
        timer = setTimeout(() => void refresh(), delay);
        return () => { disposed = true; clearTimeout(timer); };
    }, [toolId, pending, selectionEpoch, epochRef]);
    return pending;
}
