import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { KbGraphResponse } from '../admin.api';

/*
 * PR11 / Phase G4 — GraphTab Vitest scenarios.
 *
 * We mock useKbGraph through a mutable state object so every test can
 * swap the returned shape without remounting. Layout logic (radial
 * positioning) is deterministic given a stable node list so we can
 * assert on testids without snapshotting SVG coordinates.
 */

const graphState: {
    data: KbGraphResponse | undefined;
    isLoading: boolean;
    isError: boolean;
} = {
    data: undefined,
    isLoading: false,
    isError: false,
};

vi.mock('./kb-document.api', () => ({
    useKbGraph: () => ({
        data: graphState.isError ? undefined : graphState.data,
        isLoading: graphState.isLoading,
        isError: graphState.isError,
    }),
}));

import { GraphTab } from './GraphTab';

function wrap(ui: React.ReactElement) {
    const qc = new QueryClient({
        defaultOptions: { queries: { retry: false } },
    });
    return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

describe('GraphTab', () => {
    it('renders loading state while the graph query is in flight', () => {
        graphState.isLoading = true;
        graphState.data = undefined;
        graphState.isError = false;
        wrap(<GraphTab documentId={1} />);
        expect(screen.getByTestId('kb-graph')).toHaveAttribute('data-state', 'loading');
        expect(screen.getByTestId('kb-graph-loading')).toBeInTheDocument();
        graphState.isLoading = false;
    });

    it('renders empty state when the endpoint returns zero nodes (raw doc)', () => {
        graphState.data = {
            nodes: [],
            edges: [],
            meta: {
                project_key: 'hr-portal',
                center_node_uid: null,
                generated_at: '2026-04-24T10:00:00Z',
            },
        };
        wrap(<GraphTab documentId={1} />);
        expect(screen.getByTestId('kb-graph')).toHaveAttribute('data-state', 'empty');
        expect(screen.getByTestId('kb-graph-empty')).toHaveTextContent(
            'canonicalize the document',
        );
        graphState.data = undefined;
    });

    it('renders error state on failure', () => {
        graphState.isError = true;
        graphState.data = undefined;
        wrap(<GraphTab documentId={1} />);
        expect(screen.getByTestId('kb-graph')).toHaveAttribute('data-state', 'error');
        expect(screen.getByTestId('kb-graph-error')).toBeInTheDocument();
        graphState.isError = false;
    });

    it('renders center + two neighbor nodes with correct roles and data-type', () => {
        graphState.data = {
            nodes: [
                {
                    uid: 'remote-work',
                    type: 'policy',
                    label: 'Remote Work Policy',
                    source_doc_id: 'doc-remote',
                    role: 'center',
                },
                {
                    uid: 'pto',
                    type: 'policy',
                    label: 'PTO Policy',
                    source_doc_id: 'doc-pto',
                    role: 'neighbor',
                },
                {
                    uid: 'compensation',
                    type: 'standard',
                    label: 'Compensation Std',
                    source_doc_id: 'doc-comp',
                    role: 'neighbor',
                },
            ],
            edges: [
                {
                    uid: 'e1',
                    from: 'remote-work',
                    to: 'pto',
                    type: 'related_to',
                    weight: 0.9,
                    provenance: 'wikilink',
                },
                {
                    uid: 'e2',
                    from: 'remote-work',
                    to: 'compensation',
                    type: 'depends_on',
                    weight: 0.7,
                    provenance: 'frontmatter_related',
                },
            ],
            meta: {
                project_key: 'hr-portal',
                center_node_uid: 'remote-work',
                generated_at: '2026-04-24T10:00:00Z',
            },
        };
        wrap(<GraphTab documentId={1} />);

        // Wrapper state + center attribute echoed back
        const wrapper = screen.getByTestId('kb-graph');
        expect(wrapper).toHaveAttribute('data-state', 'ready');
        expect(wrapper).toHaveAttribute('data-center-uid', 'remote-work');

        // Three node testids
        const center = screen.getByTestId('kb-graph-node-remote-work');
        expect(center).toHaveAttribute('data-role', 'center');
        expect(center).toHaveAttribute('data-type', 'policy');

        const pto = screen.getByTestId('kb-graph-node-pto');
        expect(pto).toHaveAttribute('data-role', 'neighbor');

        const comp = screen.getByTestId('kb-graph-node-compensation');
        expect(comp).toHaveAttribute('data-role', 'neighbor');
        expect(comp).toHaveAttribute('data-type', 'standard');

        graphState.data = undefined;
    });

    it('renders edge testids with data-edge-type round-tripped', () => {
        graphState.data = {
            nodes: [
                {
                    uid: 'a',
                    type: 'policy',
                    label: 'A',
                    source_doc_id: 'doc-a',
                    role: 'center',
                },
                {
                    uid: 'b',
                    type: 'policy',
                    label: 'B',
                    source_doc_id: 'doc-b',
                    role: 'neighbor',
                },
            ],
            edges: [
                {
                    uid: 'edge-xyz',
                    from: 'a',
                    to: 'b',
                    type: 'supersedes',
                    weight: 1.2,
                    provenance: 'frontmatter_supersedes',
                },
            ],
            meta: {
                project_key: 'hr-portal',
                center_node_uid: 'a',
                generated_at: '2026-04-24T10:00:00Z',
            },
        };
        wrap(<GraphTab documentId={1} />);
        const edge = screen.getByTestId('kb-graph-edge-edge-xyz');
        expect(edge).toBeInTheDocument();
        expect(edge).toHaveAttribute('data-edge-type', 'supersedes');
        graphState.data = undefined;
    });
});
