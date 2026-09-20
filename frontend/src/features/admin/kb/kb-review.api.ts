import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';
import {
    adminKbReviewApi,
    type KbApproveResult,
    type KbCorrectionOutcome,
    type KbCorrectionsResponse,
    type KbPageReviewStatus,
    type KbPageReviewStatusValue,
    type KbReviewSummary,
} from '../admin.api';
import { KB_DOC_KEY } from './kb-document.api';

/*
 * v8.37/W3c — Digitization Review tab hooks (ADR 0031). Mirrors the
 * shape of kb-document.api.ts: reads are plain `useQuery`, writes are
 * `useMutation` + targeted invalidation.
 *
 * `enabled: false` on the disabled-feature 404 is handled by the
 * QUERY itself surfacing `isError` — the component reads that as the
 * "Digitization Review is disabled" state (R43), not a generic error,
 * via `isReviewDisabledError()` below.
 *
 * Copilot review PR #497 (pullrequestreview-5258159759) — that check is
 * NOT a bare status-404 check (an unrelated 404 would then be
 * misclassified as "feature disabled" too): it also requires the
 * `KbReviewDisabledException` message marker, per
 * REVIEW_DISABLED_MESSAGE_MARKER below.
 */

export const KB_REVIEW_KEY = ['admin', 'kb', 'review'] as const;

/**
 * Copilot review PR #497 (pullrequestreview-5257061609) — matching on
 * status 404 alone misclassified ANY 404 from this API surface (e.g. a
 * document that genuinely doesn't exist) as "feature disabled". Every
 * mutating entry point of KbReviewService throws
 * `KbReviewDisabledException` with this exact message
 * (app/Exceptions/KbReviewDisabledException.php) when the feature flag
 * is off, and Laravel renders it as `{"message": "..."}` for JSON
 * requests — key on that substring too, not just the status code.
 */
const REVIEW_DISABLED_MESSAGE_MARKER = 'Digitization Review is disabled';

/**
 * Copilot review PR #497 (pullrequestreview-5257132403) — `error instanceof
 * AxiosError` can be unreliable with bundlers or multiple axios copies in
 * the dependency tree; the rest of the codebase (routes/guards.tsx,
 * lib/laravel-errors.ts) consistently uses the `axios.isAxiosError()` type
 * guard instead, so this file now matches.
 */
export function isReviewDisabledError(error: unknown): boolean {
    if (!axios.isAxiosError(error) || error.response?.status !== 404) {
        return false;
    }

    const data = error.response.data as { message?: unknown } | undefined;
    return typeof data?.message === 'string' && data.message.includes(REVIEW_DISABLED_MESSAGE_MARKER);
}

export function useKbReviewSummary(documentId: number | null) {
    return useQuery<KbReviewSummary>({
        queryKey: [...KB_REVIEW_KEY, 'summary', documentId],
        queryFn: () => adminKbReviewApi.summary(documentId as number),
        enabled: typeof documentId === 'number',
        staleTime: 10_000,
        retry: false,
    });
}

export function useKbPageReviewStatus(documentId: number | null, page: number) {
    return useQuery<KbPageReviewStatus>({
        queryKey: [...KB_REVIEW_KEY, 'page', documentId, page],
        queryFn: () => adminKbReviewApi.pageStatus(documentId as number, page),
        enabled: typeof documentId === 'number' && page >= 1,
        staleTime: 10_000,
        retry: false,
    });
}

export function useSetKbPageReviewStatus(documentId: number | null) {
    const qc = useQueryClient();
    return useMutation<KbPageReviewStatus, Error, { page: number; status: KbPageReviewStatusValue }>({
        mutationFn: ({ page, status }) => {
            if (typeof documentId !== 'number') {
                throw new Error('useSetKbPageReviewStatus called without a documentId');
            }
            return adminKbReviewApi.setPageStatus(documentId, page, status);
        },
        onSuccess: (_data, { page }) => {
            if (typeof documentId !== 'number') return;
            qc.invalidateQueries({ queryKey: [...KB_REVIEW_KEY, 'page', documentId, page] });
            qc.invalidateQueries({ queryKey: [...KB_REVIEW_KEY, 'summary', documentId] });
        },
    });
}

export function useApproveKbDocument(documentId: number | null) {
    const qc = useQueryClient();
    return useMutation<KbApproveResult, Error, void>({
        mutationFn: () => {
            if (typeof documentId !== 'number') {
                throw new Error('useApproveKbDocument called without a documentId');
            }
            return adminKbReviewApi.approve(documentId);
        },
        onSuccess: () => {
            if (typeof documentId !== 'number') return;
            // Approval flips is_canonical/generation_source and writes a
            // kb_canonical_audit row — the document detail pane + the
            // History tab both need a fresh read.
            qc.invalidateQueries({ queryKey: [...KB_DOC_KEY, 'show', documentId] });
            qc.invalidateQueries({ queryKey: [...KB_DOC_KEY, 'history', documentId] });
        },
    });
}

export function useKbCorrections(documentId: number | null, limit: number, offset: number) {
    return useQuery<KbCorrectionsResponse>({
        queryKey: [...KB_REVIEW_KEY, 'corrections', documentId, limit, offset],
        queryFn: () => adminKbReviewApi.corrections(documentId as number, limit, offset),
        enabled: typeof documentId === 'number',
        staleTime: 5_000,
        retry: false,
    });
}

function invalidateCorrections(qc: ReturnType<typeof useQueryClient>, documentId: number | null) {
    if (typeof documentId !== 'number') return;
    qc.invalidateQueries({ queryKey: [...KB_REVIEW_KEY, 'corrections', documentId] });
}

export function useApproveCorrection(documentId: number | null) {
    const qc = useQueryClient();
    return useMutation<KbCorrectionOutcome, Error, number>({
        mutationFn: (candidateId) => adminKbReviewApi.approveCorrection(candidateId),
        onSuccess: () => {
            invalidateCorrections(qc, documentId);
            // A applied correction re-embeds the document family into a
            // new version — the detail pane, History, and the review
            // summary (page structure may shift) all go stale.
            if (typeof documentId !== 'number') return;
            qc.invalidateQueries({ queryKey: [...KB_DOC_KEY, 'show', documentId] });
            qc.invalidateQueries({ queryKey: [...KB_DOC_KEY, 'history', documentId] });
            qc.invalidateQueries({ queryKey: [...KB_REVIEW_KEY, 'summary', documentId] });
        },
    });
}

export function useRejectCorrection(documentId: number | null) {
    const qc = useQueryClient();
    return useMutation<KbCorrectionOutcome, Error, number>({
        mutationFn: (candidateId) => adminKbReviewApi.rejectCorrection(candidateId),
        onSuccess: () => invalidateCorrections(qc, documentId),
    });
}
