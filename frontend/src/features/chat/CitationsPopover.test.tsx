import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { CitationsPopover } from './CitationsPopover';
import type { MessageCitation } from './chat.api';

const citations: MessageCitation[] = [
    {
        document_id: 1,
        title: 'Remote Work Policy',
        source_path: 'policies/remote-work-policy.md',
        headings: ['Intro', 'Eligibility'],
        chunks_used: 2,
        origin: 'primary',
    },
    {
        document_id: 2,
        title: 'Rejected: Unlimited Remote',
        source_path: 'rejected/unlimited.md',
        headings: [],
        chunks_used: 1,
        origin: 'rejected',
    },
];

describe('CitationsPopover', () => {
    it('renders a chip per citation with the right data-origin + data-count', () => {
        render(<CitationsPopover citations={citations} />);
        expect(screen.getByTestId('chat-citations')).toHaveAttribute('data-count', '2');
        expect(screen.getByTestId('chat-citation-0')).toHaveAttribute('data-origin', 'primary');
        expect(screen.getByTestId('chat-citation-1')).toHaveAttribute('data-origin', 'rejected');
    });

    it('shows the filename in the chip, not the full source path', () => {
        render(<CitationsPopover citations={citations} />);
        const chip = screen.getByTestId('chat-citation-0');
        // Visible label is the basename...
        expect(chip).toHaveTextContent('remote-work-policy.md');
        // ...the full path is NOT in the chip text (it lives in the popover +
        // the native title attribute for hover/SR users).
        expect(chip).not.toHaveTextContent('policies/remote-work-policy.md');
        expect(chip).toHaveAttribute('title', 'policies/remote-work-policy.md');
    });

    it('opens the popover on hover with the full path, title and every heading', () => {
        render(<CitationsPopover citations={citations} />);
        // Popover is closed until interaction.
        expect(screen.queryByTestId('chat-citations-popover')).toBeNull();

        const chip = screen.getByTestId('chat-citation-0');
        fireEvent.mouseEnter(chip.parentElement as HTMLElement);

        const popover = screen.getByTestId('chat-citations-popover');
        expect(popover).toHaveAttribute('data-state', 'open');
        expect(popover).toHaveTextContent('policies/remote-work-policy.md');
        expect(popover).toHaveTextContent('Remote Work Policy');
        expect(popover).toHaveTextContent('Intro');
        expect(popover).toHaveTextContent('Eligibility');

        fireEvent.mouseLeave(chip.parentElement as HTMLElement);
        expect(screen.queryByTestId('chat-citations-popover')).toBeNull();
    });

    it('opens the popover on keyboard focus and closes on blur (R15)', () => {
        render(<CitationsPopover citations={citations} />);
        const chip = screen.getByTestId('chat-citation-0');
        expect(chip).not.toHaveAttribute('aria-describedby');

        // focusIn/focusOut dispatch the bubbling events React maps to
        // onFocus/onBlur — proving the popover is reachable without a mouse.
        fireEvent.focusIn(chip);
        expect(screen.getByTestId('chat-citations-popover')).toBeInTheDocument();
        expect(chip).toHaveAttribute('aria-describedby', 'chat-citation-popover-0');

        fireEvent.focusOut(chip);
        expect(screen.queryByTestId('chat-citations-popover')).toBeNull();
    });

    it('is not openable without an onOpenSource handler', () => {
        render(<CitationsPopover citations={citations} />);
        expect(screen.getByTestId('chat-citation-0')).toHaveAttribute('data-openable', 'false');
    });

    it('opens the source on click when a handler is wired and document_id is set', () => {
        const onOpenSource = vi.fn();
        render(<CitationsPopover citations={citations} onOpenSource={onOpenSource} />);

        const chip = screen.getByTestId('chat-citation-0');
        expect(chip).toHaveAttribute('data-openable', 'true');
        fireEvent.click(chip);

        expect(onOpenSource).toHaveBeenCalledTimes(1);
        expect(onOpenSource).toHaveBeenCalledWith(expect.objectContaining({ document_id: 1 }));
    });

    it('does not open a citation that has no document_id even with a handler', () => {
        const onOpenSource = vi.fn();
        const danglingCitation: MessageCitation[] = [
            { document_id: null, title: 'No doc', source_path: null, headings: [], origin: 'related' },
        ];
        render(<CitationsPopover citations={danglingCitation} onOpenSource={onOpenSource} />);

        const chip = screen.getByTestId('chat-citation-0');
        expect(chip).toHaveAttribute('data-openable', 'false');
        fireEvent.click(chip);
        expect(onOpenSource).not.toHaveBeenCalled();
    });
});
