import { useMemo, useState, type ComponentType, type ReactNode } from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import remarkFrontmatter from 'remark-frontmatter';
import { remarkWikilink } from './remark-wikilink';
import { remarkObsidianTag } from './remark-obsidian-tag';
import { remarkCallout } from './remark-callout';
import { WikiLink } from '../../features/chat/WikilinkHover';
import { Icon } from '../../components/Icons';

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

/**
 * v4.5/W7 Tier 1 #7 — copy-code-block button. Wraps every fenced code
 * block in a `<div>` with a hover-revealed copy button. Stable testid
 * `markdown-codeblock-copy` for Playwright.
 *
 * The component reads the textContent of the rendered `<code>` to
 * decide what to copy — that matches what the user sees, not the
 * AST-normalized source string. The wrapping `<div>` carries
 * `data-testid="markdown-codeblock"` so Playwright can scope to a
 * specific block on a long page.
 */
function CodeBlock({ children }: { children?: ReactNode }): ReactNode {
    const [copied, setCopied] = useState(false);

    const handleCopy = async (e: React.MouseEvent<HTMLButtonElement>) => {
        const pre = e.currentTarget.closest('[data-testid="markdown-codeblock"]');
        const code = pre?.querySelector('code');
        if (!code) {
            return;
        }
        // Guard: if the Clipboard API is unavailable, `navigator.clipboard?.writeText`
        // resolves to `undefined` (no throw) — never set copied=true in that case.
        if (!navigator.clipboard?.writeText) {
            return;
        }
        try {
            await navigator.clipboard.writeText(code.textContent ?? '');
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        } catch {
            setCopied(false);
        }
    };

    return (
        <div
            data-testid="markdown-codeblock"
            style={{ position: 'relative', margin: '12px 0' }}
        >
            <button
                type="button"
                data-testid="markdown-codeblock-copy"
                data-state={copied ? 'copied' : 'idle'}
                onClick={handleCopy}
                aria-label="Copy code"
                className="btn icon sm ghost"
                style={{
                    position: 'absolute',
                    top: 6,
                    right: 6,
                    opacity: 0.7,
                    background: 'var(--bg-3)',
                    border: '1px solid var(--panel-border)',
                    borderRadius: 6,
                }}
            >
                {copied ? <Icon.Check size={11} /> : <Icon.Copy size={11} />}
            </button>
            <pre
                style={{
                    margin: 0,
                    padding: '10px 12px',
                    paddingRight: 36,
                    background: 'var(--bg-2)',
                    border: '1px solid var(--panel-border)',
                    borderRadius: 8,
                    overflow: 'auto',
                    fontSize: 12.5,
                    fontFamily: 'var(--font-mono)',
                    lineHeight: 1.5,
                }}
            >
                {children}
            </pre>
        </div>
    );
}

export function Markdown({ source, project }: MarkdownProps): ReactNode {
    const components = useMemo(
        () =>
            ({
                wikilink: (props) => <WikiLink slug={props['data-slug'] ?? ''} label={props['data-label'] ?? ''} project={project} />,
                tag: Tag,
                callout: Callout,
                // v4.5/W7 — override <pre> to inject copy button.
                pre: CodeBlock,
            }) satisfies ExtraComponents & { pre: ComponentType<{ children?: ReactNode }> },
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
