import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    adminKbDocumentApi,
    type KbDocument,
    type KbHistoryResponse,
    type KbRawResponse,
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
