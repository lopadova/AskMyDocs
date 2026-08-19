import { useState } from 'react';
import { api } from '../../../lib/api';
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

export type ToolCallStatus = 'pending' | 'ok' | 'error' | 'timeout' | 'denied' | 'confirmation_required' | 'input_required';

export interface ToolCallData {
    id: string;
    name: string;
    status: ToolCallStatus;
    server_name?: string | null;
    server_id?: number | null;
    arguments?: Record<string, unknown> | null;
    result?: Record<string, unknown> | null;
    error?: string | null;
    pending_interaction_id?: string | null;
    prompt?: Record<string, unknown> | null;
}

interface ToolCallBubbleProps {
    toolCall: ToolCallData;
    conversationId?: number;
}

export function ToolCallBubble({ toolCall, conversationId }: ToolCallBubbleProps) {
    const [expanded, setExpanded] = useState(false);
    const [interactionStatus, setInteractionStatus] = useState<ToolCallStatus | null>(null);
    const [interactionResult, setInteractionResult] = useState<unknown>(null);
    const [interactionError, setInteractionError] = useState<string | null>(null);
    const [inputJson, setInputJson] = useState('{}');
    const [submitting, setSubmitting] = useState(false);

    const effectiveStatus = interactionStatus ?? toolCall.status;
    const palette = paletteForStatus(effectiveStatus);
    const stateLabel = labelForStatus(effectiveStatus);
    const requiresInteraction = interactionStatus === null
        && (toolCall.status === 'confirmation_required' || toolCall.status === 'input_required')
        && toolCall.pending_interaction_id
        && conversationId !== undefined;

    async function respond(response: Record<string, unknown>) {
        if (!toolCall.pending_interaction_id || conversationId === undefined) return;
        setSubmitting(true);
        setInteractionError(null);
        try {
            const { data } = await api.post<{ status: string; artifact?: unknown }>(
                `/api/conversations/mcp/interactions/${toolCall.pending_interaction_id}`,
                { conversation_id: String(conversationId), response },
            );
            setInteractionResult(data.artifact ?? data);
            setInteractionStatus(data.status === 'declined' ? 'denied' : data.status === 'error' ? 'error' : 'ok');
            setExpanded(true);
        } catch (cause) {
            const message = (cause as { response?: { data?: { message?: string; error?: string } }; message?: string })
                ?.response?.data;
            setInteractionError(message?.message ?? message?.error ?? 'Could not resume this MCP tool call.');
        } finally {
            setSubmitting(false);
        }
    }

    function submitInput() {
        try {
            const parsed: unknown = JSON.parse(inputJson);
            if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
                throw new Error('Input responses must be a JSON object.');
            }
            void respond(parsed as Record<string, unknown>);
        } catch (cause) {
            setInteractionError(cause instanceof Error ? cause.message : 'Invalid JSON input.');
        }
    }

    return (
        <div
            data-testid={`chat-tool-call-${toolCall.id}`}
            data-tool-name={toolCall.name}
            data-tool-status={effectiveStatus}
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
                <StatusIcon status={effectiveStatus} />
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

            {requiresInteraction && (
                <div data-testid={`chat-tool-call-${toolCall.id}-interaction`} style={{ marginTop: 10, display: 'grid', gap: 8 }}>
                    {typeof toolCall.prompt?.message === 'string' && (
                        <div style={{ color: 'var(--fg-1)' }}>{toolCall.prompt.message}</div>
                    )}
                    {toolCall.status === 'confirmation_required' ? (
                        <div style={{ display: 'flex', gap: 8 }}>
                            <button type="button" disabled={submitting} onClick={() => void respond({ confirmed: true })} style={interactionButtonStyle}>Confirm</button>
                            <button type="button" disabled={submitting} onClick={() => void respond({ confirmed: false })} style={interactionButtonStyle}>Decline</button>
                        </div>
                    ) : (
                        <>
                            {toolCall.prompt?.inputRequests && <ToolResultPreview value={toolCall.prompt.inputRequests} />}
                            <textarea aria-label="MCP input responses" value={inputJson} onChange={(event) => setInputJson(event.target.value)} rows={4} style={interactionInputStyle} />
                            <button type="button" disabled={submitting} onClick={submitInput} style={interactionButtonStyle}>Send input</button>
                        </>
                    )}
                    {interactionError && <div role="alert" style={{ color: 'var(--danger-fg)' }}>{interactionError}</div>}
                </div>
            )}

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
                    {interactionResult !== null ? (
                        <Section title="Resumed result" testid={`chat-tool-call-${toolCall.id}-resumed-result`}>
                            <ToolResultPreview value={interactionResult} />
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
        case 'confirmation_required':
        case 'input_required':
            return {
                background: 'rgba(245,158,11,0.08)',
                border: 'rgba(245,158,11,0.32)',
                color: '#fde68a',
                iconFill: 'rgba(245,158,11,0.85)',
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
        case 'confirmation_required':
            return 'Confirmation required';
        case 'input_required':
            return 'Input required';
        default:
            return status;
    }
}

const interactionButtonStyle: React.CSSProperties = {
    width: 'fit-content',
    border: '1px solid rgba(245,158,11,.4)',
    borderRadius: 7,
    background: 'rgba(245,158,11,.12)',
    color: 'var(--fg-0)',
    padding: '6px 10px',
    cursor: 'pointer',
    font: 'inherit',
};

const interactionInputStyle: React.CSSProperties = {
    width: '100%',
    boxSizing: 'border-box',
    border: '1px solid var(--border-2)',
    borderRadius: 7,
    background: 'var(--bg-2)',
    color: 'var(--fg-0)',
    padding: 8,
    fontFamily: 'var(--font-mono, ui-monospace)',
    fontSize: 12,
};
