import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ThinkingTrace } from './ThinkingTrace';

describe('ThinkingTrace', () => {
    it('renders nothing when steps list is empty', () => {
        const { container } = render(<ThinkingTrace steps={[]} />);
        expect(container.firstChild).toBeNull();
    });

    it('toggles the panel open on click and exposes aria-expanded', () => {
        render(<ThinkingTrace steps={['one', 'two']} />);
        const toggle = screen.getByTestId('chat-thinking-trace-toggle');
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        fireEvent.click(toggle);
        expect(toggle).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByTestId('chat-thinking-trace')).toHaveAttribute('data-state', 'open');
    });
});
