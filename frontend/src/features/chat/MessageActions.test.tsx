import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { MessageActions } from './MessageActions';

describe('MessageActions', () => {
    it('always renders the copy button', () => {
        render(<MessageActions content="hi" />);
        expect(screen.getByTestId('chat-message-copy')).toBeInTheDocument();
    });

    it('hides regenerate when onRegenerate is not provided', () => {
        render(<MessageActions content="hi" />);
        expect(screen.queryByTestId('chat-message-regenerate')).toBeNull();
    });

    it('hides branch when onBranch is not provided', () => {
        render(<MessageActions content="hi" />);
        expect(screen.queryByTestId('chat-message-branch')).toBeNull();
    });

    it('hides edit when onEdit is not provided', () => {
        render(<MessageActions content="hi" />);
        expect(screen.queryByTestId('chat-message-edit')).toBeNull();
    });

    it('renders + fires the regenerate handler', () => {
        const onRegenerate = vi.fn();
        render(<MessageActions content="hi" onRegenerate={onRegenerate} />);
        fireEvent.click(screen.getByTestId('chat-message-regenerate'));
        expect(onRegenerate).toHaveBeenCalledOnce();
    });

    it('renders + fires the branch handler', () => {
        const onBranch = vi.fn();
        render(<MessageActions content="hi" onBranch={onBranch} />);
        fireEvent.click(screen.getByTestId('chat-message-branch'));
        expect(onBranch).toHaveBeenCalledOnce();
    });

    it('renders + fires the edit handler', () => {
        const onEdit = vi.fn();
        render(<MessageActions content="hi" onEdit={onEdit} />);
        fireEvent.click(screen.getByTestId('chat-message-edit'));
        expect(onEdit).toHaveBeenCalledOnce();
    });

    it('has aria-labels on every action button (R15)', () => {
        render(
            <MessageActions
                content="hi"
                onRegenerate={() => undefined}
                onBranch={() => undefined}
                onEdit={() => undefined}
            />,
        );
        expect(screen.getByTestId('chat-message-copy')).toHaveAttribute('aria-label', 'Copy message');
        expect(screen.getByTestId('chat-message-edit')).toHaveAttribute('aria-label', 'Edit message');
        expect(screen.getByTestId('chat-message-regenerate')).toHaveAttribute('aria-label', 'Regenerate answer');
        expect(screen.getByTestId('chat-message-branch')).toHaveAttribute('aria-label', 'Branch from this reply');
    });
});
