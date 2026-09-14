import { useCallback, useEffect, useState } from 'react';
import { api } from '../../../lib/api';
import { Button } from '../../../components/Button';
import { McpAppFrame, type McpAppHandle } from './McpAppFrame';
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

export type ToolCallStatus = 'pending' | 'ok' | 'error' | 'timeout' | 'denied' | 'confirmation_required' | 'input_required' | 'task_accepted' | 'cancel_requested' | 'cancelled';

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
    task_id?: string | null;
    task?: Record<string, unknown> | null;
    app?: McpAppHandle | null;
}

interface RemoteTaskResponse {
    task_id: string;
    status: 'working' | 'input_required' | 'completed' | 'failed' | 'cancelled' | 'expired';
    status_message?: string | null;
    poll_interval_ms?: number;
    input_requests?: Record<string, unknown> | null;
    artifact?: unknown;
    error?: { message?: string } | null;
    cancel_requested?: boolean;
    terminal?: boolean;
}

interface ToolCallBubbleProps {
    toolCall: ToolCallData;
    conversationId?: number;
    onMcpAppMessage?: (content: string, appId: string) => Promise<void>;
}

export function ToolCallBubble({ toolCall, conversationId, onMcpAppMessage }: ToolCallBubbleProps) {
    const [expanded, setExpanded] = useState(false);
    const [interactionStatus, setInteractionStatus] = useState<ToolCallStatus | null>(null);
    const [interactionResult, setInteractionResult] = useState<unknown>(null);
    const [interactionError, setInteractionError] = useState<string | null>(null);
    const [inputJson, setInputJson] = useState('{}');
    const [submitting, setSubmitting] = useState(false);
    const [activeTaskId, setActiveTaskId] = useState(toolCall.task_id ?? null);
    const [taskInputRequests, setTaskInputRequests] = useState<Record<string, unknown> | null>(
        (toolCall.task?.input_requests as Record<string, unknown> | undefined) ?? null,
    );
    const [taskStatusMessage, setTaskStatusMessage] = useState<string | null>(null);
    const [pollRevision, setPollRevision] = useState(0);
    const [interactionApp, setInteractionApp] = useState<McpAppHandle | null>(null);

    const effectiveStatus = interactionStatus ?? toolCall.status;
    const palette = paletteForStatus(effectiveStatus);
    const stateLabel = labelForStatus(effectiveStatus);
    const requiresLocalInteraction = interactionStatus === null
        && (toolCall.status === 'confirmation_required' || toolCall.status === 'input_required')
        && toolCall.pending_interaction_id
        && conversationId !== undefined;
    const requiresTaskInput = effectiveStatus === 'input_required'
        && activeTaskId !== null
        && conversationId !== undefined;

    const applyTaskResponse = useCallback((data: RemoteTaskResponse): boolean => {
        setTaskStatusMessage(data.status_message ?? null);
        setTaskInputRequests(data.input_requests ?? null);
        if (data.status === 'completed') {
            setInteractionResult(data.artifact ?? data);
            setInteractionApp(readAppHandle(data.artifact));
            setInteractionStatus('ok');
            setExpanded(true);
            return false;
        }
        if (data.status === 'failed' || data.status === 'expired') {
            setInteractionStatus('error');
            setInteractionError(data.error?.message ?? (data.status === 'expired' ? 'The MCP task expired.' : 'The MCP task failed.'));
            setExpanded(true);
            return false;
        }
        if (data.status === 'cancelled') {
            setInteractionStatus('cancelled');
            setExpanded(true);
            return false;
        }
        if (data.status === 'input_required') {
            setInteractionStatus('input_required');
            return false;
        }
        setInteractionStatus(data.cancel_requested ? 'cancel_requested' : 'task_accepted');
        return true;
    }, []);

    useEffect(() => {
        if (activeTaskId === null || conversationId === undefined) return;
        const taskId = activeTaskId;
        const scopedConversationId = conversationId;
        let disposed = false;
        let timer: ReturnType<typeof setTimeout> | undefined;

        async function poll() {
            try {
                const { data } = await api.get<RemoteTaskResponse>(
                    `/api/conversations/mcp/tasks/${taskId}`,
                    { params: { conversation_id: String(scopedConversationId) } },
                );
                if (disposed) return;
                const keepPolling = applyTaskResponse(data);
                if (keepPolling) {
                    const delay = Math.max(250, Math.min(data.poll_interval_ms ?? 1000, 30_000));
                    timer = setTimeout(() => void poll(), delay);
                }
            } catch (cause) {
                if (disposed) return;
                const message = (cause as { response?: { data?: { message?: string; error?: string } }; message?: string })
                    ?.response?.data;
                setInteractionError(message?.message ?? message?.error ?? 'Could not refresh this MCP task.');
                timer = setTimeout(() => void poll(), 5000);
            }
        }

        void poll();
        return () => {
            disposed = true;
            if (timer !== undefined) clearTimeout(timer);
        };
    }, [activeTaskId, applyTaskResponse, conversationId, pollRevision]);

    async function respond(response: Record<string, unknown>) {
        if (!toolCall.pending_interaction_id || conversationId === undefined) return;
        setSubmitting(true);
        setInteractionError(null);
        try {
            const { data } = await api.post<{ status: string; artifact?: unknown; task_id?: string; task?: RemoteTaskResponse }>(
                `/api/conversations/mcp/interactions/${toolCall.pending_interaction_id}`,
                { conversation_id: String(conversationId), response },
            );
            if (data.status === 'task_accepted' && data.task_id) {
                setActiveTaskId(data.task_id);
                setInteractionResult(data.task ?? data);
                setInteractionStatus('task_accepted');
                setExpanded(true);
                return;
            }
            setInteractionResult(data.artifact ?? data);
            setInteractionApp(readAppHandle(data.artifact));
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

    async function submitTaskInput() {
        if (activeTaskId === null || conversationId === undefined) return;
        try {
            const parsed: unknown = JSON.parse(inputJson);
            if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
                throw new Error('Input responses must be a JSON object.');
            }
            setSubmitting(true);
            setInteractionError(null);
            const { data } = await api.post<RemoteTaskResponse>(
                `/api/conversations/mcp/tasks/${activeTaskId}/input`,
                { conversation_id: String(conversationId), input_responses: parsed },
            );
            if (applyTaskResponse(data)) {
                setPollRevision((value) => value + 1);
            }
        } catch (cause) {
            const response = (cause as { response?: { data?: { message?: string; error?: string } }; message?: string })
                ?.response?.data;
            setInteractionError(response?.message ?? response?.error ?? (cause instanceof Error ? cause.message : 'Could not update this MCP task.'));
        } finally {
            setSubmitting(false);
        }
    }

    async function cancelTask() {
        if (activeTaskId === null || conversationId === undefined) return;
        setSubmitting(true);
        setInteractionError(null);
        try {
            const { data } = await api.post<RemoteTaskResponse>(
                `/api/conversations/mcp/tasks/${activeTaskId}/cancel`,
                { conversation_id: String(conversationId) },
            );
            if (applyTaskResponse(data)) {
                setPollRevision((value) => value + 1);
            }
        } catch (cause) {
            const response = (cause as { response?: { data?: { message?: string; error?: string } }; message?: string })
                ?.response?.data;
            setInteractionError(response?.message ?? response?.error ?? 'Could not cancel this MCP task.');
        } finally {
            setSubmitting(false);
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

            {(requiresLocalInteraction || requiresTaskInput) && (
                <div data-testid={`chat-tool-call-${toolCall.id}-interaction`} style={{ marginTop: 10, display: 'grid', gap: 8 }}>
                    {typeof toolCall.prompt?.message === 'string' && (
                        <div style={{ color: 'var(--fg-1)' }}>{toolCall.prompt.message}</div>
                    )}
                    {taskStatusMessage ? <div style={{ color: 'var(--fg-1)' }}>{taskStatusMessage}</div> : null}
                    {requiresLocalInteraction && toolCall.status === 'confirmation_required' ? (
                        <div style={{ display: 'flex', gap: 8 }}>
                            <Button variant="primary" size="sm" busy={submitting} data-testid={`chat-tool-call-${toolCall.id}-confirm`} onClick={() => void respond({ confirmed: true })}>Confirm</Button>
                            <Button variant="secondary" size="sm" disabled={submitting} data-testid={`chat-tool-call-${toolCall.id}-decline`} onClick={() => void respond({ confirmed: false })}>Decline</Button>
                        </div>
                    ) : (
                        <>
                            {(taskInputRequests ?? toolCall.prompt?.inputRequests) && <ToolResultPreview value={taskInputRequests ?? toolCall.prompt?.inputRequests} />}
                            <textarea aria-label="MCP input responses" value={inputJson} onChange={(event) => setInputJson(event.target.value)} rows={4} style={interactionInputStyle} />
                            <Button variant="primary" size="sm" busy={submitting} data-testid={`chat-tool-call-${toolCall.id}-send-input`} onClick={requiresTaskInput ? submitTaskInput : submitInput}>Send input</Button>
                        </>
                    )}
                    {interactionError && <div role="alert" style={{ color: 'var(--danger-fg)' }}>{interactionError}</div>}
                </div>
            )}

            {activeTaskId !== null && (effectiveStatus === 'task_accepted' || effectiveStatus === 'cancel_requested') ? (
                <div style={{ marginTop: 10, display: 'flex', alignItems: 'center', gap: 8 }}>
                    {taskStatusMessage ? <span>{taskStatusMessage}</span> : null}
                    <Button variant="secondary" size="sm" busy={submitting} disabled={effectiveStatus === 'cancel_requested'} data-testid={`chat-tool-call-${toolCall.id}-cancel-task`} onClick={() => void cancelTask()}>
                        {effectiveStatus === 'cancel_requested' ? 'Cancellation requested' : 'Cancel task'}
                    </Button>
                    {interactionError && <div role="alert" style={{ color: 'var(--danger-fg)' }}>{interactionError}</div>}
                </div>
            ) : null}

            {effectiveStatus === 'ok' && conversationId !== undefined && (interactionApp ?? toolCall.app) ? (
                <McpAppFrame
                    app={(interactionApp ?? toolCall.app) as McpAppHandle}
                    conversationId={conversationId}
                    onSendMessage={onMcpAppMessage}
                />
            ) : null}

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
                    {effectiveStatus === 'error' || effectiveStatus === 'timeout' || effectiveStatus === 'denied' || effectiveStatus === 'cancelled' ? (
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
                                {interactionError ?? toolCall.error ?? (effectiveStatus === 'cancelled' ? 'The MCP task was cancelled.' : 'No error message reported.')}
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

function readAppHandle(artifact: unknown): McpAppHandle | null {
    if (!artifact || typeof artifact !== 'object' || Array.isArray(artifact)) return null;
    const app = (artifact as Record<string, unknown>).app;
    if (!app || typeof app !== 'object' || Array.isArray(app)) return null;
    const record = app as Record<string, unknown>;
    if (typeof record.id !== 'string' || record.id.length === 0) return null;

    return {
        id: record.id,
        resource_uri: typeof record.resource_uri === 'string' ? record.resource_uri : undefined,
        fallback: typeof record.fallback === 'string' ? record.fallback : undefined,
    };
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
        case 'cancelled':
            return {
                background: 'rgba(148,163,184,0.10)',
                border: 'rgba(148,163,184,0.32)',
                color: '#cbd5e1',
                iconFill: 'rgba(148,163,184,0.85)',
            };
        case 'confirmation_required':
        case 'input_required':
        case 'cancel_requested':
            return {
                background: 'rgba(245,158,11,0.08)',
                border: 'rgba(245,158,11,0.32)',
                color: '#fde68a',
                iconFill: 'rgba(245,158,11,0.85)',
            };
        case 'pending':
        case 'task_accepted':
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
        case 'task_accepted':
            return 'Running asynchronously';
        case 'cancel_requested':
            return 'Cancellation requested';
        case 'cancelled':
            return 'Cancelled';
        default:
            return status;
    }
}

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
