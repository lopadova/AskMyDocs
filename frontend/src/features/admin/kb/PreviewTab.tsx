import { useMemo } from 'react';
import { Markdown } from '../../../lib/markdown';
import { useKbRaw } from './kb-document.api';

/*
 * Phase G2 — Preview tab.
 *
 * Fetches the raw markdown on mount (lazy — useKbRaw is gated by
 * documentId). Frontmatter is extracted into a small pill-pack
 * ABOVE the body so the reader sees the doc's canonical stamps at
 * a glance, and then the Markdown renderer strips the YAML fence
 * from the body automatically (remark-frontmatter).
 *
 * We parse the frontmatter here with a hand-rolled key:value splitter
 * that covers the scalar case. Anything complex (lists / maps) stays
 * in the canonical `frontmatter_json._derived` map on the Meta tab —
 * the preview pills are a tactile summary, not a full YAML viewer.
 */

export interface PreviewTabProps {
    documentId: number;
    project: string | null;
}

export function PreviewTab({ documentId, project }: PreviewTabProps) {
    const query = useKbRaw(documentId);

    const { pills, body } = useMemo(() => {
        if (!query.data) {
            return { pills: [] as Array<{ key: string; value: string }>, body: '' };
        }
        return extractFrontmatterPills(query.data.content);
    }, [query.data]);

    if (query.isLoading) {
        return (
            <div data-testid="kb-preview-loading" style={{ color: 'var(--fg-3)' }}>
                Loading preview…
            </div>
        );
    }

    if (query.isError) {
        return (
            <div
                data-testid="kb-preview-error"
                style={{ color: 'var(--danger-fg, #b91c1c)', fontSize: 12.5 }}
            >
                Could not load the markdown. The file may be missing on disk.
            </div>
        );
    }

    return (
        <div data-testid="kb-preview" style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
            {pills.length > 0 ? (
                <div
                    data-testid="frontmatter-pills"
                    style={{
                        display: 'flex',
                        flexWrap: 'wrap',
                        gap: 6,
                        padding: 8,
                        border: '1px solid var(--hairline)',
                        borderRadius: 8,
                        background: 'var(--bg-0)',
                    }}
                >
                    {pills.map((pill) => (
                        <span
                            key={pill.key}
                            data-testid={`frontmatter-pill-${pill.key}`}
                            style={{
                                padding: '2px 8px',
                                borderRadius: 999,
                                border: '1px solid var(--hairline)',
                                background: 'var(--bg-1)',
                                color: 'var(--fg-2)',
                                fontSize: 11,
                                fontFamily: 'var(--font-mono)',
                            }}
                        >
                            <strong style={{ color: 'var(--fg-1)', fontWeight: 600 }}>
                                {pill.key}:
                            </strong>{' '}
                            {pill.value}
                        </span>
                    ))}
                </div>
            ) : null}

            <div data-testid="kb-preview-body">
                <Markdown source={body} project={project ?? undefined} />
            </div>
        </div>
    );
}

/**
 * Extract a shallow set of `key: value` pairs from a YAML frontmatter
 * fence. We stop at the first `---` closer; if the first line isn't
 * `---` we treat the whole source as body. Anything the scalar parser
 * can't express (lists / blocks) becomes a "[…]" placeholder so the
 * user still sees the key exists.
 */
export function extractFrontmatterPills(source: string): {
    pills: Array<{ key: string; value: string }>;
    body: string;
} {
    // Normalise CR-LF to LF so the split below is portable; this is
    // the only time we touch the raw bytes — the Markdown renderer
    // is responsible for the body from here on.
    const normalised = source.replace(/\r\n/g, '\n');

    if (!normalised.startsWith('---\n')) {
        return { pills: [], body: normalised };
    }

    const rest = normalised.slice(4);
    const closer = rest.indexOf('\n---\n');
    if (closer === -1) {
        // Unterminated frontmatter — bail out; let the markdown
        // renderer show the raw content.
        return { pills: [], body: normalised };
    }

    const head = rest.slice(0, closer);
    const body = rest.slice(closer + 5);

    const pills: Array<{ key: string; value: string }> = [];
    for (const line of head.split('\n')) {
        const trimmed = line.trim();
        if (trimmed === '' || trimmed.startsWith('#')) {
            continue;
        }
        // Ignore continuation / nested lines; only surface top-level
        // scalar keys that start in column 0.
        if (line[0] === ' ' || line[0] === '\t' || line.startsWith('- ')) {
            continue;
        }
        const colon = line.indexOf(':');
        if (colon === -1) {
            continue;
        }
        const key = line.slice(0, colon).trim();
        const rawValue = line.slice(colon + 1).trim();
        if (key === '') {
            continue;
        }
        const value = rawValue === '' ? '[…]' : rawValue.replace(/^["']|["']$/g, '');
        pills.push({ key, value });
    }

    return { pills, body };
}
