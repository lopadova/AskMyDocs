import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { FilterChip } from './FilterChip';

describe('FilterChip', () => {
    it('renders the dimension label + value visibly', () => {
        render(<FilterChip dimension="project" value="hr-portal" onRemove={() => {}} />);
        const chip = screen.getByTestId('filter-chip-project-hr-portal');
        expect(chip).toHaveTextContent(/project:/i);
        expect(chip).toHaveTextContent('hr-portal');
    });

    it('uses label prop for display when value differs from label', () => {
        // Document filters store doc_id as value but display the title.
        render(<FilterChip dimension="doc" value="42" label="HR Policy v2" onRemove={() => {}} />);
        const chip = screen.getByTestId('filter-chip-doc-42');
        expect(chip).toHaveTextContent('HR Policy v2');
        expect(chip).not.toHaveTextContent('value 42');
    });

    it('falls back to value as the visible label when label is omitted', () => {
        render(<FilterChip dimension="tag" value="policy" onRemove={() => {}} />);
        expect(screen.getByTestId('filter-chip-tag-policy')).toHaveTextContent('policy');
    });

    it('exposes data-dimension and data-value for E2E selection', () => {
        // Stable selectors regardless of label changes.
        render(<FilterChip dimension="source" value="pdf" onRemove={() => {}} />);
        const chip = screen.getByTestId('filter-chip-source-pdf');
        expect(chip).toHaveAttribute('data-dimension', 'source');
        expect(chip).toHaveAttribute('data-value', 'pdf');
    });

    it('calls onRemove when × button is clicked', async () => {
        const onRemove = vi.fn();
        render(<FilterChip dimension="tag" value="policy" onRemove={onRemove} />);
        await userEvent.click(screen.getByTestId('filter-chip-tag-policy-remove'));
        expect(onRemove).toHaveBeenCalledTimes(1);
    });

    it('does NOT call onRemove when chip body is clicked (only the × button)', async () => {
        // Stray clicks on the chip area must not delete a filter.
        const onRemove = vi.fn();
        render(<FilterChip dimension="tag" value="policy" onRemove={onRemove} />);
        await userEvent.click(screen.getByTestId('filter-chip-tag-policy'));
        expect(onRemove).not.toHaveBeenCalled();
    });

    it('remove button has accessible aria-label including dimension and value', () => {
        // R15: button must announce its purpose, not just "×".
        render(<FilterChip dimension="project" value="hr" onRemove={() => {}} />);
        const removeButton = screen.getByTestId('filter-chip-project-hr-remove');
        expect(removeButton).toHaveAttribute('aria-label', expect.stringMatching(/project filter: hr/i));
        expect(removeButton).toHaveAttribute('aria-label', expect.stringMatching(/remove/i));
    });

    it('remove button is keyboard-reachable (Tab + Enter)', async () => {
        const onRemove = vi.fn();
        render(<FilterChip dimension="tag" value="policy" onRemove={onRemove} />);
        // userEvent.tab moves focus into the document, then to the button.
        await userEvent.tab();
        const removeButton = screen.getByTestId('filter-chip-tag-policy-remove');
        expect(removeButton).toHaveFocus();
        await userEvent.keyboard('{Enter}');
        expect(onRemove).toHaveBeenCalledTimes(1);
    });
});
