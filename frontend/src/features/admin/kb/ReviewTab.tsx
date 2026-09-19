import { useEffect, useState } from 'react';
import { useToast } from '../shared/Toast';
import type { KbCorrectionCandidate } from '../admin.api';
import {
    isReviewDisabledError,
    useApproveCorrection,
    useApproveKbDocument,
    useKbCorrections,
    useKbPageReviewStatus,
    useKbReviewSummary,
    useRejectCorrection,
    useSetKbPageReviewStatus,
} from './kb-review.api';

/*
 * v8.37/W3c — Digitization Review tab (ADR 0031). Two independent
 * sections, matching the service contract's own independence (see
 * the doc-site gotcha "approving does not require reviewing every
 * page first"):
 *
 *   1. Per-page review — a Prev/Next walk over `1..summary.total`
 *      with a reviewed/unreviewed toggle per page, PLUS the
 *      document-level Approve action (auto -> human).
 *   2. The pending correction-candidate queue (ADR 0031 §6) —
 *      agent-proposed OCR fixes a human approves or rejects.
 *
 * `summary.total === 0` is the one signal that per-page review is
 * unavailable for this document at all (see admin.api.ts's docblock
 * on `KbReviewSummary`) — the page-nav section degrades to an
 * explanatory message instead of rendering a Prev/Next control that
 * would only ever 404.
 *
 * R43: the whole tab degrades to a clean "disabled" panel when
 * `KB_DIGITIZATION_REVIEW_ENABLED` is off (summary 404s), never a
 * raw error state — `isReviewDisabledError()` distinguishes that
 * from a genuine failure.
 */

export interface ReviewTabProps {
    documentId: number;
}

const CORRECTIONS_PAGE_SIZE = 20;

export function ReviewTab({ documentId }: ReviewTabProps) {
    const summary = useKbReviewSummary(documentId);

    if (summary.isLoading) {
        return (
            <div data-testid="kb-review" data-state="loading" aria-busy="true" style={{ color: 'var(--fg-3)' }}>
                Loading review status…
            </div>
        );
    }

    if (summary.isError || !summary.data) {
        if (isReviewDisabledError(summary.error)) {
            // Copilot review PR #497 (pullrequestreview-5256869227) —
            // data-state stays within the repo's shared async-state
            // contract (idle|loading|ready|error|empty, R11); the
            // feature-disabled 404 is a settled, non-loading, non-error
            // terminal state, so it's `ready` with a secondary
            // `data-feature="disabled"` flag distinguishing it from the
            // normal populated panel.
            return (
                <div
                    data-testid="kb-review"
                    data-state="ready"
                    data-feature="disabled"
                    aria-busy="false"
                    style={{ padding: 12, color: 'var(--fg-3)', fontSize: 12.5 }}
                >
                    <div data-testid="kb-review-disabled">
                        Digitization Review is disabled on this deployment
                        (<code style={{ fontFamily: 'var(--font-mono)' }}>KB_DIGITIZATION_REVIEW_ENABLED</code>).
                    </div>
                </div>
            );
        }

        return (
            <div
                data-testid="kb-review"
                data-state="error"
                aria-busy="false"
                style={{ color: 'var(--danger-fg, #b91c1c)', fontSize: 12.5 }}
            >
                <div data-testid="kb-review-error">Could not load review status.</div>
            </div>
        );
    }

    return (
        <div
            data-testid="kb-review"
            data-state="ready"
            aria-busy="false"
            style={{ display: 'flex', flexDirection: 'column', gap: 16 }}
        >
            <ApprovalSection documentId={documentId} summary={summary.data} />
            <PageReviewSection documentId={documentId} total={summary.data.total} />
            <CorrectionsSection documentId={documentId} />
        </div>
    );
}

