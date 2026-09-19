import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AxiosError } from 'axios';

/*
 * v8.37/W3c — ReviewTab Vitest scenarios. Mirrors SourceTab.test.tsx's
 * stub-the-hooks-module pattern so the component's branches (disabled
 * / loading / error / ready, page nav, approve, corrections queue) are
 * exercised without a network round-trip.
 */

type SummaryState = {
    data: { total: number; reviewed: number; unreviewed: number } | undefined;
    isLoading: boolean;
    isError: boolean;
    error: unknown;
};

const summaryState: SummaryState = {
    data: { total: 3, reviewed: 1, unreviewed: 2 },
    isLoading: false,
    isError: false,
    error: null,
};

type PageStatusState = {
    data: { page_number: number; status: 'reviewed' | 'unreviewed'; reviewed_by: string | null; reviewed_at: string | null } | undefined;
    isLoading: boolean;
    isError: boolean;
    lastRequestedPage: number | null;
};

const pageStatusState: PageStatusState = {
    data: { page_number: 1, status: 'unreviewed', reviewed_by: null, reviewed_at: null },
    isLoading: false,
    isError: false,
    lastRequestedPage: null,
};

const toggleMutation = {
    isPending: false,
    mutate: vi.fn(),
};

const approveDocMutation = {
    isPending: false,
    mutate: vi.fn(),
};

type CorrectionCandidate = {
    id: number;
    page_number: number;
    old_text: string;
    new_text: string;
    rationale: string | null;
    proposed_by: string;
    created_at: string | null;
};

type CorrectionsState = {
    data: { data: CorrectionCandidate[]; meta: { limit: number; offset: number; has_more: boolean } } | undefined;
    isLoading: boolean;
    isError: boolean;
    lastOffset: number | null;
};

const correctionsState: CorrectionsState = {
    data: {
        data: [
            {
                id: 51,
                page_number: 2,
                old_text: 'Bod',
                new_text: 'Bob',
                rationale: 'likely OCR misread',
                proposed_by: 'mcp:kb-propose-text-correction',
                created_at: '2026-09-19T10:00:00Z',
            },
        ],
        meta: { limit: 20, offset: 0, has_more: false },
    },
    isLoading: false,
    isError: false,
    lastOffset: null,
};

const approveCorrectionMutation = {
    isPending: false,
    mutate: vi.fn(),
};

const rejectCorrectionMutation = {
    isPending: false,
    mutate: vi.fn(),
};

vi.mock('./kb-review.api', () => ({
    // Copilot review PR #497 (pullrequestreview-5257179199) — this mock used
    // to treat ANY 404 as "feature disabled", a weaker contract than the
    // real isReviewDisabledError() (kb-review.api.ts) that let tests pass
    // even when the UI would show a generic error for an unrelated 404 in
    // production (R16). Mirrors the production check exactly: status 404
    // AND the KbReviewDisabledException message marker.
    isReviewDisabledError: (error: unknown) => {
        if (!(error instanceof AxiosError) || error.response?.status !== 404) {
            return false;
        }
        const data = error.response.data as { message?: unknown } | undefined;
        return typeof data?.message === 'string' && data.message.includes('Digitization Review is disabled');
    },
    useKbReviewSummary: () => ({
        data: summaryState.data,
        isLoading: summaryState.isLoading,
        isError: summaryState.isError,
        error: summaryState.error,
    }),
    useKbPageReviewStatus: (_id: number | null, page: number) => {
        pageStatusState.lastRequestedPage = page;
        return {
            data: pageStatusState.data,
            isLoading: pageStatusState.isLoading,
            isError: pageStatusState.isError,
        };
    },
    useSetKbPageReviewStatus: () => toggleMutation,
    useApproveKbDocument: () => approveDocMutation,
    useKbCorrections: (_id: number | null, _limit: number, offset: number) => {
        correctionsState.lastOffset = offset;
        return {
            data: correctionsState.data,
            isLoading: correctionsState.isLoading,
            isError: correctionsState.isError,
        };
    },
    useApproveCorrection: () => approveCorrectionMutation,
    useRejectCorrection: () => rejectCorrectionMutation,
}));

