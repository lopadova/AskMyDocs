import { useState, type ReactNode } from 'react';

export type TooltipProps = {
    children: ReactNode;
    label: string;
    side?: 'top' | 'bottom' | 'left' | 'right';
};

export function Tooltip({ children, label, side = 'bottom' }: TooltipProps) {
    const [open, setOpen] = useState(false);
    const offset = 'calc(100% + 6px)';
    const tooltipPosition: Record<string, string | number> =
        side === 'top'
            ? { bottom: offset, left: '50%', transform: 'translateX(-50%)' }
            : side === 'left'
                ? { right: offset, top: '50%', transform: 'translateY(-50%)' }
                : side === 'right'
                    ? { left: offset, top: '50%', transform: 'translateY(-50%)' }
                    : { top: offset, left: '50%', transform: 'translateX(-50%)' };
    return (
        <span
            style={{ position: 'relative', display: 'inline-flex' }}
            onMouseEnter={() => setOpen(true)}
            onMouseLeave={() => setOpen(false)}
        >
            {children}
            {open && (
                <span
                    role="tooltip"
                    style={{
                        position: 'absolute',
                        ...tooltipPosition,
                        background: 'var(--bg-4)',
                        color: 'var(--fg-0)',
                        fontSize: 11,
                        padding: '5px 8px',
                        borderRadius: 6,
                        whiteSpace: 'nowrap',
                        zIndex: 50,
                        pointerEvents: 'none',
                        border: '1px solid var(--panel-border)',
                        boxShadow: 'var(--shadow)',
                    }}
                >
                    {label}
                </span>
            )}
        </span>
    );
}
