import type { CSSProperties } from 'react';

/*
 * Shared inline-style helpers for the API Connector admin surface. Mirrors the
 * existing connectors feature (custom CSS via `var(--...)` tokens, NOT shadcn).
 * Centralised here so every form/panel/modal in the feature stays visually
 * consistent without re-declaring the same literals.
 */

export function modalBackdropStyle(): CSSProperties {
    return {
        position: 'fixed',
        inset: 0,
        background: 'rgba(0,0,0,.4)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 100,
        padding: 16,
        overflow: 'auto',
    };
}

export function modalPanelStyle(maxWidth = 520): CSSProperties {
    return {
        background: 'var(--panel-solid, #1a1a22)',
        border: '1px solid var(--panel-border-strong, rgba(255,255,255,.12))',
        borderRadius: 12,
        boxShadow: 'var(--shadow, 0 8px 24px rgba(0,0,0,.4))',
        minWidth: 360,
        maxWidth,
        width: '100%',
        maxHeight: '90vh',
        overflow: 'auto',
        padding: 16,
        display: 'flex',
        flexDirection: 'column',
        gap: 12,
    };
}

export function inputStyle(): CSSProperties {
    return {
        padding: '5px 8px',
        borderRadius: 6,
        border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
        background: 'var(--bg-3, rgba(255,255,255,.04))',
        color: 'var(--fg-0)',
        fontSize: 12,
        width: '100%',
        boxSizing: 'border-box',
    };
}

export function fieldLabelStyle(): CSSProperties {
    return { display: 'flex', flexDirection: 'column', gap: 4 };
}

export function fieldCaptionStyle(): CSSProperties {
    return { color: 'var(--fg-2)', fontSize: 11 };
}

export function errorTextStyle(): CSSProperties {
    return { fontSize: 10.5, color: 'var(--err, #fca5a5)' };
}

export function buttonStyle(
    variant: 'primary' | 'secondary' | 'danger',
    disabled: boolean,
): CSSProperties {
    const accent =
        variant === 'danger' ? 'var(--err, #ef4444)' : 'var(--accent, #6366f1)';
    const isFilled = variant === 'primary' || variant === 'danger';
    return {
        padding: '5px 14px',
        borderRadius: 6,
        border: '1px solid ' + (isFilled ? accent : 'var(--panel-border, rgba(255,255,255,.15))'),
        background: isFilled ? accent : 'transparent',
        color: isFilled ? 'white' : 'var(--fg-1)',
        fontSize: 11.5,
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.6 : 1,
    };
}
