import type { ReactNode } from 'react';
import { Icon, type IconName } from '../Icons';

export type PlaceholderProps = {
    icon: IconName;
    title: string;
    phase: string;
    description: ReactNode;
};

export function Placeholder({ icon, title, phase, description }: PlaceholderProps) {
    const IconCmp = Icon[icon];
    return (
        <div
            style={{
                flex: 1,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                padding: 40,
                color: 'var(--fg-1)',
            }}
        >
            <div
                className="panel popin"
                style={{
                    maxWidth: 520,
                    padding: '28px 28px 26px',
                    textAlign: 'center',
                }}
            >
                <div
                    style={{
                        width: 48,
                        height: 48,
                        borderRadius: 12,
                        background: 'var(--grad-accent-soft)',
                        border: '1px solid rgba(139,92,246,.3)',
                        display: 'inline-flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        margin: '0 auto 14px',
                    }}
                >
                    <IconCmp size={22} />
                </div>
                <h2 style={{ fontSize: 20, fontWeight: 600, margin: '0 0 4px', letterSpacing: '-0.01em' }}>{title}</h2>
                <div
                    className="pill accent"
                    style={{ marginBottom: 12 }}
                >
                    Coming in {phase}
                </div>
                <p style={{ fontSize: 13.5, color: 'var(--fg-2)', margin: 0, lineHeight: 1.55 }}>{description}</p>
            </div>
        </div>
    );
}
