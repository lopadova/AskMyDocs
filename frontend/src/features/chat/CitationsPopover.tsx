import { useState, type ReactNode } from 'react';
import type { MessageCitation } from './chat.api';

export interface CitationsPopoverProps {
    citations: MessageCitation[];
    /**
     * Click handler for a citation chip — wired by ChatView to navigate to
     * the KB document detail (`/app/admin/kb?doc=<id>`). Passed ONLY when the
     * current user can reach the KB admin surface (admin / super-admin); for
     * other roles the chip stays hover-only so it never dead-ends on a 403.
     * Called only for citations that carry a `document_id`.
     */
    onOpenSource?: (citation: MessageCitation) => void;
}

const ORIGIN_PALETTE: Record<string, { label: string; color: string }> = {
    primary: { label: 'primary', color: 'var(--accent-a)' },
    related: { label: 'related', color: 'var(--accent-b)' },
    rejected: { label: 'rejected', color: '#ef4444' },
};

/**
 * Row of citation chips under an assistant message. Each chip opens a
 * hover popover with the doc title + excerpt + origin pill (primary /
 * related / rejected).
 *
 * R11: the strip itself carries `data-testid="chat-citations"`; each
 * chip is `chat-citation-<idx>` and the popover for the chip is
 * `chat-citations-popover` (opened state via `data-state="open"`).
 */
export function CitationsPopover({ citations, onOpenSource }: CitationsPopoverProps): ReactNode {
    const [openIdx, setOpenIdx] = useState<number | null>(null);

    return (
        <div
            data-testid="chat-citations"
            data-count={citations.length}
            style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 10 }}
        >
            {citations.map((c, i) => (
                <CitationChip
                    key={`${c.source_path ?? 'x'}-${i}`}
                    citation={c}
                    index={i}
                    open={openIdx === i}
                    onHover={(open) => setOpenIdx(open ? i : null)}
                    onOpenSource={onOpenSource}
                />
            ))}
        </div>
    );
}

interface CitationChipProps {
    citation: MessageCitation;
    index: number;
    open: boolean;
    onHover: (open: boolean) => void;
    onOpenSource?: (citation: MessageCitation) => void;
}

function CitationChip({ citation, index, open, onHover, onOpenSource }: CitationChipProps): ReactNode {
    const origin = citation.origin ?? 'primary';
    const palette = ORIGIN_PALETTE[origin] ?? ORIGIN_PALETTE.primary;
    const short = citation.source_path ?? citation.title ?? `#${index + 1}`;
    // The chip opens its source only when a handler is wired AND the citation
    // resolves to a concrete document (rejected-approach citations / legacy
    // rows may have a null document_id and have nothing to open).
    const canOpen = onOpenSource !== undefined && citation.document_id != null;

    return (
        <span
            style={{ position: 'relative' }}
            onMouseEnter={() => onHover(true)}
            onMouseLeave={() => onHover(false)}
        >
            <button
                type="button"
                data-testid={`chat-citation-${index}`}
                data-origin={origin}
                data-openable={canOpen ? 'true' : 'false'}
                aria-label={
                    canOpen
                        ? `Open source ${index + 1}: ${short}`
                        : `Citation ${index + 1}: ${short}`
                }
                onClick={canOpen ? () => onOpenSource?.(citation) : undefined}
                style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 6,
                    padding: '4px 9px 4px 4px',
                    background: 'var(--bg-2)',
                    border: '1px solid var(--panel-border)',
                    borderRadius: 99,
                    cursor: canOpen ? 'pointer' : 'default',
                    color: 'var(--fg-1)',
                    fontSize: 11.5,
                }}
            >
                <span
                    style={{
                        width: 18,
                        height: 18,
                        borderRadius: 99,
                        background: 'var(--grad-accent)',
                        display: 'inline-flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        fontSize: 10,
                        fontWeight: 600,
                        color: '#0a0a14',
                        fontFamily: 'var(--font-mono)',
                    }}
                >
                    {index + 1}
                </span>
                <span className="mono" style={{ fontSize: 11 }}>
                    {short}
                </span>
                {citation.headings && citation.headings[0] && (
                    <>
                        <span style={{ color: 'var(--fg-3)' }}>·</span>
                        <span style={{ color: 'var(--fg-3)' }}>§{citation.headings[0]}</span>
                    </>
                )}
            </button>
            {open && (
                <span
                    role="tooltip"
                    data-testid="chat-citations-popover"
                    data-state="open"
                    data-origin={origin}
                    className="panel popin"
                    style={{
                        position: 'absolute',
                        bottom: 'calc(100% + 8px)',
                        left: 0,
                        zIndex: 40,
                        width: 360,
                        padding: 14,
                        fontSize: 12,
                        background: 'var(--panel-solid)',
                        boxShadow: 'var(--shadow-lg)',
                        border: '1px solid var(--panel-border-strong)',
                        borderRadius: 10,
                    }}
                >
                    <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 8 }}>
                        <span
                            className="pill"
                            style={{
                                padding: '2px 8px',
                                fontSize: 10.5,
                                borderRadius: 99,
                                background: 'var(--bg-3)',
                                border: `1px solid ${palette.color}`,
                                color: palette.color,
                                textTransform: 'uppercase',
                                letterSpacing: '.06em',
                                fontFamily: 'var(--font-mono)',
                            }}
                        >
                            {palette.label}
                        </span>
                        <span className="mono" style={{ fontSize: 10.5, color: 'var(--fg-2)' }}>
                            {citation.source_path}
                        </span>
                    </div>
                    <div
                        style={{
                            color: 'var(--fg-0)',
                            lineHeight: 1.5,
                            fontSize: 13,
                            fontWeight: 500,
                            marginBottom: 6,
                        }}
                    >
                        {citation.title}
                    </div>
                    {citation.headings && citation.headings.length > 0 && (
                        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 6 }}>
                            {citation.headings.slice(0, 3).map((h, i) => (
                                <span
                                    key={i}
                                    className="mono"
                                    style={{ fontSize: 10.5, color: 'var(--fg-3)' }}
                                >
                                    §{h}
                                </span>
                            ))}
                        </div>
                    )}
                </span>
            )}
        </span>
    );
}
