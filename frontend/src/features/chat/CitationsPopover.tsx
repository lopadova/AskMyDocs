import { useLayoutEffect, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import type { MessageCitation } from './chat.api';

export interface CitationsPopoverProps {
    citations: MessageCitation[];
    /**
     * Click handler for a citation chip — wired by ChatView to open the cited
     * document in an in-chat modal ({@link CitationDocumentModal}) for EVERY
     * reader. The modal fetches the source through a tenant + AccessScope-scoped
     * endpoint, so it never dead-ends on a 403. Called only for citations that
     * carry a `document_id`.
     */
    onOpenSource?: (citation: MessageCitation) => void;
}

interface OriginStyle {
    label: string;
    /** Accent used for the popover origin pill border + text. */
    color: string;
    /** Background of the chip's numbered badge. */
    badge: string;
    /** Foreground of the chip's numbered badge. */
    badgeFg: string;
}

const ORIGIN_PALETTE: Record<string, OriginStyle> = {
    primary: { label: 'primary', color: 'var(--accent-a)', badge: 'var(--grad-accent)', badgeFg: '#0a0a14' },
    related: { label: 'related', color: 'var(--accent-b)', badge: 'var(--accent-b)', badgeFg: '#06222a' },
    rejected: { label: 'rejected', color: '#ef4444', badge: '#ef4444', badgeFg: '#fff' },
};

/**
 * Basename of a KB source path — the chip shows just the filename
 * (e.g. `blog-settings.md`) while the popover keeps the full path. The
 * extension is preserved on purpose: the ingest pipeline accepts both
 * `.md` AND `.markdown`, so stripping it would mislabel `.markdown`
 * docs (R18). Returns null for empty / null input so the caller can
 * fall back to the title.
 */
function fileName(path: string | null | undefined): string | null {
    if (!path) {
        return null;
    }
    const segments = path.replace(/[\\/]+$/, '').split(/[\\/]/);
    const last = segments[segments.length - 1];
    return last.length > 0 ? last : null;
}

/**
 * Most-specific heading for the chip. `headings[0]` is a full
 * breadcrumb string (`"A > B > C"`); rendering the whole thing is what
 * blew the chips up into ragged multi-line rows. We surface only the
 * last segment (`C`) inline — the complete breadcrumb stays in the
 * popover.
 */
function lastHeadingSegment(headings?: string[]): string | null {
    const first = headings?.[0];
    if (!first) {
        return null;
    }
    const segments = first.split('>').map((s) => s.trim()).filter((s) => s.length > 0);
    const tail = segments.length > 0 ? segments[segments.length - 1] : first.trim();
    return tail.length > 0 ? tail : null;
}

/**
 * Row of citation chips under an assistant message. Each chip is a
 * compact pill (numbered badge + filename + most-specific heading); on
 * hover OR keyboard focus it opens a popover with the doc title, full
 * source path, full heading breadcrumb and an origin pill (primary /
 * related / rejected).
 *
 * The popover opens BELOW the chip by default so it never covers the
 * answer that sits directly above the strip; it flips above only when
 * there isn't room below (last message near the viewport bottom).
 *
 * R11: the strip carries `data-testid="chat-citations"` + `data-count`;
 * each chip is `chat-citation-<idx>`; the popover is
 * `chat-citations-popover` (`data-state="open"`, `data-placement`).
 * R15: the popover is reachable by keyboard (focus/blur) and Escape
 * closes it.
 */
export function CitationsPopover({ citations, onOpenSource }: CitationsPopoverProps): ReactNode {
    const [openIdx, setOpenIdx] = useState<number | null>(null);

    return (
        <div data-testid="chat-citations" data-count={citations.length} style={{ marginTop: 10 }}>
            <div
                style={{
                    fontSize: 10.5,
                    color: 'var(--fg-3)',
                    fontFamily: 'var(--font-mono)',
                    letterSpacing: '.04em',
                    marginBottom: 6,
                }}
            >
                Sources · {citations.length}
            </div>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
                {citations.map((c, i) => (
                    <CitationChip
                        key={`${c.source_path ?? 'x'}-${i}`}
                        citation={c}
                        index={i}
                        open={openIdx === i}
                        onOpenChange={(open) => setOpenIdx(open ? i : null)}
                        onOpenSource={onOpenSource}
                    />
                ))}
            </div>
        </div>
    );
}

interface CitationChipProps {
    citation: MessageCitation;
    index: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onOpenSource?: (citation: MessageCitation) => void;
}

const ELLIPSIS: CSSProperties = {
    minWidth: 0,
    overflow: 'hidden',
    textOverflow: 'ellipsis',
    whiteSpace: 'nowrap',
};

function CitationChip({ citation, index, open, onOpenChange, onOpenSource }: CitationChipProps): ReactNode {
    const origin = citation.origin ?? 'primary';
    const palette = ORIGIN_PALETTE[origin] ?? ORIGIN_PALETTE.primary;
    // Visible chip label: just the filename. The aria-label + popover keep
    // the full path so screen-reader and hover users lose nothing.
    const label = fileName(citation.source_path) ?? citation.title ?? `#${index + 1}`;
    const ariaTarget = citation.source_path ?? citation.title ?? `#${index + 1}`;
    const heading = lastHeadingSegment(citation.headings);
    // The chip opens its source only when a handler is wired AND the citation
    // resolves to a concrete document (rejected-approach citations / legacy
    // rows may have a null document_id and have nothing to open).
    const canOpen = onOpenSource !== undefined && citation.document_id != null;

    const wrapRef = useRef<HTMLSpanElement>(null);
    const [placement, setPlacement] = useState<'top' | 'bottom'>('bottom');
    const popoverId = `chat-citation-popover-${index}`;
    // Hover and keyboard focus are tracked separately so the popover stays
    // open while EITHER is active (R15). Collapsed into one boolean,
    // onMouseLeave closed it while the chip was still focused (and onBlur
    // while still hovered) — the flicker Copilot flagged.
    const [hovered, setHovered] = useState(false);
    const [focused, setFocused] = useState(false);

    // Prefer opening below the chip (keeps the answer above readable). Flip
    // above only when there clearly isn't room below but there is above —
    // e.g. the last message sitting near the viewport bottom. getBoundingClientRect
    // returns zeros under jsdom, which keeps the default 'bottom'.
    useLayoutEffect(() => {
        if (!open || !wrapRef.current) {
            return;
        }
        const rect = wrapRef.current.getBoundingClientRect();
        const estimatedHeight = 170;
        // Measure available space against the scrollable thread container,
        // not the window: the popover is position:absolute inside
        // [data-testid="chat-thread"] (overflow:auto), so it's clipped at
        // the thread's edges — not the viewport's, which sits below the
        // composer + suggested-followups. Falling back to the window covers
        // jsdom (zero rects) and any host without the thread testid.
        const scroller = wrapRef.current.closest('[data-testid="chat-thread"]');
        const bounds = scroller?.getBoundingClientRect();
        const bottomEdge = bounds ? bounds.bottom : window.innerHeight;
        const topEdge = bounds ? bounds.top : 0;
        const spaceBelow = bottomEdge - rect.bottom;
        const spaceAbove = rect.top - topEdge;
        setPlacement(spaceBelow < estimatedHeight && spaceAbove > spaceBelow ? 'top' : 'bottom');
    }, [open]);

    return (
        <span
            ref={wrapRef}
            style={{ position: 'relative', display: 'inline-flex', maxWidth: '100%' }}
            onMouseEnter={() => {
                setHovered(true);
                onOpenChange(true);
            }}
            onMouseLeave={() => {
                setHovered(false);
                // Close only when focus isn't also keeping it open. Guard on
                // `open` so leaving THIS chip never dismisses another chip's
                // popover (the parent tracks a single open index).
                if (!focused && open) {
                    onOpenChange(false);
                }
            }}
            onFocus={() => {
                setFocused(true);
                onOpenChange(true);
            }}
            onBlur={() => {
                setFocused(false);
                if (!hovered && open) {
                    onOpenChange(false);
                }
            }}
            onKeyDown={(e) => {
                if (e.key === 'Escape' && open) {
                    setHovered(false);
                    setFocused(false);
                    onOpenChange(false);
                }
            }}
        >
            <button
                type="button"
                data-testid={`chat-citation-${index}`}
                data-origin={origin}
                data-tier={citation.generation_source ?? 'human'}
                data-openable={canOpen ? 'true' : 'false'}
                aria-label={
                    canOpen
                        ? `Open source ${index + 1}: ${ariaTarget}`
                        : `Citation ${index + 1}: ${ariaTarget}`
                }
                aria-describedby={open ? popoverId : undefined}
                title={citation.source_path ?? citation.title}
                onClick={canOpen ? () => onOpenSource?.(citation) : undefined}
                style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 6,
                    maxWidth: 300,
                    padding: '4px 10px 4px 4px',
                    background: 'var(--bg-2)',
                    border: '1px solid var(--panel-border)',
                    borderRadius: 99,
                    cursor: canOpen ? 'pointer' : 'default',
                    color: 'var(--fg-1)',
                    fontSize: 11.5,
                    transition: 'border-color .12s ease, background .12s ease',
                }}
            >
                <span
                    aria-hidden="true"
                    style={{
                        flex: '0 0 auto',
                        width: 18,
                        height: 18,
                        borderRadius: 99,
                        background: palette.badge,
                        display: 'inline-flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        fontSize: 10,
                        fontWeight: 600,
                        color: palette.badgeFg,
                        fontFamily: 'var(--font-mono)',
                    }}
                >
                    {index + 1}
                </span>
                <span className="mono" style={{ ...ELLIPSIS, flex: '0 1 auto', fontSize: 11 }}>
                    {label}
                </span>
                {citation.generation_source === 'auto' && (
                    <span
                        data-testid={`chat-citation-${index}-tier`}
                        title="Auto-compiled page (ranks below human-vouched content)"
                        style={{
                            flex: '0 0 auto',
                            padding: '0 6px',
                            fontSize: 9.5,
                            lineHeight: '15px',
                            borderRadius: 99,
                            background: 'var(--bg-3)',
                            border: '1px solid var(--warn, #d29922)',
                            color: 'var(--warn, #d29922)',
                            textTransform: 'uppercase',
                            letterSpacing: '.05em',
                            fontFamily: 'var(--font-mono)',
                        }}
                    >
                        auto
                    </span>
                )}
                {heading && (
                    <>
                        <span aria-hidden="true" style={{ flex: '0 0 auto', color: 'var(--fg-4)' }}>
                            ·
                        </span>
                        <span
                            style={{ ...ELLIPSIS, flex: '0 1 auto', maxWidth: 130, color: 'var(--fg-3)' }}
                        >
                            §{heading}
                        </span>
                    </>
                )}
            </button>
            {open && (
                <span
                    id={popoverId}
                    role="tooltip"
                    data-testid="chat-citations-popover"
                    data-state="open"
                    data-origin={origin}
                    data-placement={placement}
                    className="panel popin"
                    style={{
                        position: 'absolute',
                        [placement === 'bottom' ? 'top' : 'bottom']: 'calc(100% + 8px)',
                        left: 0,
                        zIndex: 40,
                        width: 360,
                        maxWidth: '80vw',
                        padding: 14,
                        fontSize: 12,
                        background: 'var(--panel-solid)',
                        boxShadow: 'var(--shadow-lg)',
                        border: '1px solid var(--panel-border-strong)',
                        borderRadius: 10,
                    }}
                >
                    {/* Caret: a rotated square showing two borders, points at the chip. */}
                    <span
                        aria-hidden="true"
                        style={{
                            position: 'absolute',
                            [placement === 'bottom' ? 'top' : 'bottom']: -5,
                            left: 16,
                            width: 9,
                            height: 9,
                            background: 'var(--panel-solid)',
                            borderLeft: '1px solid var(--panel-border-strong)',
                            borderTop: '1px solid var(--panel-border-strong)',
                            transform: placement === 'bottom' ? 'rotate(45deg)' : 'rotate(225deg)',
                        }}
                    />
                    <div style={{ display: 'flex', gap: 8, alignItems: 'flex-start', marginBottom: 8 }}>
                        <span
                            className="pill"
                            style={{
                                flex: '0 0 auto',
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
                        {citation.source_path && (
                            <span
                                className="mono"
                                style={{
                                    minWidth: 0,
                                    fontSize: 10.5,
                                    color: 'var(--fg-2)',
                                    wordBreak: 'break-all',
                                }}
                            >
                                {citation.source_path}
                            </span>
                        )}
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
                        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 2 }}>
                            {citation.headings.slice(0, 3).map((h, i) => (
                                <span
                                    key={i}
                                    className="mono"
                                    style={{ fontSize: 10.5, color: 'var(--fg-3)', wordBreak: 'break-word' }}
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
