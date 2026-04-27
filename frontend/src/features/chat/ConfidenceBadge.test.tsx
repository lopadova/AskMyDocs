import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ConfidenceBadge } from './ConfidenceBadge';

describe('ConfidenceBadge', () => {
    it('renders nothing when both confidence and refusalReason are absent', () => {
        // Legacy rows (pre-v3.0) carry no grounding signal. The badge
        // must collapse to nothing — never fabricate a tier.
        const { container } = render(<ConfidenceBadge confidence={null} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders nothing when confidence is undefined', () => {
        const { container } = render(<ConfidenceBadge confidence={undefined} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders nothing when confidence is NaN (poisoned input safety guard)', () => {
        const { container } = render(<ConfidenceBadge confidence={Number.NaN} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders high tier for scores ≥ 80', () => {
        render(<ConfidenceBadge confidence={87} />);
        const badge = screen.getByTestId('confidence-badge');
        expect(badge).toHaveAttribute('data-state', 'high');
        expect(badge).toHaveTextContent('87/100');
        expect(badge).toHaveAttribute('aria-label', 'Confidence 87 of 100, high');
    });

    it('renders moderate tier for scores in [50, 80)', () => {
        render(<ConfidenceBadge confidence={65} />);
        expect(screen.getByTestId('confidence-badge')).toHaveAttribute('data-state', 'moderate');
    });

    it('renders low tier for scores < 50', () => {
        render(<ConfidenceBadge confidence={42} />);
        expect(screen.getByTestId('confidence-badge')).toHaveAttribute('data-state', 'low');
    });

    it('uses ≥ comparison at the high boundary (80 → high, 79 → moderate)', () => {
        // Strict-monotonic boundary check (R16 — the test must pin the
        // ACTUAL cutoff, not the spirit of it). 80 must round up to
        // high, 79 must stay moderate.
        const { rerender } = render(<ConfidenceBadge confidence={80} />);
        expect(screen.getByTestId('confidence-badge')).toHaveAttribute('data-state', 'high');
        rerender(<ConfidenceBadge confidence={79} />);
        expect(screen.getByTestId('confidence-badge')).toHaveAttribute('data-state', 'moderate');
    });

    it('uses ≥ comparison at the moderate boundary (50 → moderate, 49 → low)', () => {
        const { rerender } = render(<ConfidenceBadge confidence={50} />);
        expect(screen.getByTestId('confidence-badge')).toHaveAttribute('data-state', 'moderate');
        rerender(<ConfidenceBadge confidence={49} />);
        expect(screen.getByTestId('confidence-badge')).toHaveAttribute('data-state', 'low');
    });

    it('rounds the displayed score to the nearest integer', () => {
        // BE is supposed to clamp+round to int already (T3.2's
        // ConfidenceCalculator). But if a future caller passes a float
        // (or a JSON deserialiser produces 87.0), the badge should
        // still render cleanly.
        render(<ConfidenceBadge confidence={87.6} />);
        expect(screen.getByTestId('confidence-badge')).toHaveTextContent('88/100');
    });

    it('forces refused state when refusalReason is set, regardless of score', () => {
        // T3.3/T3.4 contract: refusal payloads carry confidence=0 + a
        // refusal_reason tag. The badge must show "refused" not "low" —
        // they're semantically different (low confidence answer was
        // delivered; refusal means no answer was generated).
        render(<ConfidenceBadge confidence={0} refusalReason="no_relevant_context" />);
        const badge = screen.getByTestId('confidence-badge');
        expect(badge).toHaveAttribute('data-state', 'refused');
        expect(badge).toHaveTextContent('No grounded answer');
        expect(badge).toHaveAttribute(
            'aria-label',
            'Answer refused — no grounded context available',
        );
    });

    it('refusal beats numeric score even on improbable inputs (92 + refused)', () => {
        // Defense-in-depth: if a future refusal carries a non-zero
        // score, the FE still classifies it as refused — tier is
        // determined by refusal_reason FIRST, score SECOND.
        render(<ConfidenceBadge confidence={92} refusalReason="llm_self_refusal" />);
        expect(screen.getByTestId('confidence-badge')).toHaveAttribute('data-state', 'refused');
    });

    it('exposes role="status" so assistive tech announces tier changes', () => {
        // R15: announce on focus / live region. role=status is polite
        // (announces when content changes but doesn't interrupt). For a
        // chat UI where assistant turns stream in, polite is correct;
        // role=alert would be too aggressive.
        render(<ConfidenceBadge confidence={75} />);
        expect(screen.getByTestId('confidence-badge')).toHaveAttribute('role', 'status');
    });
});