function ApprovalSection({
    documentId,
    summary,
}: {
    documentId: number;
    summary: { total: number; reviewed: number; unreviewed: number };
}) {
    const approveMut = useApproveKbDocument(documentId);
    const toast = useToast();
    const [lastResult, setLastResult] = useState<{ approved: boolean; reason?: string } | null>(null);

    function handleApprove() {
        approveMut.mutate(undefined, {
            onSuccess: (result) => {
                setLastResult(result);
                if (result.approved) {
                    toast.success('Document approved.', 'toast-success');
                } else {
                    // A non-approval here is a decided, non-error outcome
                    // (e.g. already human, or not eligible) — info, not error.
                    toast.info(
                        `Not approved${result.reason ? ` (${result.reason})` : ''}.`,
                        'toast-info',
                    );
                }
            },
            onError: () => toast.error('Approve request failed.', 'toast-error'),
        });
    }

    return (
        <section
            data-testid="kb-review-approval"
            style={{
                display: 'flex',
                flexDirection: 'column',
                gap: 8,
                padding: 12,
                border: '1px solid var(--hairline)',
                borderRadius: 8,
                background: 'var(--bg-0)',
            }}
        >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10 }}>
                <div data-testid="kb-review-summary" style={{ fontSize: 12.5, color: 'var(--fg-2)' }}>
                    {summary.total > 0
                        ? `${summary.reviewed} of ${summary.total} pages reviewed`
                        : // Copilot review PR #497 (pullrequestreview-5257179199,
                          // discussion on ReviewTab.tsx:154) — total === 0 means
                          // per-page review is UNAVAILABLE for this document (no
                          // recorded page count — see the module docblock and
                          // PageReviewSection's matching copy below), not that
                          // review simply hasn't started yet.
                          'Per-page review is unavailable for this document (no recorded page count).'}
                </div>
                <button
                    type="button"
                    data-testid="kb-review-approve"
                    onClick={handleApprove}
                    disabled={approveMut.isPending}
                    style={primaryBtnStyle(approveMut.isPending)}
                >
                    {approveMut.isPending ? 'Approving…' : 'Approve document'}
                </button>
            </div>
            {lastResult ? (
                <div data-testid="kb-review-approve-result" style={{ fontSize: 11.5, color: 'var(--fg-3)' }}>
                    {lastResult.approved
                        ? 'Approved — promoted to human-reviewed.'
                        : `Not approved${lastResult.reason ? `: ${lastResult.reason}` : '.'}`}
                </div>
            ) : null}
        </section>
    );
}

function PageReviewSection({ documentId, total }: { documentId: number; total: number }) {
    const [page, setPage] = useState(1);

    // Selecting a different document must not carry over the previous
    // document's page cursor — a stale page can exceed the new
    // document's total (or land on a doc with none at all).
    useEffect(() => {
        setPage(1);
    }, [documentId]);

    if (total === 0) {
        return (
            <section
                data-testid="kb-review-no-pages"
                style={{
                    padding: 12,
                    border: '1px solid var(--hairline)',
                    borderRadius: 8,
                    background: 'var(--bg-0)',
                    color: 'var(--fg-3)',
                    fontSize: 12.5,
                }}
            >
                This document has no recorded page count (not converted through OCR/PDF
                processing) — per-page review is unavailable.
            </section>
        );
    }

    return <PageReviewNavigator documentId={documentId} total={total} page={page} onPageChange={setPage} />;
}

function PageReviewNavigator({
    documentId,
    total,
    page,
    onPageChange,
}: {
    documentId: number;
    total: number;
    page: number;
    onPageChange: (next: number) => void;
}) {
    const status = useKbPageReviewStatus(documentId, page);
    const toggleMut = useSetKbPageReviewStatus(documentId);
    const toast = useToast();

    function handleToggle() {
        if (!status.data) return;
        const next = status.data.status === 'reviewed' ? 'unreviewed' : 'reviewed';
        toggleMut.mutate(
            { page, status: next },
            {
                onSuccess: () => toast.success(`Page ${page} marked ${next}.`, 'toast-success'),
                onError: () => toast.error('Could not update page review status.', 'toast-error'),
            },
        );
    }

    // Copilot review PR #497 (pullrequestreview-5257223251) — this navigator is
    // an async surface (it fetches page status and runs a toggle mutation), but
    // previously exposed no observable async-state attributes, forcing E2E
    // waits onto inner text elements instead of the shared data-state/aria-busy
    // contract every other async region in this file uses.
    const navState = status.isLoading ? 'loading' : status.isError || !status.data ? 'error' : 'ready';

    return (
        <section
            data-testid="kb-review-page-nav"
            data-state={navState}
            aria-busy={status.isLoading || toggleMut.isPending}
            style={{
                display: 'flex',
                flexDirection: 'column',
                gap: 8,
                padding: 12,
                border: '1px solid var(--hairline)',
                borderRadius: 8,
                background: 'var(--bg-0)',
            }}
        >
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <button
                        type="button"
                        data-testid="kb-review-page-prev"
                        onClick={() => onPageChange(Math.max(1, page - 1))}
                        disabled={page <= 1}
                        style={pagerBtnStyle(page <= 1)}
                    >
                        Prev
                    </button>
                    <span data-testid="kb-review-page-number" style={{ fontSize: 12.5, color: 'var(--fg-1)' }}>
                        Page {page} of {total}
                    </span>
                    <button
                        type="button"
                        data-testid="kb-review-page-next"
                        onClick={() => onPageChange(Math.min(total, page + 1))}
                        disabled={page >= total}
                        style={pagerBtnStyle(page >= total)}
                    >
                        Next
                    </button>
                </div>

                {status.isLoading ? (
                    <span data-testid="kb-review-page-status-loading" style={{ fontSize: 11.5, color: 'var(--fg-3)' }}>
                        Loading…
                    </span>
                ) : status.isError || !status.data ? (
                    <span
                        data-testid="kb-review-page-status-error"
                        style={{ fontSize: 11.5, color: 'var(--danger-fg, #b91c1c)' }}
                    >
                        Could not load page status.
                    </span>
                ) : (
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <StatusPill status={status.data.status} />
                        {status.data.reviewed_by ? (
                            <span data-testid="kb-review-page-reviewed-by" style={{ fontSize: 11, color: 'var(--fg-3)' }}>
                                by {status.data.reviewed_by}
                            </span>
                        ) : null}
                        <button
                            type="button"
                            data-testid="kb-review-page-toggle"
                            onClick={handleToggle}
                            disabled={toggleMut.isPending}
                            style={secondaryBtnStyle(toggleMut.isPending)}
                        >
                            {toggleMut.isPending
                                ? 'Working…'
                                : status.data.status === 'reviewed'
                                  ? 'Mark unreviewed'
                                  : 'Mark reviewed'}
                        </button>
                    </div>
                )}
            </div>
        </section>
    );
}

