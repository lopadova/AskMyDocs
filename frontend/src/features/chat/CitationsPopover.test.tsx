import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
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
});
