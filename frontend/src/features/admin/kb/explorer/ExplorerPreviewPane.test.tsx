import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import type { KbTreeDocNode } from '../../admin.api';

// PreviewTab fetches via useKbRaw + renders Markdown; both are covered
// by their own tests. Stub them so this file focuses on the pane's
// header, chips, and the close / open-detail callbacks.
vi.mock('../PreviewTab', () => ({
    PreviewTab: ({ documentId }: { documentId: number }) => (
        <div data-testid="preview-tab-stub">doc:{documentId}</div>
    ),
}));

import { ExplorerPreviewPane } from './ExplorerPreviewPane';

function node(overrides: Partial<KbTreeDocNode['meta']> = {}): KbTreeDocNode {
    return {
        type: 'doc',
        name: 'remote.md',
        path: 'policies/remote.md',
        meta: {
            id: 42,
            project_key: 'hr-portal',
            title: 'Remote Work Policy',
            slug: 'remote-work',
            canonical_type: 'policy',
            canonical_status: 'accepted',
            is_canonical: true,
            indexed_at: null,
            updated_at: null,
            deleted_at: null,
            ...overrides,
        },
    };
}

describe('ExplorerPreviewPane', () => {
    it('renders the focused doc title, path, and reuses PreviewTab with its id', () => {
        render(<ExplorerPreviewPane node={node()} onClose={vi.fn()} onOpenDetail={vi.fn()} />);

        const pane = screen.getByTestId('kb-explorer-preview');
        expect(pane).toHaveAttribute('data-doc-id', '42');
        expect(screen.getByText('Remote Work Policy')).toBeInTheDocument();
        expect(screen.getByText('policies/remote.md')).toBeInTheDocument();
        expect(screen.getByTestId('preview-tab-stub')).toHaveTextContent('doc:42');
    });

    it('fires onClose when the close button is clicked', () => {
        const onClose = vi.fn();
        render(<ExplorerPreviewPane node={node()} onClose={onClose} onOpenDetail={vi.fn()} />);
        fireEvent.click(screen.getByTestId('kb-explorer-preview-close'));
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('fires onOpenDetail with the doc id when "Open full detail" is clicked', () => {
        const onOpenDetail = vi.fn();
        render(<ExplorerPreviewPane node={node()} onClose={vi.fn()} onOpenDetail={onOpenDetail} />);
        fireEvent.click(screen.getByTestId('kb-explorer-preview-open-detail'));
        expect(onOpenDetail).toHaveBeenCalledWith(42);
    });

    it('shows a deleted chip for a trashed doc', () => {
        render(
            <ExplorerPreviewPane
                node={node({ deleted_at: '2026-06-01T00:00:00Z' })}
                onClose={vi.fn()}
                onOpenDetail={vi.fn()}
            />,
        );
        expect(screen.getByText('deleted')).toBeInTheDocument();
    });
});
