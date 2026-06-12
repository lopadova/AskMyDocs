import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { KB_PROJECTS_KEY, KB_TREE_KEY } from '../kb-tree.api';
import {
    isTerminalBatch,
    kbUploadApi,
    type StageInput,
    type UploadBatchResponse,
} from './kb-upload.api';

/**
 * v8.9 — react-query hooks for the upload modal. Mutations surface errors via
 * their result object (the modal renders them — R14, no silent failure); the
 * progress query polls until the batch is terminal, then STOPS (no busy-loop).
 */

const KB_UPLOAD_KEY = ['admin', 'kb', 'uploads'] as const;
const batchKey = (id: string | null) => [...KB_UPLOAD_KEY, id ?? 'idle'] as const;

export function useStageBatch() {
    return useMutation({
        mutationFn: (input: StageInput) => kbUploadApi.stage(input),
    });
}

export function useCommitBatch() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (vars: { batchId: string; expectedItemIds?: string[] }) =>
            kbUploadApi.commit(vars.batchId, vars.expectedItemIds),
        onSuccess: () => {
            // New docs will surface in the tree + project list once ingest runs.
            qc.invalidateQueries({ queryKey: KB_TREE_KEY });
            qc.invalidateQueries({ queryKey: KB_PROJECTS_KEY });
        },
    });
}

export function useCancelBatch() {
    return useMutation({
        mutationFn: (batchId: string) => kbUploadApi.cancel(batchId),
    });
}

export function useRemoveStagedItem() {
    return useMutation({
        mutationFn: (vars: { batchId: string; itemId: string }) =>
            kbUploadApi.removeItem(vars.batchId, vars.itemId),
    });
}

/**
 * Poll batch progress. `enabled` only when a batch id exists AND `poll` is on
 * (the modal turns it on only during committing/progress). `refetchInterval`
 * returns false once the batch reaches a terminal state so polling stops.
 */
export function useBatchProgress(batchId: string | null, poll: boolean) {
    return useQuery<UploadBatchResponse>({
        queryKey: batchKey(batchId),
        queryFn: () => kbUploadApi.status(batchId as string),
        enabled: batchId !== null && poll,
        refetchInterval: (query) => {
            const data = query.state.data;
            if (!poll || !data) {
                return poll ? 2000 : false;
            }
            return isTerminalBatch(data.batch) ? false : 2000;
        },
        staleTime: 0,
        retry: false,
    });
}
