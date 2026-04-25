import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

const rawState: { data: { content: string; path: string; disk: string; mime: string; content_hash: string } | undefined; isLoading: boolean; isError: boolean } = {
    data: undefined,
    isLoading: false,
    isError: false,
};

vi.mock('./kb-document.api', () => ({
    useKbRaw: () => ({
        data: rawState.isError ? undefined : rawState.data,
        isLoading: rawState.isLoading,
        isError: rawState.isError,
    }),
}));

// The Markdown component itself is exercised in its own test file; we
// stub it here so the PreviewTab assertions focus on the frontmatter
// pill extraction + body hand-off.
vi.mock('../../../lib/markdown', () => ({
    Markdown: ({ source }: { source: string }) => (
        <div data-testid="markdown-stub">{source}</div>
    ),
}));

import { PreviewTab, extractFrontmatterPills } from './PreviewTab';

function wrap(ui: React.ReactElement) {
    const qc = new QueryClient({
        defaultOptions: { queries: { retry: false } },
    });
    return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

describe('extractFrontmatterPills', () => {
    it('returns empty pills and original body when no frontmatter', () => {
        const result = extractFrontmatterPills('# hello\n\nNo fence.');
        expect(result.pills).toEqual([]);
        expect(result.body).toBe('# hello\n\nNo fence.');
    });

    it('splits scalar keys into pills and returns the body after ---', () => {
        const src = `---\nid: dec-x\nstatus: accepted\ntype: decision\n---\n\n# Body here\n`;
        const { pills, body } = extractFrontmatterPills(src);
        expect(pills.map((p) => p.key)).toEqual(['id', 'status', 'type']);
        expect(pills.find((p) => p.key === 'status')?.value).toBe('accepted');
        expect(body.trim().startsWith('# Body here')).toBe(true);
    });

    it('skips list continuation lines and nested blocks', () => {
        const src = `---\nid: dec-x\ntags:\n  - a\n  - b\nstatus: accepted\n---\nBody\n`;
        const { pills } = extractFrontmatterPills(src);
        const keys = pills.map((p) => p.key);
        expect(keys).toContain('id');
        expect(keys).toContain('status');
        // Nested list items must NOT leak into pills as their own keys.
        expect(keys).not.toContain('- a');
        expect(keys).not.toContain('- b');
    });
});

describe('PreviewTab', () => {
    it('renders loading state while the raw endpoint is in flight', () => {
        rawState.isLoading = true;
        rawState.data = undefined;
        rawState.isError = false;
        wrap(<PreviewTab documentId={1} project="hr-portal" />);
        expect(screen.getByTestId('kb-preview-loading')).toBeInTheDocument();
        rawState.isLoading = false;
    });

    it('renders error state when the fetch fails', () => {
        rawState.isError = true;
        wrap(<PreviewTab documentId={1} project="hr-portal" />);
        expect(screen.getByTestId('kb-preview-error')).toBeInTheDocument();
        rawState.isError = false;
    });

    it('extracts frontmatter pills and renders body through Markdown stub', () => {
        rawState.data = {
            content: `---\nid: dec-x\nstatus: accepted\n---\n\n# Hello body\n`,
            path: 'kb/dec-x.md',
            disk: 'kb',
            mime: 'text/markdown',
            content_hash: 'xxx',
        };
        wrap(<PreviewTab documentId={1} project="hr-portal" />);
        const pills = screen.getByTestId('frontmatter-pills');
        expect(pills).toBeInTheDocument();
        expect(screen.getByTestId('frontmatter-pill-id')).toHaveTextContent('dec-x');
        expect(screen.getByTestId('frontmatter-pill-status')).toHaveTextContent('accepted');

        const body = screen.getByTestId('markdown-stub');
        expect(body.textContent).toContain('# Hello body');
        rawState.data = undefined;
    });
});
