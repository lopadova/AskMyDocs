import type { OcrEstimate } from './kb-upload.api';

/*
 * v8.36 / ADR 0029 §8 — the OCR line shown on the upload modal BEFORE commit.
 *
 * Stateless (props in, markup out) so the modal owns the fetch and this
 * component is unit-testable in every state (R16). Both flag states render
 * something explicit (R43): OFF says OCR is disabled and that images will be
 * refused; ON says how many pages would be OCR'd, with which driver, and the
 * estimated cost in the FinOps base currency. `aria-live` announces the
 * number once it arrives (R15).
 */

export type OcrEstimateState = 'loading' | 'ready' | 'error';

export interface OcrEstimateLineProps {
    state: OcrEstimateState;
    estimate: OcrEstimate | undefined;
    errorMessage?: string | null;
}

export function formatCost(amount: number, currency: string): string {
    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency,
            minimumFractionDigits: 2,
            maximumFractionDigits: 4,
        }).format(amount);
    } catch {
        // An unknown ISO code (misconfigured ai-finops.currency.base) must not
        // crash the modal — fall back to a plain number + code.
        return `${amount.toFixed(4)} ${currency}`;
    }
}

export function OcrEstimateLine({ state, estimate, errorMessage }: OcrEstimateLineProps) {
    const base: React.CSSProperties = { margin: 0, fontSize: 11, color: 'var(--fg-2)' };

    if (state === 'loading') {
        return (
            <p data-testid="kb-upload-ocr-estimate" data-state="loading" role="status" aria-busy="true" style={base}>
                Checking whether any file needs OCR…
            </p>
        );
    }

    if (state === 'error' || !estimate) {
        return (
            <p data-testid="kb-upload-ocr-estimate" data-state="error" role="alert" style={{ ...base, color: 'var(--err)' }}>
                OCR estimate unavailable{errorMessage ? `: ${errorMessage}` : ''}. You can still commit.
            </p>
        );
    }

    if (!estimate.enabled) {
        const imageCount = estimate.items.filter((i) => i.reason === 'ocr_disabled').length;
        return (
            <p data-testid="kb-upload-ocr-estimate" data-state="ready" data-ocr-enabled="false" role="status" aria-live="polite" style={base}>
                OCR is disabled on this server (<code>KB_OCR_ENABLED=false</code>): scanned PDFs are ingested as
                empty documents and images are refused
                {imageCount > 0 ? ` — ${imageCount} staged file${imageCount === 1 ? '' : 's'} would need it` : ''}.
            </p>
        );
    }

    const pending = estimate.items.filter((i) => i.would_ocr);
    const overLimit = estimate.items.filter((i) => i.reason === 'too_many_pages' || i.reason === 'too_many_bytes');

    // R14 — never promise a run the server will refuse: the driver cannot
    // run here (missing binary, remote driver without KB_OCR_ALLOW_REMOTE).
    if (pending.length > 0 && !estimate.driver_available) {
        return (
            <p data-testid="kb-upload-ocr-estimate" data-state="ready" data-ocr-enabled="true" data-ocr-driver-available="false" role="alert" style={{ ...base, color: 'var(--err)' }}>
                {pending.length} file{pending.length === 1 ? '' : 's'} would need OCR, but the <code>{estimate.driver}</code> driver cannot run on this server
                {estimate.driver_error ? ` (${estimate.driver_error})` : ''}. Committing will fail those files.
            </p>
        );
    }

    if (pending.length === 0 && overLimit.length > 0) {
        return (
            <p data-testid="kb-upload-ocr-estimate" data-state="ready" data-ocr-enabled="true" data-ocr-pages="0" role="alert" style={{ ...base, color: 'var(--err)' }}>
                {overLimit.length} file{overLimit.length === 1 ? '' : 's'} exceed the OCR limits (KB_OCR_MAX_PAGES / KB_OCR_MAX_BYTES) and will be refused.
            </p>
        );
    }

    if (pending.length === 0) {
        return (
            <p data-testid="kb-upload-ocr-estimate" data-state="ready" data-ocr-enabled="true" data-ocr-pages="0" role="status" aria-live="polite" style={base}>
                No file needs OCR — every PDF has a text layer.
            </p>
        );
    }

    return (
        <p
            data-testid="kb-upload-ocr-estimate"
            data-state="ready"
            data-ocr-enabled="true"
            data-ocr-pages={String(estimate.total_pages)}
            role="status"
            aria-live="polite"
            style={base}
        >
            OCR will run on <strong>{pending.length}</strong> file{pending.length === 1 ? '' : 's'} (
            {pending.some((i) => !i.pages_exact) ? '≥ ' : ''}{estimate.total_pages} page{estimate.total_pages === 1 ? '' : 's'}) with the <code>{estimate.driver}</code>{' '}
            driver — estimated <strong data-testid="kb-upload-ocr-estimate-cost">{formatCost(estimate.total_cost, estimate.currency)}</strong>{' '}
            at {formatCost(estimate.rate_per_page, estimate.currency)}/page, metered by FinOps.
            {overLimit.length > 0 ? ` ${overLimit.length} file${overLimit.length === 1 ? '' : 's'} exceed the OCR limits and will be refused.` : ''}
        </p>
    );
}