function StatusPill({ status }: { status: 'reviewed' | 'unreviewed' }) {
    const reviewed = status === 'reviewed';
    return (
        <span
            data-testid="kb-review-page-status"
            data-status={status}
            style={{
                padding: '2px 8px',
                borderRadius: 999,
                fontSize: 10.5,
                fontFamily: 'var(--font-mono)',
                textTransform: 'uppercase',
                letterSpacing: '0.04em',
                border: '1px solid ' + (reviewed ? 'var(--accent)' : 'var(--hairline)'),
                background: reviewed ? 'var(--grad-accent-soft)' : 'var(--bg-1)',
                color: reviewed ? 'var(--accent-fg)' : 'var(--fg-3)',
            }}
        >
            {status}
        </span>
    );
}

function CorrectionsSection({ documentId }: { documentId: number }) {
    const [offset, setOffset] = useState(0);

    // Same reasoning as PageReviewSection: a stale offset from the
    // previously selected document must not leak into the new
    // document's corrections query.
    useEffect(() => {
        setOffset(0);
    }, [documentId]);

    const query = useKbCorrections(documentId, CORRECTIONS_PAGE_SIZE, offset);
    const approveMut = useApproveCorrection(documentId);
    const rejectMut = useRejectCorrection(documentId);
    const toast = useToast();

    function handleApprove(id: number) {
        approveMut.mutate(id, {
            onSuccess: (result) => {
                if (result.applied) {
                    toast.success('Correction applied.', 'toast-success');
                } else if (result.reason === 'already_consumed') {
                    toast.info('Already resolved by another reviewer.', 'toast-info');
                } else {
                    toast.error(`Not applied${result.reason ? `: ${result.reason}` : '.'}`, 'toast-error');
                }
            },
            onError: () => toast.error('Approve request failed.', 'toast-error'),
        });
    }

    function handleReject(id: number) {
        rejectMut.mutate(id, {
            onSuccess: (result) => {
                if (result.rejected) {
                    toast.success('Correction rejected.', 'toast-success');
                } else if (result.reason === 'already_consumed') {
                    toast.info('Already resolved by another reviewer.', 'toast-info');
                }
            },
            onError: () => toast.error('Reject request failed.', 'toast-error'),
        });
    }

    if (query.isLoading) {
        return (
            <section
                data-testid="kb-review-corrections-loading"
                data-state="loading"
                aria-busy="true"
                style={{ color: 'var(--fg-3)', fontSize: 12.5 }}
            >
                Loading correction candidates…
            </section>
        );
    }

    if (query.isError || !query.data) {
        return (
            <section
                data-testid="kb-review-corrections-error"
                data-state="error"
                aria-busy="false"
                style={{ color: 'var(--danger-fg, #b91c1c)', fontSize: 12.5 }}
            >
                Could not load correction candidates.
            </section>
        );
    }

    const { data, meta } = query.data;

    return (
        <section
            data-testid="kb-review-corrections"
            data-state={data.length === 0 ? 'empty' : 'ready'}
            aria-busy="false"
            style={{ display: 'flex', flexDirection: 'column', gap: 8 }}
        >
            <h4 style={{ margin: 0, fontSize: 13, color: 'var(--fg-1)' }}>Pending correction candidates</h4>
            {data.length === 0 ? (
                <div data-testid="kb-review-corrections-empty" style={{ color: 'var(--fg-3)', fontSize: 12.5 }}>
                    No pending correction candidates for this document.
                </div>
            ) : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                    {data.map((candidate) => (
                        <CorrectionRow
                            key={candidate.id}
                            candidate={candidate}
                            isBusy={approveMut.isPending || rejectMut.isPending}
                            onApprove={() => handleApprove(candidate.id)}
                            onReject={() => handleReject(candidate.id)}
                        />
                    ))}
                </div>
            )}
            {data.length > 0 || offset > 0 ? (
                <div
                    data-testid="kb-review-corrections-pager"
                    style={{ display: 'flex', gap: 6, alignItems: 'center', fontSize: 11.5, color: 'var(--fg-3)' }}
                >
                    <button
                        type="button"
                        data-testid="kb-review-corrections-prev"
                        onClick={() => setOffset((o) => Math.max(0, o - (meta.limit || CORRECTIONS_PAGE_SIZE)))}
                        disabled={offset <= 0}
                        style={pagerBtnStyle(offset <= 0)}
                    >
                        Prev
                    </button>
                    <button
                        type="button"
                        data-testid="kb-review-corrections-next"
                        onClick={() => setOffset((o) => o + (meta.limit || CORRECTIONS_PAGE_SIZE))}
                        disabled={!meta.has_more}
                        style={pagerBtnStyle(!meta.has_more)}
                    >
                        Next
                    </button>
                </div>
            ) : null}
        </section>
    );
}

