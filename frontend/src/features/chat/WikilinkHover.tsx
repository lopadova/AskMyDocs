import { useQuery } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { api } from '../../lib/api';

export interface WikilinkPayload {
    document_id: number;
    title: string;
    source_path: string;
    canonical_type: string | null;
    canonical_status: string | null;
    is_canonical: boolean;
    preview: string;
}

export interface WikiLinkProps {
    slug: string;
    label?: string;
    project?: string;
}

async function fetchWikilink(project: string | undefined, slug: string): Promise<WikilinkPayload | null> {
    if (!project || !slug) {
        return null;
    }
    try {
        const { data } = await api.get<WikilinkPayload>('/api/kb/resolve-wikilink', {
            params: { project, slug },
        });
        return data;
    } catch (err) {
        // 404 / 403 → degrade gracefully (renderer keeps the [[slug]] text).
        return null;
    }
}

/**
 * Renders `[[slug]]` as a gradient-underlined anchor; on hover, fetches
 * the preview via TanStack Query (cached per project+slug) and shows
 * the popover that mirrors the Claude Design chat reference.
 */
export function WikiLink({ slug, label, project }: WikiLinkProps): ReactNode {
    const [hover, setHover] = useState(false);

    const { data, isLoading, isError } = useQuery<WikilinkPayload | null>({
        queryKey: ['wikilink', project ?? 'default', slug],
        queryFn: () => fetchWikilink(project, slug),
        enabled: Boolean(slug) && hover,
        staleTime: 5 * 60_000,
    });

    const resolved = data !== null && data !== undefined;
    const display = label && label.length > 0 ? label : slug;

    return (
        <span
            data-testid={`wikilink-${slug}`}
            data-resolved={resolved ? 'true' : 'false'}
            data-state={isLoading ? 'loading' : isError ? 'error' : resolved ? 'ready' : hover ? 'empty' : 'idle'}
            style={{ position: 'relative', display: 'inline-block' }}
            onMouseEnter={() => setHover(true)}
            onMouseLeave={() => setHover(false)}
        >
            <a
                data-testid={`wikilink-anchor-${slug}`}
                tabIndex={0}
                style={{
                    color: 'transparent',
                    backgroundImage: 'var(--grad-accent)',
                    backgroundClip: 'text',
                    WebkitBackgroundClip: 'text',
                    borderBottom: '1px dashed rgba(139,92,246,.5)',
                    cursor: 'pointer',
                    fontWeight: 500,
                }}
            >
                [[{display}]]
            </a>
            {hover && (
                <span
                    data-testid="wikilink-preview"
                    role="tooltip"
                    className="panel popin"
                    style={{
                        position: 'absolute',
                        bottom: 'calc(100% + 6px)',
                        left: 0,
                        zIndex: 40,
                        minWidth: 280,
                        padding: 12,
                        fontSize: 12,
                        background: 'var(--panel-solid)',
                        boxShadow: 'var(--shadow-lg)',
                        border: '1px solid var(--panel-border-strong)',
                        borderRadius: 10,
                    }}
                >
                    <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 6 }}>
                        <span className="mono" style={{ fontSize: 11, color: 'var(--fg-2)' }}>{slug}.md</span>
                        {data?.canonical_type && (
                            <span
                                className="pill"
                                style={{
                                    marginLeft: 'auto',
                                    fontSize: 10,
                                    padding: '2px 8px',
                                    background: 'var(--bg-3)',
                                    border: '1px solid var(--panel-border)',
                                    borderRadius: 99,
                                }}
                            >
                                {data.canonical_type}
                            </span>
                        )}
                    </div>
                    {isLoading && <div style={{ color: 'var(--fg-3)' }}>Loading…</div>}
                    {isError && <div data-testid="wikilink-preview-error" style={{ color: 'var(--fg-3)' }}>Preview unavailable.</div>}
                    {!isLoading && !isError && !resolved && (
                        <div style={{ color: 'var(--fg-3)' }}>No canonical document found for this slug yet.</div>
                    )}
                    {resolved && data && (
                        <>
                            <div style={{ color: 'var(--fg-0)', lineHeight: 1.4, fontWeight: 500, marginBottom: 4 }}>
                                {data.title}
                            </div>
                            <div style={{ color: 'var(--fg-2)', lineHeight: 1.5 }}>{data.preview}</div>
                        </>
                    )}
                </span>
            )}
        </span>
    );
}
