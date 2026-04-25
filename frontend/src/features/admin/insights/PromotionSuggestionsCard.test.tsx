import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { PromotionSuggestionsCard } from './PromotionSuggestionsCard';

describe('PromotionSuggestionsCard', () => {
    // Copilot #6 fix: the original test replaced `window.location` via
    // Object.defineProperty but never restored it in afterEach, which
    // leaked the mock into every subsequent Vitest suite sharing the
    // same jsdom environment (flakiness in unrelated tests that read
    // `location.href` etc). Snapshot the full descriptor up-front and
    // restore it after every test; restore the whole suite to the
    // original `location` when the describe block ends.
    const originalLocationDescriptor = Object.getOwnPropertyDescriptor(
        window,
        'location',
    );

    beforeEach(() => {
        // jsdom doesn't allow reassigning `location.assign` directly;
        // spy via Object.defineProperty so the test can assert the
        // navigation target without actually navigating.
        Object.defineProperty(window, 'location', {
            configurable: true,
            writable: true,
            value: { assign: vi.fn() },
        });
    });

    afterEach(() => {
        if (originalLocationDescriptor) {
            Object.defineProperty(window, 'location', originalLocationDescriptor);
        }
    });

    it('renders the empty state when no items', () => {
        render(<PromotionSuggestionsCard items={[]} />);
        expect(screen.getByTestId('insight-card-promotions')).toHaveAttribute(
            'data-state',
            'empty',
        );
        expect(screen.getByTestId('insight-card-promotions-empty')).toBeInTheDocument();
    });

    it('renders one row per suggestion with the ready state', () => {
        render(
            <PromotionSuggestionsCard
                items={[
                    {
                        document_id: 42,
                        project_key: 'hr-portal',
                        slug: 'hot-doc',
                        title: 'Hot Doc',
                        reason: 'cited 10x',
                        score: 10,
                    },
                ]}
            />,
        );
        expect(screen.getByTestId('insight-card-promotions')).toHaveAttribute(
            'data-state',
            'ready',
        );
        expect(screen.getByTestId('promotion-row-42')).toBeInTheDocument();
        expect(screen.getByText('Hot Doc')).toBeInTheDocument();
        expect(screen.getByText(/10 citations/)).toBeInTheDocument();
    });

    it('clicking the Promote action pivots to the KB document page', async () => {
        render(
            <PromotionSuggestionsCard
                items={[
                    {
                        document_id: 7,
                        project_key: 'engineering',
                        slug: 'x',
                        title: 'X',
                        reason: '',
                        score: 3,
                    },
                ]}
            />,
        );
        await userEvent.click(screen.getByTestId('promotions-action-promote-7'));
        expect(window.location.assign).toHaveBeenCalledWith(
            '/app/admin/kb?doc=7&tab=meta&promote=1',
        );
    });

    it('caps the visible list at 10 rows even if more items are passed', () => {
        const many = Array.from({ length: 15 }).map((_, i) => ({
            document_id: i + 1,
            project_key: 'p',
            slug: `s-${i}`,
            title: `T ${i}`,
            reason: '',
            score: 10 - i,
        }));
        render(<PromotionSuggestionsCard items={many} />);
        // Row 11 should not be rendered.
        expect(screen.queryByTestId('promotion-row-11')).not.toBeInTheDocument();
        expect(screen.getByTestId('promotion-row-10')).toBeInTheDocument();
    });
});
