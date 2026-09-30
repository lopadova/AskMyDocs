import { Children, isValidElement, useEffect, useMemo, useRef, useState, type ComponentType, type ReactNode } from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import remarkFrontmatter from 'remark-frontmatter';
import { remarkWikilink } from './remark-wikilink';
import { remarkObsidianTag } from './remark-obsidian-tag';
import { remarkCallout } from './remark-callout';
import { WikiLink } from '../../features/chat/WikilinkHover';
import { Icon } from '../../components/Icons';
import { Button } from '../../components/Button';
import './markdown.css';

/*
 * Shared markdown renderer for chat messages and KB/document previews.
 * The plugin stack is small on purpose: remark-gfm for tables/checklists,
 * remark-frontmatter so YAML frontmatter in source docs is silently
 * stripped, and three custom plugins for wikilinks / tags / callouts.
 *
 * Answer typography is opt-in; colours and controls use the application
 * tokens so light/dark themes share the same semantic content.
 */

const CALLOUT_LABELS: Record<string, string> = {
    note: 'Note', warning: 'Warning', tip: 'Tip', info: 'Info', important: 'Important', caution: 'Caution',
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
    const label = CALLOUT_LABELS[kind] ?? CALLOUT_LABELS.note;
    const Mark = ['warning', 'caution', 'important'].includes(kind) ? Icon.Alert : kind === 'tip' ? Icon.Check : Icon.Info;
    return (
        <div
            data-testid={`chat-callout-${kind}`}
            className="markdown-callout"
            data-kind={kind}
        >
            <div className="markdown-callout-title">
                <span aria-hidden="true"><Mark size={16} /></span>
                <strong>{title || label}</strong>
            </div>
            <div className="markdown-callout-content">{children}</div>
        </div>
    );
}

export interface MarkdownProps {
    source: string;
    project?: string;
    /** Editorial answer typography is opt-in; document previews keep their density. */
    variant?: 'default' | 'answer';
}

/**
 * Wraps every fenced code block with a language label and an always
 * discoverable copy button, including on touch screens. Stable testid
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
    const resetTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    useEffect(() => () => { if (resetTimer.current) clearTimeout(resetTimer.current); }, []);
    const code = Children.toArray(children).find((child) => isValidElement<{ className?: string }>(child));
    const language = isValidElement<{ className?: string }>(code)
        ? /(?:^|\s)language-([\w+-]+)/.exec(code.props.className ?? '')?.[1]
        : undefined;

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
            if (resetTimer.current) clearTimeout(resetTimer.current);
            resetTimer.current = setTimeout(() => setCopied(false), 1500);
        } catch {
            setCopied(false);
        }
    };

    return (
        <div
            data-testid="markdown-codeblock"
            className="markdown-codeblock"
        >
            <div className="markdown-codeblock-toolbar">
                <span className="markdown-codeblock-language"><span aria-hidden="true"><Icon.Terminal size={14} /></span>{language ?? 'Code'}</span>
                <Button
                    variant="quiet"
                    size="sm"
                    data-testid="markdown-codeblock-copy"
                    data-state={copied ? 'copied' : 'idle'}
                    onClick={handleCopy}
                    aria-label="Copy code"
                    title="Copy code"
                    leadingIcon={copied ? <Icon.Check size={13} /> : <Icon.Copy size={13} />}
                >
                    <span role="status">{copied ? 'Copied' : 'Copy'}</span>
                </Button>
            </div>
            <pre tabIndex={0} aria-label={language ? `${language} code` : 'Code block'}>{children}</pre>
        </div>
    );
}

function MarkdownTable({ children }: { children?: ReactNode }): ReactNode {
    return (
        <div className="markdown-table-scroll" role="region" aria-label="Data table" tabIndex={0}>
            <table>{children}</table>
        </div>
    );
}

export function Markdown({ source, project, variant = 'default' }: MarkdownProps): ReactNode {
    const components = useMemo(
        () =>
            ({
                wikilink: (props) => <WikiLink slug={props['data-slug'] ?? ''} label={props['data-label'] ?? ''} project={project} />,
                tag: Tag,
                callout: Callout,
                // v4.5/W7 — override <pre> to inject copy button.
                pre: CodeBlock,
                table: MarkdownTable,
            }) satisfies ExtraComponents & { pre: ComponentType<{ children?: ReactNode }>; table: ComponentType<{ children?: ReactNode }> },
        [project],
    );

    return (
        <div className={`markdown-body${variant === 'answer' ? ' markdown-body--answer' : ''}`}>
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
