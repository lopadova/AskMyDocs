import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    adminKbDocumentApi,
    adminKbGraphApi,
    type KbDocument,
    type KbGraphResponse,
    type KbHistoryResponse,
    type KbRawResponse,
    type KbUpdateRawResponse,
} from '../admin.api';
import { KB_TREE_KEY } from './kb-tree.api';

/*
 * Phase G2 — KB document detail hooks. Separate from the tree hook
 * so the two query graphs can stale independently: the tree is
 * volatile as deletes happen, but a single document's raw body is
 * pinned by `content_hash`.
 *
 * On mutation (restore / delete) we invalidate BOTH the tree key
 * AND the doc key, so the split-panel view updates together.
 */

export const KB_DOC_KEY = ['admin', 'kb', 'doc'] as const;

export function useKbDocument(id: number | null) {
    return useQuery<KbDocument>({
        queryKey: [...KB_DOC_KEY, 'show', id],
        queryFn: () => adminKbDocumentApi.show(id as number, true),
        enabled: typeof id === 'number',
        staleTime: 15_000,
    });
}

export function useKbRaw(id: number | null) {
    return useQuery<KbRawResponse>({
        queryKey: [...KB_DOC_KEY, 'raw', id],
        queryFn: () => adminKbDocumentApi.raw(id as number),
        enabled: typeof id === 'number',
        staleTime: 30_000,
        retry: false,
    });
}

export function useKbHistory(id: number | null, page: number) {
    return useQuery<KbHistoryResponse>({
        queryKey: [...KB_DOC_KEY, 'history', id, page],
        queryFn: () => adminKbDocumentApi.history(id as number, page),
        enabled: typeof id === 'number',
        staleTime: 15_000,
    });
}

export function useRestoreKbDocument() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: number) => adminKbDocumentApi.restore(id),
        onSuccess: (_data, id) => {
            qc.invalidateQueries({ queryKey: KB_TREE_KEY });
            qc.invalidateQueries({ queryKey: [...KB_DOC_KEY, 'show', id] });
        },
    });
}

/**
 * Phase G3 — write path. Saves the buffer back through PATCH /raw
 * and invalidates every read-side cache a re-ingest could affect:
 *   - raw markdown (content_hash flips after the save),
 *   - document detail (chunks_count / audits_count drift once the
 *     IngestDocumentJob completes),
 *   - history (new audit row + downstream job-emitted rows),
 *   - tree (indexed_at / status may flip).
 */
export function useUpdateKbRaw(id: number | null) {
    const qc = useQueryClient();
    return useMutation<KbUpdateRawResponse, Error, string>({
        mutationFn: (content: string) => {
            if (typeof id !== 'number') {
                throw new Error('useUpdateKbRaw called without an id');
            }
            return adminKbDocumentApi.updateRaw(id, content);
        },
        onSuccess: () => {
            if (typeof id !== 'number') return;
            qc.invalidateQueries({ queryKey: [...KB_DOC_KEY, 'raw', id] });
            qc.invalidateQueries({ queryKey: [...KB_DOC_KEY, 'show', id] });
            qc.invalidateQueries({ queryKey: [...KB_DOC_KEY, 'history', id] });
            qc.invalidateQueries({ queryKey: KB_TREE_KEY });
        },
    });
}

export function useDeleteKbDocument() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (v: { id: number; force?: boolean }) =>
            adminKbDocumentApi.destroy(v.id, v.force ?? false),
        onSuccess: (_data, v) => {
            qc.invalidateQueries({ queryKey: KB_TREE_KEY });
            qc.invalidateQueries({ queryKey: [...KB_DOC_KEY, 'show', v.id] });
        },
    });
}

/**
 * Phase G4 — 1-hop subgraph rooted at this doc's canonical node.
 * `staleTime` is small because promotions / edits change the graph.
 * `retry: false` so a 500 surfaces in `isError` immediately — the
 * SPA renders a stable `data-state="error"` wrapper instead of
 * flashing between loading states during the retry chain.
 */
export function useKbGraph(id: number | null) {
    return useQuery<KbGraphResponse>({
        queryKey: [...KB_DOC_KEY, 'graph', id],
        queryFn: () => adminKbGraphApi.graph(id as number),
        enabled: typeof id === 'number',
        staleTime: 10_000,
        retry: false,
    });
}

/**
 * Phase G4 — server-side PDF render. On success we trigger a blob
 * download so the user gets the file; on error we surface the 501
 * message (or generic 500 text) as a toast. The mutation does NOT
 * invalidate any query — the doc's content is unchanged.
 */
export function useExportPdf(id: number | null) {
    return useMutation<Blob, Error, void>({
        mutationFn: async () => {
            if (typeof id !== 'number') {
                throw new Error('useExportPdf called without an id');
            }
            return adminKbGraphApi.exportPdf(id);
        },
    });
}
