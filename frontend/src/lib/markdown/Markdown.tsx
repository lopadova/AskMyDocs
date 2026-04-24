import { useMemo, type ComponentType, type ReactNode } from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import remarkFrontmatter from 'remark-frontmatter';
import { remarkWikilink } from './remark-wikilink';
import { remarkObsidianTag } from './remark-obsidian-tag';
import { remarkCallout } from './remark-callout';
import { WikiLink } from '../../features/chat/WikilinkHover';

/*
 * Shared markdown renderer for chat messages + (later) the KB viewer.
 * The plugin stack is small on purpose: remark-gfm for tables/checklists,
 * remark-frontmatter so YAML frontmatter in source docs is silently
 * stripped, and three custom plugins for wikilinks / tags / callouts.
 *
 * Tokens drive every visual decision (see styles/tokens.css) — no
 * tailwind utilities here so the design-reference port stays 1:1.
 */

const CALLOUT_PALETTE: Record<string, { color: string; label: string }> = {
    note: { color: '#22d3ee', label: 'Note' },
    warning: { color: '#f59e0b', label: 'Warning' },
    tip: { color: '#10b981', label: 'Tip' },
    info: { color: '#8b5cf6', label: 'Info' },
    important: { color: '#f97316', label: 'Important' },
    caution: { color: '#ef4444', label: 'Caution' },
};

type ExtraComponents = {
    wikilink: ComponentType<{ 'data-slug'?: string; 'data-label'?: string; children?: ReactNode }>;
    tag: ComponentType<{ 'data-label'?: string; children?: ReactNode }>;
    callout: ComponentType<{ 'data-kind'?: string; 'data-title'?: string; children?: ReactNode }>;
};

function Tag({ 'data-label': label }: { 'data-label'?: string }): ReactNode {
    if (!label) {
        return null;
    }
    return (
        <span
            data-testid={`chat-tag-${label}`}
            style={{
                display: 'inline-flex',
                alignItems: 'center',
                padding: '1px 8px',
                background: 'var(--bg-3)',
                border: '1px solid var(--panel-border)',
                borderRadius: 99,
                fontSize: 11,
                fontFamily: 'var(--font-mono)',
                color: 'var(--fg-1)',
                marginInline: 2,
            }}
        >
            #{label}
        </span>
    );
}

function Callout({ 'data-kind': kind = 'note', 'data-title': title, children }: { 'data-kind'?: string; 'data-title'?: string; children?: ReactNode }): ReactNode {
    const palette = CALLOUT_PALETTE[kind] ?? CALLOUT_PALETTE.note;
    return (
        <div
            data-testid={`chat-callout-${kind}`}
            style={{
                margin: '12px 0',
                padding: '10px 14px',
                background: `${palette.color}15`,
                border: `1px solid ${palette.color}40`,
                borderLeft: `3px solid ${palette.color}`,
                borderRadius: 8,
                fontSize: 13,
            }}
        >
            <div
                style={{
                    fontSize: 10.5,
                    color: palette.color,
                    textTransform: 'uppercase',
                    letterSpacing: '.08em',
                    fontWeight: 600,
                    marginBottom: 3,
                    fontFamily: 'var(--font-mono)',
                }}
            >
                {title ? `${palette.label} · ${title}` : palette.label}
            </div>
            <div style={{ color: 'var(--fg-1)', lineHeight: 1.55 }}>{children}</div>
        </div>
    );
}

export interface MarkdownProps {
    source: string;
    project?: string;
}

export function Markdown({ source, project }: MarkdownProps): ReactNode {
    const components = useMemo(
        () =>
            ({
                wikilink: (props) => <WikiLink slug={props['data-slug'] ?? ''} label={props['data-label'] ?? ''} project={project} />,
                tag: Tag,
                callout: Callout,
            }) satisfies ExtraComponents,
        [project],
    );

    return (
        <div className="markdown-body" style={{ fontSize: 13.5, color: 'var(--fg-1)', lineHeight: 1.65 }}>
            <ReactMarkdown
                remarkPlugins={[
                    remarkGfm,
                    [remarkFrontmatter, ['yaml', 'toml']],
                    remarkWikilink,
                    remarkObsidianTag,
                    remarkCallout,
                ]}
                components={components as never}
            >
                {source}
            </ReactMarkdown>
        </div>
    );
}
