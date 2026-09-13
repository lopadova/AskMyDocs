import type { CSSProperties } from 'react';

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
    const base: CSSProperties = { margin: 0, fontSize: 11, color: 'var(--fg-2)' };

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
    const overLimit = estimate.items.filter((i) => i.reason === 'too_many_pages' || i.reason === 'too_many_bytes' || i.reason === 'pages_uncountable' || i.reason === 'multi_frame_image');
    const uncountable = estimate.items.filter((i) => i.reason === 'pages_uncountable').length;
    const multiFrame = estimate.items.filter((i) => i.reason === 'multi_frame_image').length;
    const capped = overLimit.length - uncountable - multiFrame;
    const files = (k: number) => `${k} file${k === 1 ? '' : 's'}`;
    // One refusal sentence, reused by every branch that mentions refused
    // files, so a mixed batch names the real reason for each group (R14).
    // `pages_uncountable` is not a remote-only refusal: a local whole-file
    // driver (docling) refuses an unverified count too, so the sentence
    // names the configured driver, never a destination.
    const refusal = overLimit.length === 0
        ? ''
        : [
            capped > 0 ? `${files(capped)} exceed${capped === 1 ? 's' : ''} the OCR limits (KB_OCR_MAX_PAGES / KB_OCR_MAX_BYTES)` : '',
            uncountable > 0 ? `${files(uncountable)} ha${uncountable === 1 ? 's' : 've'} a page count that cannot be verified (the PDF could not be parsed), which the configured ${estimate.driver} driver refuses to run without` : '',
            multiFrame > 0 ? `${files(multiFrame)} ${multiFrame === 1 ? 'is a multi-frame TIFF' : 'are multi-frame TIFFs'} the ${estimate.driver} driver would transcribe one frame of (split into one image per page)` : '',
        ].filter(Boolean).join('; ') + ' — refused before any driver runs.';

    // R14 — never promise a run the server will refuse: the driver cannot
    // run here (missing binary, remote driver without KB_OCR_ALLOW_REMOTE).
    // Files the limits already refuse count too: their reason must not read
    // as if a disabled driver were about to receive them.
    const needing = pending.length + overLimit.length;
    if (needing > 0 && !estimate.driver_available) {
        return (
            <p data-testid="kb-upload-ocr-estimate" data-state="ready" data-ocr-enabled="true" data-ocr-driver-available="false" role="alert" style={{ ...base, color: 'var(--err)' }}>
                {files(needing)} would need OCR, but the <code>{estimate.driver}</code> driver cannot run on this server
                {estimate.driver_error ? ` (${estimate.driver_error})` : ''}. Committing will fail those files.
            </p>
        );
    }

    if (pending.length === 0 && overLimit.length > 0) {
        return (
            <p data-testid="kb-upload-ocr-estimate" data-state="ready" data-ocr-enabled="true" data-ocr-pages="0" role="alert" style={{ ...base, color: 'var(--err)' }}>
                {refusal}
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
            driver — {estimate.metering === 'sdk'
                ? <>metered per token by the provider (no page rate, so no estimate up front); FinOps records the real spend<span data-testid="kb-upload-ocr-estimate-metering" data-metering="sdk" /></>
                : <>estimated <strong data-testid="kb-upload-ocr-estimate-cost">{formatCost(estimate.total_cost, estimate.currency)}</strong>{' '}
                at {formatCost(estimate.rate_per_page, estimate.currency)}/page, metered by FinOps</>}.
            {overLimit.length > 0 ? ` ${refusal}` : ''}
        </p>
    );
}
