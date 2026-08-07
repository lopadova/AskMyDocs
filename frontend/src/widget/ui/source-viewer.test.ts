import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    SourceViewer,
    renderSafeMarkdown,
    type SourceCitation,
    type SourceDocumentPreview,
} from './source-viewer';

const CITATIONS: SourceCitation[] = [
    {
        document_id: 7,
        title: 'Cache decision',
        source_path: 'decisions/cache.md',
        origin: 'primary',
        chunks: [{ chunk_id: 70, heading: 'Decision', snippet: 'Redis keeps hot reads fast.', evidence_hash: 'a' }],
    },
    {
        document_id: 8,
        title: 'Operations guide',
        source_path: 'runbooks/operations.md',
        origin: 'related',
        chunks: [],
    },
    {
        document_id: 7,
        title: 'Cache decision',
        source_path: 'decisions/cache.md',
        origin: 'related',
        chunks: [{ chunk_id: 71, heading: 'TTL', snippet: 'Use a one hour TTL.', evidence_hash: 'b' }],
    },
];

function preview(id: number, content = '# Heading\n\n**Rendered** body.'): SourceDocumentPreview {
    return {
        document_id: id,
        title: id === 7 ? 'Cache decision' : 'Operations guide',
        source_path: id === 7 ? 'decisions/cache.md' : 'runbooks/operations.md',
        source_type: 'markdown',
        language: 'en',
        source_updated_at: '2026-08-07T10:00:00Z',
        sections: [{ heading_path: 'Architecture', content }],
    };
}

