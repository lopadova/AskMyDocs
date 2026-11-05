import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { SearchStatsBadge } from './SearchStatsBadge';

describe('SearchStatsBadge', () => {
    it('renders nothing when stats is null (legacy / non-agent message)', () => {
        const { container } = render(<SearchStatsBadge stats={null} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders nothing when stats is undefined', () => {
        const { container } = render(<SearchStatsBadge stats={undefined} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders nothing when kb_searches is not a finite number (poisoned input safety guard)', () => {
        const { container } = render(<SearchStatsBadge stats={{ kb_searches: Number.NaN, tool_calls: 0 }} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders only the search count when there were no tool calls', () => {
        render(<SearchStatsBadge stats={{ kb_searches: 1, tool_calls: 0 }} />);
        const badge = screen.getByTestId('search-stats-badge');
        expect(badge).toHaveTextContent('1 search');
        expect(badge).not.toHaveTextContent('tool call');
    });

    it('pluralizes "searches" and "tool calls" correctly', () => {
        render(<SearchStatsBadge stats={{ kb_searches: 3, tool_calls: 2 }} />);
        const badge = screen.getByTestId('search-stats-badge');
        expect(badge).toHaveTextContent('3 searches · 2 tool calls');
    });

    it('uses the singular form for exactly one of each', () => {
        render(<SearchStatsBadge stats={{ kb_searches: 1, tool_calls: 1 }} />);
        expect(screen.getByTestId('search-stats-badge')).toHaveTextContent('1 search · 1 tool call');
    });

    it('exposes a descriptive aria-label for assistive tech', () => {
        render(<SearchStatsBadge stats={{ kb_searches: 3, tool_calls: 2 }} />);
        expect(screen.getByTestId('search-stats-badge')).toHaveAttribute(
            'aria-label',
            'Investigation: 3 searches · 2 tool calls',
        );
    });

    it('exposes role="status" so assistive tech can announce it', () => {
        render(<SearchStatsBadge stats={{ kb_searches: 1, tool_calls: 0 }} />);
        expect(screen.getByTestId('search-stats-badge')).toHaveAttribute('role', 'status');
    });
});
