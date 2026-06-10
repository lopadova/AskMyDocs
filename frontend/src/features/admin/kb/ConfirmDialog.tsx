/*
 * Shared confirmation modal for destructive KB actions.
 *
 * Extracted from DocumentDetail so the explorer's bulk toolbar reuses
 * the exact same dialog. `testIdPrefix` keeps the existing
 * `kb-detail-confirm*` testids byte-identical for the live Playwright
 * specs while the explorer passes `kb-explorer-confirm`.
 *
 * The dialog is intentionally content-agnostic: callers pass the
 * rendered `title` + `body` so a single-doc delete, a bulk delete, and
 * a bulk restore can all reuse it. `tone` only drives the confirm
 * button's colour + default label.
 */

export type ConfirmTone = 'danger' | 'neutral';

export function ConfirmDialog({
    title,
    body,
    confirmLabel,
    tone = 'danger',
    isSubmitting,
    onCancel,
    onConfirm,
    testIdPrefix = 'kb-detail-confirm',
    dataAttrs,
}: {
    title: string;
    body: string;
    confirmLabel: string;
    tone?: ConfirmTone;
    isSubmitting: boolean;
    onCancel: () => void;
    onConfirm: () => void;
    testIdPrefix?: string;
    /** Extra data-* attributes on the root (e.g. data-mode for the detail pane). */
    dataAttrs?: Record<string, string>;
}) {
    const danger = tone === 'danger';
    return (
        <div
            data-testid={testIdPrefix}
            {...dataAttrs}
            style={{
                position: 'fixed',
                inset: 0,
                background: 'rgba(0, 0, 0, 0.4)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                zIndex: 500,
            }}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-label={title}
                style={{
                    background: 'var(--bg-0)',
                    border: '1px solid var(--hairline)',
                    borderRadius: 10,
                    padding: 16,
                    width: 420,
                    maxWidth: '90vw',
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 10,
                }}
            >
                <h3 style={{ margin: 0, fontSize: 15, color: 'var(--fg-0)' }}>{title}</h3>
                <p style={{ margin: 0, fontSize: 12.5, color: 'var(--fg-2)', lineHeight: 1.45 }}>{body}</p>
                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 4 }}>
                    <button
                        type="button"
                        data-testid={testIdPrefix + '-cancel'}
                        onClick={onCancel}
                        disabled={isSubmitting}
                        style={{
                            padding: '6px 12px',
                            fontSize: 12,
                            border: '1px solid var(--hairline)',
                            background: 'var(--bg-1)',
                            color: 'var(--fg-1)',
                            borderRadius: 6,
                            cursor: 'pointer',
                        }}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        data-testid={testIdPrefix + '-submit'}
                        onClick={onConfirm}
                        disabled={isSubmitting}
                        style={{
                            padding: '6px 12px',
                            fontSize: 12,
                            border: danger
                                ? '1px solid var(--danger-fg, #b91c1c)'
                                : '1px solid var(--hairline)',
                            background: danger
                                ? 'var(--danger-soft, rgba(220, 38, 38, 0.12))'
                                : 'var(--bg-1)',
                            color: danger ? 'var(--danger-fg, #b91c1c)' : 'var(--fg-1)',
                            borderRadius: 6,
                            cursor: isSubmitting ? 'not-allowed' : 'pointer',
                            opacity: isSubmitting ? 0.6 : 1,
                        }}
                    >
                        {isSubmitting ? 'Working…' : confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}
