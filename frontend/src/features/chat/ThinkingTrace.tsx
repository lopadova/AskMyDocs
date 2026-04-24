import { useState, type ReactNode } from 'react';
import { Icon } from '../../components/Icons';

export interface ThinkingTraceProps {
    steps: string[];
    elapsedSeconds?: number;
}

/**
 * Collapsible reasoning trace. Matches the Claude Design chat
 * reference — a pill with a Brain icon + step count + optional
 * elapsed time; click expands a mono panel listing each step.
 */
export function ThinkingTrace({ steps, elapsedSeconds }: ThinkingTraceProps): ReactNode {
    const [open, setOpen] = useState(false);
    if (!steps || steps.length === 0) {
        return null;
    }
    return (
        <div data-testid="chat-thinking-trace" data-state={open ? 'open' : 'closed'} style={{ marginBottom: 12 }}>
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                data-testid="chat-thinking-trace-toggle"
                aria-expanded={open}
                aria-controls="chat-thinking-trace-panel"
                style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 8,
                    padding: '5px 10px',
                    background: 'var(--bg-2)',
                    border: '1px solid var(--panel-border)',
                    borderRadius: 99,
                    color: 'var(--fg-2)',
                    fontSize: 11.5,
                    fontFamily: 'var(--font-mono)',
                    cursor: 'pointer',
                }}
            >
                <Icon.Brain size={12} />
                Thinking · {steps.length} steps
                {elapsedSeconds !== undefined && ` · ${elapsedSeconds.toFixed(1)}s`}
                <Icon.Chevron
                    size={11}
                    style={{ transform: open ? 'rotate(90deg)' : 'none', transition: 'transform .15s' }}
                />
            </button>
            {open && (
                <div
                    id="chat-thinking-trace-panel"
                    className="popin"
                    style={{
                        marginTop: 8,
                        padding: '10px 12px',
                        background: 'var(--bg-2)',
                        border: '1px solid var(--panel-border)',
                        borderRadius: 8,
                        fontSize: 12,
                        color: 'var(--fg-2)',
                        lineHeight: 1.6,
                    }}
                >
                    {steps.map((s, i) => (
                        <div key={i} style={{ display: 'flex', gap: 10, padding: '3px 0' }}>
                            <span className="mono" style={{ color: 'var(--fg-3)', fontSize: 10.5, minWidth: 14 }}>
                                {String(i + 1).padStart(2, '0')}
                            </span>
                            <span>{s}</span>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
