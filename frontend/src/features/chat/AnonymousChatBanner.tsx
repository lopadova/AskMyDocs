import { type ReactNode } from 'react';

/**
 * v8.8.3 — persistent header notice for an anonymous chat session.
 *
 * Anonymous chats are authenticated but NOT saved: no conversation row, no
 * message rows, and only a minimal by-norm `chat_logs` entry (or none). The
 * banner makes that contract visible so the user understands the turn will be
 * lost on refresh and that the server force-redacts PII from the question
 * before it is used for retrieval, sent to the AI model, or logged. (The
 * redaction runs server-side, not in the browser — the copy below avoids
 * implying client-side scrubbing.)
 *
 * Visual contract mirrors {@link RefusalNotice}: neutral (not destructive),
 * info glyph, `role="status"` + `aria-live="polite"` so AT announces it on
 * mount. R11 testid, R15 a11y.
 */
export function AnonymousChatBanner(): ReactNode {
    return (
        <div
            data-testid="anonymous-chat-banner"
            data-state="anonymous"
            role="status"
            aria-live="polite"
            style={{
                display: 'flex',
                gap: 10,
                padding: '10px 14px',
                background: 'var(--bg-3, rgba(120,120,135,.08))',
                border: '1px solid var(--panel-border, rgba(120,120,135,.30))',
                borderRadius: 10,
                fontSize: 12.5,
                color: 'var(--fg-1)',
                lineHeight: 1.5,
                alignItems: 'center',
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
                }}
            >
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
                    <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                    <circle cx="12" cy="12" r="3" />
                    <path d="m2 2 20 20" />
                </svg>
            </span>
            <span data-testid="anonymous-chat-banner-body">
                <strong>Anonymous chat</strong> — this conversation is not saved and is lost on
                refresh. The server still redacts PII from your question before it is used for
                retrieval, sent to the AI model, or logged.
            </span>
        </div>
    );
}
