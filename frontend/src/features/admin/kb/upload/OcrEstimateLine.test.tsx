import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { OcrEstimateLine, formatCost } from './OcrEstimateLine';
import type { OcrEstimate } from './kb-upload.api';

/*
 * R16 — every state the modal can hand this component, including the OFF
 * flag state (R43), is driven and asserted by name.
 */
const on: OcrEstimate = {
    enabled: true,
    driver: 'fake',
    driver_available: true,
    driver_error: null,
    currency: 'USD',
    rate_per_page: 0.004,
    total_pages: 3,
    total_cost: 0.012,
    items: [
        { id: 'a', would_ocr: true, pages: 1, pages_exact: true, cost: 0.004, reason: 'image' },
        { id: 'b', would_ocr: true, pages: 2, pages_exact: true, cost: 0.008, reason: 'scanned_pdf' },
        { id: 'c', would_ocr: false, pages: 0, pages_exact: true, cost: 0, reason: 'text_layer_present' },
    ],
};

describe('OcrEstimateLine', () => {
    it('announces a busy status while loading', () => {
        render(<OcrEstimateLine state="loading" estimate={undefined} />);
        const el = screen.getByTestId('kb-upload-ocr-estimate');
        expect(el).toHaveAttribute('data-state', 'loading');
        expect(el).toHaveAttribute('aria-busy', 'true');
    });

    it('surfaces the error in the DOM and does not block commit (R14)', () => {
        render(<OcrEstimateLine state="error" estimate={undefined} errorMessage="HTTP 500" />);
        const el = screen.getByTestId('kb-upload-ocr-estimate');
        expect(el).toHaveAttribute('data-state', 'error');
        expect(el).toHaveAttribute('role', 'alert');
        expect(el.textContent).toContain('HTTP 500');
        expect(el.textContent).toContain('You can still commit');
    });

    it('OFF flag state — says OCR is disabled and counts the files that would need it (R43)', () => {
        const off: OcrEstimate = {
            ...on,
            enabled: false,
            total_pages: 0,
            total_cost: 0,
            items: [
                { id: 'a', would_ocr: false, pages: 0, pages_exact: true, cost: 0, reason: 'ocr_disabled' },
                { id: 'b', would_ocr: false, pages: 0, pages_exact: true, cost: 0, reason: 'ocr_disabled' },
                { id: 'c', would_ocr: false, pages: 0, pages_exact: true, cost: 0, reason: 'not_ocr_able' },
            ],
        };
        render(<OcrEstimateLine state="ready" estimate={off} />);
        const el = screen.getByTestId('kb-upload-ocr-estimate');
        expect(el).toHaveAttribute('data-ocr-enabled', 'false');
        expect(el.textContent).toContain('KB_OCR_ENABLED=false');
        expect(el.textContent).toContain('2 staged files would need it');
        expect(screen.queryByTestId('kb-upload-ocr-estimate-cost')).toBeNull();
    });

    it('ON flag state — reports files, pages, driver and cost', () => {
        render(<OcrEstimateLine state="ready" estimate={on} />);
        const el = screen.getByTestId('kb-upload-ocr-estimate');
        expect(el).toHaveAttribute('data-ocr-enabled', 'true');
        expect(el).toHaveAttribute('data-ocr-pages', '3');
        expect(el.textContent).toContain('2 files');
        expect(el.textContent).toContain('3 pages');
        expect(el.textContent).toContain('fake');
        expect(screen.getByTestId('kb-upload-ocr-estimate-cost').textContent).toContain('0.012');
    });

    it('ON flag state with nothing to OCR — says so explicitly instead of a zero cost', () => {
        const none: OcrEstimate = { ...on, total_pages: 0, total_cost: 0, items: [on.items[2]] };
        render(<OcrEstimateLine state="ready" estimate={none} />);
        const el = screen.getByTestId('kb-upload-ocr-estimate');
        expect(el).toHaveAttribute('data-ocr-pages', '0');
        expect(el.textContent).toContain('No file needs OCR');
    });

    it('ON but driver unavailable — warns instead of promising a run (R14)', () => {
        const unavailable: OcrEstimate = { ...on, driver: 'mistral-ocr', driver_available: false, driver_error: 'set KB_OCR_ALLOW_REMOTE=true' };
        render(<OcrEstimateLine state="ready" estimate={unavailable} />);
        const el = screen.getByTestId('kb-upload-ocr-estimate');
        expect(el).toHaveAttribute('data-ocr-driver-available', 'false');
        expect(el).toHaveAttribute('role', 'alert');
        expect(el.textContent).toContain('mistral-ocr');
        expect(el.textContent).toContain('KB_OCR_ALLOW_REMOTE');
        expect(screen.queryByTestId('kb-upload-ocr-estimate-cost')).toBeNull();
    });

    it('ON with a file over the limits — says it will be refused', () => {
        const over: OcrEstimate = {
            ...on,
            total_pages: 0,
            total_cost: 0,
            items: [{ id: 'x', would_ocr: false, pages: 900, pages_exact: true, cost: 0, reason: 'too_many_pages' }],
        };
        render(<OcrEstimateLine state="ready" estimate={over} />);
        const el = screen.getByTestId('kb-upload-ocr-estimate');
        expect(el).toHaveAttribute('role', 'alert');
        expect(el.textContent).toContain('exceed the OCR limits');
    });

    it('marks a floor page count with ≥ when the PDF could not be parsed', () => {
        const floor: OcrEstimate = { ...on, total_pages: 3, items: [{ id: 'f', would_ocr: true, pages: 3, pages_exact: false, cost: 0.012, reason: 'scanned_pdf' }] };
        render(<OcrEstimateLine state="ready" estimate={floor} />);
        expect(screen.getByTestId('kb-upload-ocr-estimate').textContent).toContain('≥ 3 pages');
    });

    it('formatCost falls back on an unknown currency code instead of throwing', () => {
        expect(formatCost(0.5, 'NOTACODE')).toBe('0.5000 NOTACODE');
        expect(formatCost(0.012, 'USD')).toContain('0.012');
    });
});
