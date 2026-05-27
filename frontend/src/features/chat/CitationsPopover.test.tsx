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
    it('renders a chip per citation with the right data-origin', () => {
        render(<CitationsPopover citations={citations} />);
        expect(screen.getByTestId('chat-citations')).toBeInTheDocument();
        expect(screen.getByTestId('chat-citation-0')).toHaveAttribute('data-origin', 'primary');
        expect(screen.getByTestId('chat-citation-1')).toHaveAttribute('data-origin', 'rejected');
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
