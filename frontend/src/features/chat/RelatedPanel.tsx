import { useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { getRelated, type RelatedNode } from './related.api';

/**
 * v8.8/W6 — chat-side "Related" panel: shows the 1-hop graph neighbours of the
 * canonical docs an answer cited, so the user can navigate the knowledge graph
 * from a grounded answer. Collapsible; fetches lazily only when there ARE
 * canonical slugs to expand from (non-canonical answers render nothing).
 *
 * R11 testids · R14 distinct loading/empty/error states · R15 the toggle is a
 * real <button> with aria-expanded.
 */
export function RelatedPanel({ projectKey, slugs }: { projectKey: string | null; slugs: string[] }): ReactNode {
    const [open, setOpen] = useState(false);

    const usableSlugs = slugs.filter((s): s is string => typeof s === 'string' && s !== '');
    const enabled = open && !!projectKey && usableSlugs.length > 0;

    const query = useQuery({
        queryKey: ['kb-related', projectKey, [...usableSlugs].sort()],
        queryFn: () => getRelated(projectKey as string, usableSlugs),
        enabled,
        staleTime: 30_000,
    });

    // Nothing to relate from (no canonical citations) → render nothing.
    if (!projectKey || usableSlugs.length === 0) {
        return null;
    }

    const nodes = query.data?.related ?? [];
    const state = !open ? 'idle' : query.isLoading ? 'loading' : query.isError ? 'error' : nodes.length === 0 ? 'empty' : 'ready';

    return (
        <div data-testid="chat-related-panel" data-state={state} style={{ marginTop: 8 }}>
            <button
                type="button"
                data-testid="chat-related-toggle"
                aria-expanded={open}
                onClick={() => setOpen((v) => !v)}
                style={{
                    fontSize: 11.5, color: 'var(--fg-2)', background: 'transparent',
                    border: '1px solid var(--panel-border, rgba(255,255,255,.12))', borderRadius: 6,
                    padding: '2px 10px', cursor: 'pointer',
                }}
            >
                {open ? '▾' : '▸'} Related
            </button>

            {open && (
                <div data-testid="chat-related-body" style={{ marginTop: 6 }}>
                    {query.isLoading && (
                        <p data-testid="chat-related-loading" data-state="loading" style={{ fontSize: 11.5, color: 'var(--fg-3)' }}>Loading…</p>
                    )}
                    {query.isError && (
                        <p data-testid="chat-related-error" data-state="error" role="alert" style={{ fontSize: 11.5, color: 'var(--err)' }}>
                            Couldn't load related documents. {query.error instanceof Error ? query.error.message : ''}
                        </p>
                    )}
                    {!query.isLoading && !query.isError && nodes.length === 0 && (
                        <p data-testid="chat-related-empty" data-state="empty" style={{ fontSize: 11.5, color: 'var(--fg-3)' }}>
                            No related documents in the knowledge graph.
                        </p>
                    )}
                    {nodes.length > 0 && (
                        <ul data-testid="chat-related-list" data-state="ready" style={{ margin: 0, paddingLeft: 16, fontSize: 12 }}>
                            {nodes.map((n) => (
                                <RelatedItem key={n.slug} node={n} />
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}

function RelatedItem({ node }: { node: RelatedNode }): ReactNode {
    return (
        <li data-testid={`chat-related-item-${node.slug}`} data-direction={node.direction} style={{ margin: '2px 0', color: 'var(--fg-1)' }}>
            <span style={{ color: 'var(--fg-0)' }}>{node.title ?? node.slug}</span>{' '}
            <span style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>
                ({node.direction === 'outgoing' ? '→' : '←'} {node.edge_type.replace(/_/g, ' ')})
            </span>
        </li>
    );
}