function CorrectionRow({
    candidate,
    isBusy,
    onApprove,
    onReject,
}: {
    candidate: KbCorrectionCandidate;
    isBusy: boolean;
    onApprove: () => void;
    onReject: () => void;
}) {
    return (
        <div
            data-testid={`kb-review-correction-${candidate.id}`}
            style={{
                padding: 10,
                border: '1px solid var(--hairline)',
                borderRadius: 8,
                background: 'var(--bg-0)',
                display: 'flex',
                flexDirection: 'column',
                gap: 6,
            }}
        >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: 8 }}>
                <span style={{ fontSize: 11, color: 'var(--fg-3)', fontFamily: 'var(--font-mono)' }}>
                    page {candidate.page_number} · {candidate.proposed_by}
                </span>
                <span style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>{candidate.created_at ?? '—'}</span>
            </div>
            <div style={{ display: 'flex', gap: 8, alignItems: 'baseline', fontSize: 12.5 }}>
                <span
                    style={{
                        textDecoration: 'line-through',
                        color: 'var(--danger-fg, #b91c1c)',
                        fontFamily: 'var(--font-mono)',
                    }}
                >
                    {candidate.old_text}
                </span>
                <span style={{ color: 'var(--fg-3)' }}>→</span>
                <span style={{ color: 'var(--accent-fg)', fontFamily: 'var(--font-mono)' }}>
                    {candidate.new_text}
                </span>
            </div>
            {candidate.rationale ? (
                <div style={{ fontSize: 11.5, color: 'var(--fg-2)' }}>{candidate.rationale}</div>
            ) : null}
            <div style={{ display: 'flex', gap: 8 }}>
                <button
                    type="button"
                    data-testid={`kb-review-correction-${candidate.id}-approve`}
                    onClick={onApprove}
                    disabled={isBusy}
                    style={primaryBtnStyle(isBusy)}
                >
                    Approve
                </button>
                <button
                    type="button"
                    data-testid={`kb-review-correction-${candidate.id}-reject`}
                    onClick={onReject}
                    disabled={isBusy}
                    style={secondaryBtnStyle(isBusy)}
                >
                    Reject
                </button>
            </div>
        </div>
    );
}

function primaryBtnStyle(disabled: boolean): React.CSSProperties {
    return {
        padding: '5px 10px',
        fontSize: 12,
        border: '1px solid var(--accent)',
        background: 'var(--grad-accent-soft)',
        color: 'var(--accent-fg)',
        borderRadius: 6,
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.6 : 1,
    };
}

function secondaryBtnStyle(disabled: boolean): React.CSSProperties {
    return {
        padding: '5px 10px',
        fontSize: 12,
        border: '1px solid var(--hairline)',
        background: 'var(--bg-0)',
        color: 'var(--fg-1)',
        borderRadius: 6,
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.6 : 1,
    };
}

function pagerBtnStyle(disabled: boolean): React.CSSProperties {
    return {
        padding: '4px 10px',
        fontSize: 11.5,
        border: '1px solid var(--hairline)',
        background: 'var(--bg-0)',
        color: 'var(--fg-1)',
        borderRadius: 6,
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.5 : 1,
    };
}
