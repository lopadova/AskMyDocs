/*
 * Cross-mount HTTP client for the native Evidence & Risk Review admin surface
 * (v8.13 / P11).
 *
 * Transport is delegated to the host's shared axios instance
 * (frontend/src/lib/api.ts), which already carries Sanctum credentials, the
 * XSRF-TOKEN → X-XSRF-TOKEN forwarding, and the host 401/419 interceptors —
 * so the package's gated /api/admin/evidence-risk-review/* routes authenticate
 * exactly like every other admin call.
 *
 * Failures surface LOUDLY (R14): a non-2xx throws `EvidenceApiError` carrying
 * the status + server payload; no method ever resolves to null/empty on error,
 * so the UI can always tell success from failure.
 */
import type { AxiosError, AxiosRequestConfig } from 'axios';
import { api } from '../../../../lib/api';
import type {
    Paginated,
    ProfileMetadata,
    ReviewLogFilters,
    ReviewLogRow,
    ReviewResult,
    Taxonomy,
} from './types';

export const EVIDENCE_RISK_REVIEW_API_BASE = '/api/admin/evidence-risk-review';

export class EvidenceApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly payload: unknown,
    ) {
        super(message);
        this.name = 'EvidenceApiError';
    }
}

async function request<T>(config: AxiosRequestConfig): Promise<T> {
    try {
        const response = await api.request<T>({
            ...config,
            url: `${EVIDENCE_RISK_REVIEW_API_BASE}${config.url ?? ''}`,
            headers: { Accept: 'application/json', ...(config.headers ?? {}) },
        });
        return response.data;
    } catch (error) {
        const axiosError = error as AxiosError<unknown>;
        const status = axiosError.response?.status ?? 0;
        const payload = axiosError.response?.data ?? null;
        const messageFromPayload =
            payload && typeof payload === 'object' && 'message' in payload
                ? String((payload as { message: unknown }).message)
                : null;
        throw new EvidenceApiError(
            messageFromPayload ?? axiosError.message ?? `Request failed with status ${status}.`,
            status,
            payload,
        );
    }
}

function reviewFilterParams(filters: ReviewLogFilters): Record<string, string | number> {
    return Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value !== undefined && value !== null && value !== ''),
    ) as Record<string, string | number>;
}

export const evidenceApi = {
    async listReviews(filters: ReviewLogFilters = {}): Promise<Paginated<ReviewLogRow>> {
        return request<Paginated<ReviewLogRow>>({ url: '/reviews', params: reviewFilterParams(filters) });
    },

    async getReview(reviewId: string): Promise<ReviewResult> {
        return request<ReviewResult>({ url: `/reviews/${encodeURIComponent(reviewId)}` });
    },

    async submitReview(payload: Record<string, unknown>, dryRun = false): Promise<ReviewResult> {
        return request<ReviewResult>({
            url: '/reviews',
            method: 'POST',
            data: payload,
            params: dryRun ? { dry_run: 1 } : undefined,
        });
    },

    async listProfiles(): Promise<ProfileMetadata[]> {
        const body = await request<{ profiles: Record<string, ProfileMetadata> }>({ url: '/profiles' });
        return Object.values(body.profiles ?? {});
    },

    async taxonomy(): Promise<Taxonomy> {
        return request<Taxonomy>({ url: '/taxonomy' });
    },
};

export function evidenceErrorMessage(cause: unknown): string {
    if (cause instanceof EvidenceApiError) {
        return cause.message;
    }
    if (cause instanceof Error) {
        return cause.message;
    }
    return 'Request failed.';
}
