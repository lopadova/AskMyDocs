import { type ReactNode } from 'react';

/**
 * T3.6 — Compact grounding-confidence badge for assistant turns.
 *
 * Three numeric tiers + one refusal state:
 *   high     ≥ 80   green
 *   moderate ≥ 50   yellow
 *   low      < 50   red
 *   refused  any    grey (when refusalReason is present, score is ignored)
 *
 * R11 — `data-testid="confidence-badge"` + `data-state` on the
 * focusable element. R15 — `aria-label` describes the score + tier so
 * a screen-reader user gets the full meaning ("Confidence 87 of 100,
 * high"); the visible label is just "87/100".
 *
 * Refusal beats score: a refusal_reason of any kind forces the
 * "refused" state regardless of the confidence value (which is 0 on
 * refusal payloads anyway, but the explicit guard means a future
 * non-zero refusal couldn't accidentally render as "low").
 *
 * The tier thresholds are duplicated here (UI presentation) and on the
 * BE (`ConfidenceCalculator`'s composite formula) — this is fine: the
 * BE decides what number to assign, the FE decides how to colour it.
 * Tweaking the band cutoffs is a UI judgement call, not a contract change.
 */

export type ConfidenceTier = 'high' | 'moderate' | 'low' | 'refused';

export interface ConfidenceBadgeProps {
    /** Composite score from the BE (0..100). May be null on legacy rows. */
    confidence: number | null | undefined;
    /** Refusal taxonomy tag — when present, forces tier='refused'. */
    refusalReason?: string | null;
}

export function ConfidenceBadge({ confidence, refusalReason }: ConfidenceBadgeProps): ReactNode {
    // Legacy rows (pre-v3.0) carry no confidence — render nothing.
    // Don't fake a tier — the absence of data is itself information.
    if (refusalReason == null && (confidence == null || Number.isNaN(confidence))) {
        return null;
    }

    const tier: ConfidenceTier = refusalReason != null
        ? 'refused'
        : (confidence ?? 0) >= 80
            ? 'high'
            : (confidence ?? 0) >= 50
                ? 'moderate'
                : 'low';

    const palette = TIER_PALETTE[tier];
    const label = tier === 'refused'
        ? 'No grounded answer'
        : `${Math.round(confidence ?? 0)}/100`;
    const ariaLabel = tier === 'refused'
        ? 'Answer refused — no grounded context available'
        : `Confidence ${Math.round(confidence ?? 0)} of 100, ${tier}`;

    return (
        <span
            data-testid="confidence-badge"
            data-state={tier}
            role="status"
            aria-label={ariaLabel}
            style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: 4,
                padding: '2px 7px',
                fontSize: 10.5,
                fontFamily: 'var(--font-mono, monospace)',
                lineHeight: 1.4,
                color: palette.fg,
                background: palette.bg,
                border: `1px solid ${palette.border}`,
                borderRadius: 99,
                whiteSpace: 'nowrap',
            }}
        >
            <span aria-hidden="true">{TIER_GLYPH[tier]}</span>
            {label}
        </span>
    );
}

const TIER_PALETTE: Record<ConfidenceTier, { fg: string; bg: string; border: string }> = {
    // CSS vars chosen to match the existing chat colour-tokens; falls
    // back to safe hex equivalents if a theme doesn't define them.
    high: {
        fg: 'var(--ok, #1f8348)',
        bg: 'var(--ok-bg, rgba(31,131,72,.12))',
        border: 'var(--ok-border, rgba(31,131,72,.35))',
    },
    moderate: {
        fg: 'var(--warn, #b88a1a)',
        bg: 'var(--warn-bg, rgba(184,138,26,.12))',
        border: 'var(--warn-border, rgba(184,138,26,.35))',
    },
    low: {
        fg: 'var(--err, #c4391d)',
        bg: 'var(--err-bg, rgba(196,57,29,.12))',
        border: 'var(--err-border, rgba(196,57,29,.35))',
    },
    refused: {
        fg: 'var(--fg-2, #6b6b76)',
        bg: 'var(--bg-3, rgba(120,120,135,.12))',
        border: 'var(--panel-border, rgba(120,120,135,.35))',
    },
};

const TIER_GLYPH: Record<ConfidenceTier, string> = {
    high: '●',
    moderate: '●',
    low: '●',
    refused: '○',
};