describe('SourceViewer', () => {
    let root: HTMLElement;
    let trigger: HTMLButtonElement;

    beforeEach(() => {
        document.body.innerHTML = '<button id="trigger">Fonti</button><div id="root"></div>';
        root = document.querySelector<HTMLElement>('#root')!;
        trigger = document.querySelector<HTMLButtonElement>('#trigger')!;
    });

    afterEach(() => {
        document.body.replaceChildren();
        vi.restoreAllMocks();
    });

    it('deduplicates sources, merges evidence, and renders the selected full document', async () => {
        const fetcher = vi.fn(async (id: number) => preview(id));
        const viewer = new SourceViewer(root, fetcher);

        viewer.open(CITATIONS, 7, trigger);

        expect(root.querySelectorAll('.amd-source-item')).toHaveLength(2);
        expect(root.querySelector('[data-testid="askmydocs-widget-source-viewer"]')).toHaveAttribute('open');
        expect(root.querySelector('[data-testid="askmydocs-widget-source-loading"]')).toHaveAttribute('role', 'status');
        expect(root.querySelector('[data-testid="askmydocs-widget-source-content"]')).toHaveAttribute('aria-busy', 'true');
        await vi.waitFor(() => {
            expect(root.querySelector('[data-testid="askmydocs-widget-source-content"]')).toHaveAttribute('data-state', 'ready');
        });
        expect(root.querySelector('[data-testid="askmydocs-widget-source-content"]')).toHaveAttribute('aria-busy', 'false');
        expect(fetcher).toHaveBeenCalledWith(7, expect.any(AbortSignal));
        expect(root.querySelector('[data-testid="askmydocs-widget-source-evidence"]')?.textContent)
            .toContain('Redis keeps hot reads fast.');
        expect(root.querySelector('[data-testid="askmydocs-widget-source-evidence"]')?.textContent)
            .toContain('Use a one hour TTL.');
        expect(root.querySelector('.amd-source-markdown strong')).toHaveTextContent('Rendered');
        expect(root.querySelectorAll('.amd-source-origin')).toHaveLength(2);
    });

    it('aborts the previous request when the reader changes source', async () => {
        const signals: AbortSignal[] = [];
        const fetcher = vi.fn((id: number, signal: AbortSignal) => {
            signals.push(signal);
            return new Promise<SourceDocumentPreview>((resolve) => {
                if (id === 8) resolve(preview(8));
            });
        });
        const viewer = new SourceViewer(root, fetcher);
        viewer.open(CITATIONS, 7, trigger);

        (root.querySelector('[data-testid="askmydocs-widget-source-item-1"]') as HTMLButtonElement).click();

        expect(signals[0].aborted).toBe(true);
        await vi.waitFor(() => expect(root.querySelector('.amd-source-title')).toHaveTextContent('Operations guide'));
    });

    it('shows a retryable failure and does not cache failed requests', async () => {
        const fetcher = vi.fn()
            .mockRejectedValueOnce(new Error('503'))
            .mockResolvedValueOnce(preview(7, 'Recovered'));
        const viewer = new SourceViewer(root, fetcher);
        viewer.open(CITATIONS, 7, trigger);

        await vi.waitFor(() => expect(
            root.querySelector<HTMLButtonElement>('[data-testid="askmydocs-widget-source-retry"]'),
        ).not.toBeNull());
        const retry = root.querySelector<HTMLButtonElement>('[data-testid="askmydocs-widget-source-retry"]');
        retry?.click();
        await vi.waitFor(() => expect(root.querySelector('.amd-source-markdown')).toHaveTextContent('Recovered'));
        expect(fetcher).toHaveBeenCalledTimes(2);
    });

    it('caches by session and document without leaking the entry to another session', async () => {
        let session = 'session-a';
        const fetcher = vi.fn(async (id: number) => preview(id));
        const viewer = new SourceViewer(root, fetcher, () => session);

        viewer.open(CITATIONS, 7, trigger);
        await vi.waitFor(() => expect(root.querySelector('.amd-source-markdown')).not.toBeNull());
        viewer.open(CITATIONS, 7, trigger);
        expect(fetcher).toHaveBeenCalledTimes(1);

        session = 'session-b';
        viewer.open(CITATIONS, 7, trigger);
        await vi.waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2));
    });

    it('shows every merged evidence passage instead of silently truncating the list', async () => {
        const manyEvidence: SourceCitation[] = [{
            ...CITATIONS[0],
            chunks: Array.from({ length: 8 }, (_, index) => ({
                chunk_id: index + 1,
                heading: `Section ${index + 1}`,
                snippet: `Evidence passage ${index + 1}`,
            })),
        }];
        const viewer = new SourceViewer(root, async (id) => preview(id));

        viewer.open(manyEvidence, 7, trigger);
        await vi.waitFor(() => expect(root.querySelector('.amd-source-markdown')).not.toBeNull());

        expect(root.querySelectorAll('.amd-source-evidence blockquote')).toHaveLength(8);
        expect(root.querySelector('.amd-source-evidence')).toHaveTextContent('Evidence passage 8');
    });

    it('renders an explicit empty state and restores focus when closed', async () => {
        const viewer = new SourceViewer(root, async () => preview(7, ''));
        trigger.focus();
        viewer.open(CITATIONS, 7, trigger);
        await vi.waitFor(() => expect(root.querySelector('[data-testid="askmydocs-widget-source-empty"]')).not.toBeNull());

        (root.querySelector('[data-testid="askmydocs-widget-source-close"]') as HTMLButtonElement).click();
        expect(root.querySelector('[data-testid="askmydocs-widget-source-viewer"]')).not.toHaveAttribute('open');
        expect(document.activeElement).toBe(trigger);
    });
});

describe('renderSafeMarkdown', () => {
    it('escapes raw HTML, removes remote images, and hardens safe links', () => {
        const host = document.createElement('div');
        host.append(renderSafeMarkdown([
            '<script>alert(1)</script>',
            '',
            '![tracking](https://tracker.example/pixel.png)',
            '',
            '[safe](https://example.com) [unsafe](javascript:alert(1))',
        ].join('\n')));

        expect(host.querySelector('script')).toBeNull();
        expect(host.querySelector('img')).toBeNull();
        expect(host.textContent).toContain('<script>alert(1)</script>');
        expect(host.textContent).toContain('[Immagine: tracking]');
        const link = host.querySelector('a');
        expect(link).toHaveAttribute('href', 'https://example.com');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        expect(host.querySelectorAll('a')).toHaveLength(1);
    });
});
