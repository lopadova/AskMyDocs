import { useState } from 'react';
import { ToolResultPreview } from './ToolResultPreview';

/*
 * v5.0/W3 — Renders one MCP tool call inline within an assistant
 * message bubble. State machine:
 *
 *   pending       → spinner + "Calling <tool>…"
 *   ok            → check + tool name + collapsible result
 *   error/timeout → red triangle + message + retry hint
 *   denied        → lock + denial reason
 */

export type ToolCallStatus = 'pending' | 'ok' | 'error' | 'timeout' | 'denied';

export interface ToolCallData {
    id: string;
    name: string;
    status: ToolCallStatus;
    server_name?: string | null;
    server_id?: number | null;
    arguments?: Record<string, unknown> | null;
    result?: Record<string, unknown> | null;
    error?: string | null;
}

interface ToolCallBubbleProps {
    toolCall: ToolCallData;
}

export function ToolCallBubble({ toolCall }: ToolCallBubbleProps) {
    const [expanded, setExpanded] = useState(false);

    const palette = paletteForStatus(toolCall.status);
    const stateLabel = labelForStatus(toolCall.status);

    return (
        <div
            data-testid={`chat-tool-call-${toolCall.id}`}
            data-tool-name={toolCall.name}
            data-tool-status={toolCall.status}
            role="group"
            aria-label={`Tool call ${toolCall.name} — ${stateLabel}`}
            style={{
                border: `1px solid ${palette.border}`,
                background: palette.background,
                borderRadius: 10,
                padding: '8px 12px',
                marginBottom: 10,
                fontSize: 13,
                color: 'var(--fg-1)',
            }}
        >
            <button
                type="button"
                onClick={() => setExpanded((value) => !value)}
                aria-expanded={expanded}
                aria-controls={`chat-tool-call-${toolCall.id}-details`}
                data-testid={`chat-tool-call-${toolCall.id}-toggle`}
                style={{
                    background: 'transparent',
                    border: 0,
                    padding: 0,
                    color: 'inherit',
                    cursor: 'pointer',
                    width: '100%',
                    textAlign: 'left',
                    display: 'flex',
                    alignItems: 'center',
                    gap: 10,
                    font: 'inherit',
                }}
            >
                <StatusIcon status={toolCall.status} />
                <span style={{ fontWeight: 600 }}>{toolCall.name}</span>
                <span
                    aria-hidden="true"
                    style={{
                        color: palette.color,
                        fontWeight: 600,
                        fontSize: 11.5,
                        textTransform: 'uppercase',
                        letterSpacing: '0.04em',
                    }}
                >
                    {stateLabel}
                </span>
                {toolCall.server_name ? (
                    <span style={{ color: 'var(--fg-2)', fontSize: 11.5 }}>
                        via {toolCall.server_name}
                    </span>
                ) : null}
                <span
                    aria-hidden="true"
                    style={{
                        marginLeft: 'auto',
                        color: 'var(--fg-2)',
                        fontSize: 11.5,
                    }}
                >
                    {expanded ? '▾' : '▸'}
                </span>
            </button>

            {expanded ? (
                <div
                    id={`chat-tool-call-${toolCall.id}-details`}
                    data-testid={`chat-tool-call-${toolCall.id}-details`}
                    style={{ marginTop: 8, display: 'flex', flexDirection: 'column', gap: 8 }}
                >
                    {toolCall.arguments && Object.keys(toolCall.arguments).length > 0 ? (
                        <Section title="Arguments" testid={`chat-tool-call-${toolCall.id}-arguments`}>
                            <ToolResultPreview value={toolCall.arguments} />
                        </Section>
                    ) : null}
                    {toolCall.status === 'error' || toolCall.status === 'timeout' || toolCall.status === 'denied' ? (
                        <Section title="Error" testid={`chat-tool-call-${toolCall.id}-error`} tone="error">
                            <pre
                                style={{
                                    margin: 0,
                                    fontFamily: 'var(--font-mono, ui-monospace)',
                                    fontSize: 11.5,
                                    color: 'var(--danger-fg)',
                                    whiteSpace: 'pre-wrap',
                                    wordBreak: 'break-word',
                                }}
                            >
                                {toolCall.error ?? 'No error message reported.'}
                            </pre>
                        </Section>
                    ) : null}
                    {toolCall.result ? (
                        <Section title="Result" testid={`chat-tool-call-${toolCall.id}-result`}>
                            <ToolResultPreview value={toolCall.result} />
                        </Section>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}

function StatusIcon({ status }: { status: ToolCallStatus }) {
    const fillColor = paletteForStatus(status).iconFill;
    const symbol = (() => {
        switch (status) {
            case 'pending':
                return '⏳';
            case 'ok':
                return '✓';
            case 'error':
                return '⚠';
            case 'timeout':
                return '⌛';
            case 'denied':
                return '🔒';
            default:
                return '•';
        }
    })();
    return (
        <span
            aria-hidden="true"
            style={{
                display: 'inline-flex',
                alignItems: 'center',
                justifyContent: 'center',
                width: 18,
                height: 18,
                borderRadius: 999,
                background: fillColor,
                color: '#fff',
                fontSize: 11,
                fontWeight: 700,
            }}
        >
            {symbol}
        </span>
    );
}

interface SectionProps {
    title: string;
    children: React.ReactNode;
    tone?: 'normal' | 'error';
    testid?: string;
}

function Section({ title, children, tone = 'normal', testid }: SectionProps) {
    return (
        <div data-testid={testid}>
            <div
                style={{
                    fontSize: 11.5,
                    color: tone === 'error' ? 'var(--danger-fg)' : 'var(--fg-2)',
                    fontWeight: 600,
                    textTransform: 'uppercase',
                    letterSpacing: '0.04em',
                    marginBottom: 4,
                }}
            >
                {title}
            </div>
            <div
                style={{
                    background: 'var(--bg-2)',
                    border: '1px solid var(--border-2)',
                    borderRadius: 8,
                    padding: '8px 10px',
                }}
            >
                {children}
            </div>
        </div>
    );
}

function paletteForStatus(status: ToolCallStatus) {
    switch (status) {
        case 'ok':
            return {
                background: 'rgba(34,197,94,0.08)',
                border: 'rgba(34,197,94,0.30)',
                color: '#86efac',
                iconFill: 'rgba(34,197,94,0.85)',
            };
        case 'error':
            return {
                background: 'rgba(239,68,68,0.08)',
                border: 'rgba(239,68,68,0.32)',
                color: '#fca5a5',
                iconFill: 'rgba(239,68,68,0.85)',
            };
        case 'timeout':
            return {
                background: 'rgba(245,158,11,0.08)',
                border: 'rgba(245,158,11,0.32)',
                color: '#fde68a',
                iconFill: 'rgba(245,158,11,0.85)',
            };
        case 'denied':
            return {
                background: 'rgba(148,163,184,0.10)',
                border: 'rgba(148,163,184,0.32)',
                color: '#cbd5e1',
                iconFill: 'rgba(148,163,184,0.85)',
            };
        case 'pending':
        default:
            return {
                background: 'rgba(59,130,246,0.08)',
                border: 'rgba(59,130,246,0.32)',
                color: '#93c5fd',
                iconFill: 'rgba(59,130,246,0.85)',
            };
    }
}

function labelForStatus(status: ToolCallStatus): string {
    switch (status) {
        case 'pending':
            return 'Running';
        case 'ok':
            return 'Completed';
        case 'error':
            return 'Failed';
        case 'timeout':
            return 'Timeout';
        case 'denied':
            return 'Denied';
        default:
            return status;
    }
}