vi.mock('../shared/Toast', () => ({
    useToast: () => ({
        success: vi.fn(),
        error: vi.fn(),
        info: vi.fn(),
    }),
    ToastHost: () => null,
    pushToast: vi.fn(),
    dismissToast: vi.fn(),
}));

import { ReviewTab } from './ReviewTab';

function wrap(ui: React.ReactElement) {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

beforeEach(() => {
    vi.clearAllMocks();
    summaryState.data = { total: 3, reviewed: 1, unreviewed: 2 };
    summaryState.isLoading = false;
    summaryState.isError = false;
    summaryState.error = null;
    pageStatusState.data = { page_number: 1, status: 'unreviewed', reviewed_by: null, reviewed_at: null };
    pageStatusState.isLoading = false;
    pageStatusState.isError = false;
    pageStatusState.lastRequestedPage = null;
    toggleMutation.isPending = false;
    approveDocMutation.isPending = false;
    correctionsState.data = {
        data: [
            {
                id: 51,
                page_number: 2,
                old_text: 'Bod',
                new_text: 'Bob',
                rationale: 'likely OCR misread',
                proposed_by: 'mcp:kb-propose-text-correction',
                created_at: '2026-09-19T10:00:00Z',
            },
        ],
        meta: { limit: 20, offset: 0, has_more: false },
    };
    correctionsState.lastOffset = null;
    correctionsState.isLoading = false;
    correctionsState.isError = false;
    approveCorrectionMutation.isPending = false;
    rejectCorrectionMutation.isPending = false;
});

describe('ReviewTab', () => {
    it('renders a loading state', () => {
        summaryState.isLoading = true;
        wrap(<ReviewTab documentId={7} />);
        expect(screen.getByTestId('kb-review')).toHaveAttribute('data-state', 'loading');
    });

    it('renders the disabled panel on a 404 carrying the KbReviewDisabledException marker, not a generic error', () => {
        summaryState.isLoading = false;
        summaryState.isError = true;
        summaryState.data = undefined;
        summaryState.error = new AxiosError('Not Found', '404', undefined, undefined, {
            status: 404,
            // Copilot review PR #497 (pullrequestreview-5257179199) — the
            // real backend response body for the disabled-feature case
            // (app/Exceptions/KbReviewDisabledException.php); an empty body
            // would no longer trigger the mocked isReviewDisabledError()
            // now that it mirrors production's message-marker check.
            data: { message: 'Digitization Review is disabled (set KB_DIGITIZATION_REVIEW_ENABLED=true).' },
            statusText: 'Not Found',
            headers: {},
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            config: {} as any,
        });
        wrap(<ReviewTab documentId={7} />);
        // Copilot review PR #497 (pullrequestreview-5256869227) — data-state
        // stays within the shared idle|loading|ready|error|empty enum; the
        // disabled-feature branch is distinguished by data-feature instead.
        expect(screen.getByTestId('kb-review')).toHaveAttribute('data-state', 'ready');
        expect(screen.getByTestId('kb-review')).toHaveAttribute('data-feature', 'disabled');
        expect(screen.getByTestId('kb-review-disabled')).toBeInTheDocument();
        expect(screen.queryByTestId('kb-review-error')).not.toBeInTheDocument();
    });

    it('renders a generic error state on an UNRELATED 404 (e.g. document not found), not the disabled panel', () => {
        // Copilot review PR #497 (pullrequestreview-5257179199) — this is
        // the regression the mock alignment fix closes: before it, ANY 404
        // — including a document that genuinely doesn't exist — rendered
        // the "Digitization Review is disabled" panel instead of a real
        // error, hiding the actual failure from the operator.
        summaryState.isLoading = false;
        summaryState.isError = true;
        summaryState.data = undefined;
        summaryState.error = new AxiosError('Not Found', '404', undefined, undefined, {
            status: 404,
            data: { message: 'Document not found.' },
            statusText: 'Not Found',
            headers: {},
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            config: {} as any,
        });
        wrap(<ReviewTab documentId={7} />);
        expect(screen.getByTestId('kb-review')).toHaveAttribute('data-state', 'error');
        expect(screen.getByTestId('kb-review-error')).toBeInTheDocument();
        expect(screen.queryByTestId('kb-review-disabled')).not.toBeInTheDocument();
    });

    it('renders a generic error state on a non-404 failure', () => {
        summaryState.isLoading = false;
        summaryState.isError = true;
        summaryState.data = undefined;
        summaryState.error = new AxiosError('Server Error', '500', undefined, undefined, {
            status: 500,
            data: {},
            statusText: 'Server Error',
            headers: {},
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            config: {} as any,
        });
        wrap(<ReviewTab documentId={7} />);
        expect(screen.getByTestId('kb-review')).toHaveAttribute('data-state', 'error');
        expect(screen.getByTestId('kb-review-error')).toBeInTheDocument();
    });

    it('renders the "no pages" message instead of a page navigator when total is 0', () => {
        summaryState.data = { total: 0, reviewed: 0, unreviewed: 0 };
        wrap(<ReviewTab documentId={7} />);
        expect(screen.getByTestId('kb-review-no-pages')).toBeInTheDocument();
        expect(screen.queryByTestId('kb-review-page-nav')).not.toBeInTheDocument();
        // Copilot review PR #497 (pullrequestreview-5257179199) — total === 0
        // means per-page review is UNAVAILABLE for this document (no recorded
        // page count), not that review simply hasn't started; the summary
        // copy must say so, matching PageReviewSection's own "unavailable"
        // wording for the same condition rather than implying zero progress.
        expect(screen.getByTestId('kb-review-summary')).toHaveTextContent('unavailable');
        expect(screen.getByTestId('kb-review-summary')).not.toHaveTextContent('0 of 0 pages reviewed');
    });

    it('shows the page summary and disables Prev on page 1', () => {
        wrap(<ReviewTab documentId={7} />);
        expect(screen.getByTestId('kb-review-summary')).toHaveTextContent('1 of 3 pages reviewed');
        expect(screen.getByTestId('kb-review-page-number')).toHaveTextContent('Page 1 of 3');
        expect(screen.getByTestId('kb-review-page-prev')).toBeDisabled();
        expect(screen.getByTestId('kb-review-page-next')).not.toBeDisabled();
    });

    it('advances to page 2 when Next is clicked, actually re-requesting that page', async () => {
        wrap(<ReviewTab documentId={7} />);
        expect(pageStatusState.lastRequestedPage).toBe(1);

        await act(async () => {
            await userEvent.click(screen.getByTestId('kb-review-page-next'));
        });

        expect(screen.getByTestId('kb-review-page-number')).toHaveTextContent('Page 2 of 3');
        expect(pageStatusState.lastRequestedPage).toBe(2);
    });

    it('disables Next on the last page', async () => {
        wrap(<ReviewTab documentId={7} />);
        await act(async () => {
            await userEvent.click(screen.getByTestId('kb-review-page-next'));
            await userEvent.click(screen.getByTestId('kb-review-page-next'));
        });
        expect(screen.getByTestId('kb-review-page-number')).toHaveTextContent('Page 3 of 3');
        expect(screen.getByTestId('kb-review-page-next')).toBeDisabled();
    });

    it('toggling an unreviewed page calls the mutation with status=reviewed', async () => {
        pageStatusState.data = { page_number: 1, status: 'unreviewed', reviewed_by: null, reviewed_at: null };
        wrap(<ReviewTab documentId={7} />);

        expect(screen.getByTestId('kb-review-page-toggle')).toHaveTextContent('Mark reviewed');
        await act(async () => {
            await userEvent.click(screen.getByTestId('kb-review-page-toggle'));
        });

        expect(toggleMutation.mutate).toHaveBeenCalledWith(
            { page: 1, status: 'reviewed' },
            expect.anything(),
        );
    });

    it('toggling a reviewed page calls the mutation with status=unreviewed (the reverse direction actually fires)', async () => {
        pageStatusState.data = {
            page_number: 1,
            status: 'reviewed',
            reviewed_by: 'user:3',
            reviewed_at: '2026-09-19T09:00:00Z',
        };
        wrap(<ReviewTab documentId={7} />);

        expect(screen.getByTestId('kb-review-page-toggle')).toHaveTextContent('Mark unreviewed');
        expect(screen.getByTestId('kb-review-page-reviewed-by')).toHaveTextContent('by user:3');

        await act(async () => {
            await userEvent.click(screen.getByTestId('kb-review-page-toggle'));
        });

        expect(toggleMutation.mutate).toHaveBeenCalledWith(
            { page: 1, status: 'unreviewed' },
            expect.anything(),
        );
    });

    it('clicking Approve document calls the approve mutation', async () => {
        approveDocMutation.mutate.mockImplementation((_v, opts) => {
            opts.onSuccess({ approved: true });
        });
        wrap(<ReviewTab documentId={7} />);

        await act(async () => {
            await userEvent.click(screen.getByTestId('kb-review-approve'));
        });

        expect(approveDocMutation.mutate).toHaveBeenCalled();
        expect(screen.getByTestId('kb-review-approve-result')).toHaveTextContent(
            'Approved — promoted to human-reviewed.',
        );
    });

    it('renders a non-approval outcome with its reason, not as an error', async () => {
        approveDocMutation.mutate.mockImplementation((_v, opts) => {
            opts.onSuccess({ approved: false, reason: 'not_auto' });
        });
        wrap(<ReviewTab documentId={7} />);

        await act(async () => {
            await userEvent.click(screen.getByTestId('kb-review-approve'));
        });

        expect(screen.getByTestId('kb-review-approve-result')).toHaveTextContent(
            'Not approved: not_auto',
        );
    });

    it('renders the correction candidates queue with stable per-row testids', () => {
        wrap(<ReviewTab documentId={7} />);
        expect(screen.getByTestId('kb-review-correction-51')).toBeInTheDocument();
        expect(screen.getByTestId('kb-review-correction-51')).toHaveTextContent('Bod');
        expect(screen.getByTestId('kb-review-correction-51')).toHaveTextContent('Bob');
        // Copilot review PR #497 (pullrequestreview-5256918804) — the
        // corrections queue's own async region carries the shared
        // data-state/aria-busy contract (R11), independent of the outer
        // kb-review container's state.
        expect(screen.getByTestId('kb-review-corrections')).toHaveAttribute('data-state', 'ready');
        expect(screen.getByTestId('kb-review-corrections')).toHaveAttribute('aria-busy', 'false');
    });

    it('renders the empty state when there are no pending candidates', () => {
        correctionsState.data = { data: [], meta: { limit: 20, offset: 0, has_more: false } };
        wrap(<ReviewTab documentId={7} />);
        expect(screen.getByTestId('kb-review-corrections-empty')).toBeInTheDocument();
        expect(screen.getByTestId('kb-review-corrections')).toHaveAttribute('data-state', 'empty');
    });

    // Copilot review PR #497 (pullrequestreview-5256918804): the corrections
    // queue's loading/error branches were missing data-state/aria-busy,
    // breaking the shared Playwright/testid async-state contract other KB
    // tabs (e.g. GraphTab) already honour.
    it('renders a loading state for the corrections queue', () => {
        correctionsState.isLoading = true;
        wrap(<ReviewTab documentId={7} />);
        expect(screen.getByTestId('kb-review-corrections-loading')).toHaveAttribute('data-state', 'loading');
        expect(screen.getByTestId('kb-review-corrections-loading')).toHaveAttribute('aria-busy', 'true');
    });

    it('renders an error state for the corrections queue', () => {
        correctionsState.isError = true;
        correctionsState.data = undefined;
        wrap(<ReviewTab documentId={7} />);
        expect(screen.getByTestId('kb-review-corrections-error')).toHaveAttribute('data-state', 'error');
        expect(screen.getByTestId('kb-review-corrections-error')).toHaveAttribute('aria-busy', 'false');
    });

    it('clicking Approve on a candidate calls approveCorrection with that candidate id', async () => {
        wrap(<ReviewTab documentId={7} />);
        await act(async () => {
            await userEvent.click(screen.getByTestId('kb-review-correction-51-approve'));
        });
        expect(approveCorrectionMutation.mutate).toHaveBeenCalledWith(51, expect.anything());
    });

    it('clicking Reject on a candidate calls rejectCorrection with that candidate id', async () => {
        wrap(<ReviewTab documentId={7} />);
        await act(async () => {
            await userEvent.click(screen.getByTestId('kb-review-correction-51-reject'));
        });
        expect(rejectCorrectionMutation.mutate).toHaveBeenCalledWith(51, expect.anything());
    });

    it('advances the corrections offset when Next is clicked', async () => {
        correctionsState.data = {
            data: [
                {
                    id: 51,
                    page_number: 2,
                    old_text: 'Bod',
                    new_text: 'Bob',
                    rationale: null,
                    proposed_by: 'mcp:kb-propose-text-correction',
                    created_at: null,
                },
            ],
            meta: { limit: 20, offset: 0, has_more: true },
        };
        wrap(<ReviewTab documentId={7} />);
        expect(correctionsState.lastOffset).toBe(0);

        await act(async () => {
            await userEvent.click(screen.getByTestId('kb-review-corrections-next'));
        });

        expect(correctionsState.lastOffset).toBe(20);
    });

    // Copilot finding on PR #497: the server can cap the effective page size
    // below the requested CORRECTIONS_PAGE_SIZE (KB_REVIEW_CORRECTIONS_PAGE_SIZE),
    // and returns the applied value as meta.limit. Stepping by the hard-coded
    // constant instead of meta.limit desyncs the offset from what the server
    // actually returned.
    it('advances/retreats the corrections offset by the server-effective meta.limit, not the requested page size', async () => {
        correctionsState.data = {
            data: [
                {
                    id: 51,
                    page_number: 2,
                    old_text: 'Bod',
                    new_text: 'Bob',
                    rationale: null,
                    proposed_by: 'mcp:kb-propose-text-correction',
                    created_at: null,
                },
            ],
            meta: { limit: 5, offset: 0, has_more: true },
        };
        wrap(<ReviewTab documentId={7} />);
        expect(correctionsState.lastOffset).toBe(0);

        await act(async () => {
            await userEvent.click(screen.getByTestId('kb-review-corrections-next'));
        });
        expect(correctionsState.lastOffset).toBe(5);

        await act(async () => {
            await userEvent.click(screen.getByTestId('kb-review-corrections-prev'));
        });
        expect(correctionsState.lastOffset).toBe(0);
    });

    // Copilot finding on PR #497: switching the selected document must not
    // carry over the previous document's page cursor / corrections offset.
    it('resets the page cursor to 1 when the selected document changes', async () => {
        const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        const { rerender } = render(
            <QueryClientProvider client={qc}>
                <ReviewTab documentId={7} />
            </QueryClientProvider>,
        );

        await act(async () => {
            await userEvent.click(screen.getByTestId('kb-review-page-next'));
        });
        expect(screen.getByTestId('kb-review-page-number')).toHaveTextContent('Page 2 of 3');
        expect(pageStatusState.lastRequestedPage).toBe(2);

        rerender(
            <QueryClientProvider client={qc}>
                <ReviewTab documentId={8} />
            </QueryClientProvider>,
        );

        expect(screen.getByTestId('kb-review-page-number')).toHaveTextContent('Page 1 of 3');
        expect(pageStatusState.lastRequestedPage).toBe(1);
    });

    it('resets the corrections offset to 0 when the selected document changes', async () => {
        correctionsState.data = {
            data: [
                {
                    id: 51,
                    page_number: 2,
                    old_text: 'Bod',
                    new_text: 'Bob',
                    rationale: null,
                    proposed_by: 'mcp:kb-propose-text-correction',
                    created_at: null,
                },
            ],
            meta: { limit: 20, offset: 0, has_more: true },
        };
        const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        const { rerender } = render(
            <QueryClientProvider client={qc}>
                <ReviewTab documentId={7} />
            </QueryClientProvider>,
        );

        await act(async () => {
            await userEvent.click(screen.getByTestId('kb-review-corrections-next'));
        });
        expect(correctionsState.lastOffset).toBe(20);

        rerender(
            <QueryClientProvider client={qc}>
                <ReviewTab documentId={8} />
            </QueryClientProvider>,
        );

        expect(correctionsState.lastOffset).toBe(0);
    });
});
