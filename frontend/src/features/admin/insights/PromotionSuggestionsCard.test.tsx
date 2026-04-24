import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { PromotionSuggestionsCard } from './PromotionSuggestionsCard';

describe('PromotionSuggestionsCard', () => {
    beforeEach(() => {
        // jsdom doesn't allow reassigning `location.assign` directly;
        // spy via Object.defineProperty so the test can assert the
        // navigation target without actually navigating.
        Object.defineProperty(window, 'location', {
            writable: true,
            value: { assign: vi.fn() },
        });
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
