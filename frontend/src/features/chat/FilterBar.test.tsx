import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { FilterBar } from './FilterBar';

describe('FilterBar', () => {
    it('renders the + Filter trigger even when no filters are active', () => {
        // The bar is unconditionally visible — empty state still renders
        // the trigger so the user knows the UI exists.
        render(<FilterBar filters={{}} onChange={() => {}} />);
        expect(screen.getByTestId('chat-filter-bar-add')).toBeVisible();
        // No count badge when zero filters.
        expect(screen.queryByTestId('chat-filter-bar-count')).not.toBeInTheDocument();
    });

    it('renders one chip per active filter dimension/value pair', () => {
        render(
            <FilterBar
                filters={{
                    project_keys: ['hr-portal'],
                    tag_slugs: ['policy'],
                    source_types: ['pdf'],
                }}
                onChange={() => {}}
            />,
        );
        expect(screen.getByTestId('filter-chip-project-hr-portal')).toBeVisible();
        expect(screen.getByTestId('filter-chip-tag-policy')).toBeVisible();
        expect(screen.getByTestId('filter-chip-source-pdf')).toBeVisible();
    });

    it('renders the count badge with the number of dimensions active', () => {
        // Counts dimensions, not values. 3 different dimensions selected
        // → count = 3 even though source_types has 2 values.
        render(
            <FilterBar
                filters={{
                    project_keys: ['hr', 'engineering'],  // 1 dimension
                    source_types: ['pdf', 'docx'],         // 1 dimension
                    languages: ['en'],                     // 1 dimension
                }}
                onChange={() => {}}
            />,
        );
        const badge = screen.getByTestId('chat-filter-bar-count');
        expect(badge).toHaveTextContent('3');
        expect(badge).toHaveAttribute('aria-label', expect.stringMatching(/3 filters selected/i));
    });

    it('clicking × on a chip emits onChange with that value removed', async () => {
        const onChange = vi.fn();
        render(
            <FilterBar
                filters={{ tag_slugs: ['policy', 'security'] }}
                onChange={onChange}
            />,
        );
        await userEvent.click(screen.getByTestId('filter-chip-tag-policy-remove'));
        expect(onChange).toHaveBeenCalledWith({ tag_slugs: ['security'] });
    });

    it('clicking × on the last value of a dimension empties that dimension', async () => {
        const onChange = vi.fn();
        render(
            <FilterBar filters={{ tag_slugs: ['policy'] }} onChange={onChange} />,
        );
        await userEvent.click(screen.getByTestId('filter-chip-tag-policy-remove'));
        expect(onChange).toHaveBeenCalledWith({ tag_slugs: [] });
    });

    it('Clear all button removes every filter at once', async () => {
        const onChange = vi.fn();
        render(
            <FilterBar
                filters={{
                    project_keys: ['hr'],
                    tag_slugs: ['policy'],
                    source_types: ['pdf'],
                }}
                onChange={onChange}
            />,
        );
        await userEvent.click(screen.getByTestId('chat-filter-bar-clear'));
        expect(onChange).toHaveBeenCalledWith({});
    });

    it('does NOT render Clear all when no filters are active', () => {
        render(<FilterBar filters={{}} onChange={() => {}} />);
        expect(screen.queryByTestId('chat-filter-bar-clear')).not.toBeInTheDocument();
    });

    it('clicking + Filter opens the popover (aria-expanded toggles)', async () => {
        render(<FilterBar filters={{}} onChange={() => {}} availableProjects={['hr']} />);
        const trigger = screen.getByTestId('chat-filter-bar-add');
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        await userEvent.click(trigger);
        expect(trigger).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByTestId('filter-popover')).toBeVisible();
    });

    it('renders date chips with formatted from/to labels', () => {
        render(
            <FilterBar
                filters={{ date_from: '2026-01-01', date_to: '2026-12-31' }}
                onChange={() => {}}
            />,
        );
        expect(screen.getByTestId('filter-chip-date-from')).toHaveTextContent('from 2026-01-01');
        expect(screen.getByTestId('filter-chip-date-to')).toHaveTextContent('to 2026-12-31');
    });

    it('removing a date chip clears only that endpoint', async () => {
        const onChange = vi.fn();
        render(
            <FilterBar
                filters={{ date_from: '2026-01-01', date_to: '2026-12-31' }}
                onChange={onChange}
            />,
        );
        await userEvent.click(screen.getByTestId('filter-chip-date-from-remove'));
        expect(onChange).toHaveBeenCalledWith(
            expect.objectContaining({ date_from: null, date_to: '2026-12-31' }),
        );
    });

    it('uses tag label from availableTags when rendering the chip', () => {
        // Tag chips show display label, not the slug, when the parent
        // supplies a label map. Falls back to slug when not found.
        render(
            <FilterBar
                filters={{ tag_slugs: ['hr-policy', 'orphan-slug'] }}
                onChange={() => {}}
                availableTags={[{ slug: 'hr-policy', label: 'HR Policy', color: '#0a0' }]}
            />,
        );
        // Known tag uses the friendly label.
        expect(screen.getByTestId('filter-chip-tag-hr-policy')).toHaveTextContent('HR Policy');
        // Unknown tag falls back to the slug.
        expect(screen.getByTestId('filter-chip-tag-orphan-slug')).toHaveTextContent('orphan-slug');
    });

    it('uses doc title from docLabels for doc-id chips, falls back to #id', () => {
        render(
            <FilterBar
                filters={{ doc_ids: [42, 99] }}
                onChange={() => {}}
                docLabels={{ 42: 'HR Policy v2' }}
            />,
        );
        expect(screen.getByTestId('filter-chip-doc-42')).toHaveTextContent('HR Policy v2');
        expect(screen.getByTestId('filter-chip-doc-99')).toHaveTextContent('#99');
    });
});
