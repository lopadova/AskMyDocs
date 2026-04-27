import { type ReactNode } from 'react';

/**
 * T3.7 — neutral-toned info notice rendered in place of an answer body
 * when the BE emitted a refusal payload.
 *
 * Visual contract (plan §2836): NOT red/destructive. Refusal is a
 * QUALITY signal — "we deliberately didn't make something up" — not an
 * error the user should panic about. Info icon + neutral colours +
 * `role="status"` + `aria-live="polite"` so a screen reader announces
 * the refusal as it lands without interrupting other narration.
 *
 * The body string itself is rendered by the caller (MessageBubble) from
 * the BE-localized `message.content` — see L22 — so we don't duplicate
 * the translation surface here. The component just wraps the rendered
 * body with the right framing + appends a generic remediation hint
 * (the plan §2842 helper text).
 *
 * Two refusal reasons surface from the BE:
 *   - 'no_relevant_context'  — retrieval came up empty (T3.3)
 *   - 'llm_self_refusal'     — LLM emitted the sentinel (T3.4)
 *
 * The reason is exposed as `data-reason` so Playwright can assert which
 * path triggered without parsing the body.
 *
 * R11 — `data-testid="refusal-notice"`, `data-reason` for the tag.
 * R15 — `role="status"` on the wrapper (focusable element through
 * keyboard nav); semantic `<p>` for the body so AT renders it as a
 * paragraph, not a div.
 */

export interface RefusalNoticeProps {
    /** BE-localized message body (e.g. "No documents in the knowledge base match this question."). */
    body: string;
    /** Refusal taxonomy tag from the BE — stays English regardless of locale. */
    reason: string;
}

const HINT_BY_REASON: Record<string, string> = {
    no_relevant_context: 'Try refining your question, broadening filters, or adding more documents.',
    llm_self_refusal: 'Try rephrasing the question or providing more context.',
};

const FALLBACK_HINT = 'Try refining your question or providing more context.';

export function RefusalNotice({ body, reason }: RefusalNoticeProps): ReactNode {
    const hint = HINT_BY_REASON[reason] ?? FALLBACK_HINT;

    return (
        <div
            data-testid="refusal-notice"
            data-reason={reason}
            role="status"
            aria-live="polite"
            style={{
                display: 'flex',
                gap: 10,
                padding: '12px 14px',
                background: 'var(--bg-3, rgba(120,120,135,.08))',
                border: '1px solid var(--panel-border, rgba(120,120,135,.30))',
                borderRadius: 10,
                fontSize: 13,
                color: 'var(--fg-1)',
                lineHeight: 1.55,
            }}
        >
            <span
                aria-hidden="true"
                style={{
                    flex: '0 0 auto',
                    width: 20,
                    height: 20,
                    borderRadius: '50%',
                    background: 'var(--bg-2, rgba(120,120,135,.18))',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    color: 'var(--fg-2)',
                    marginTop: 1,
                }}
            >
                {/*
                  * Inline info-style glyph (lowercase 'i' in a circle).
                  * Inline rather than registered in the shared Icon set
                  * because the only consumer is this component — adding
                  * to the shared set would need its own test surface.
                  */}
                <svg
                    width={12}
                    height={12}
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth={2}
                    strokeLinecap="round"
                    strokeLinejoin="round"
                >
                    <circle cx="12" cy="12" r="10" />
                    <path d="M12 11v5" />
                    <path d="M12 7.5v.5" />
                </svg>
            </span>
            <div style={{ flex: 1, minWidth: 0 }}>
                <p
                    data-testid="refusal-notice-body"
                    style={{ margin: 0, color: 'var(--fg-0)' }}
                >
                    {body}
                </p>
                <p
                    data-testid="refusal-notice-hint"
                    style={{
                        margin: '6px 0 0',
                        fontSize: 12,
                        color: 'var(--fg-2)',
                    }}
                >
                    {hint}
                </p>
            </div>
        </div>
    );
}
