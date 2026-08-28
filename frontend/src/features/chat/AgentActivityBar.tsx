import { useState, type ReactNode } from 'react';
import type { AgentRunEvent } from '../../lib/agent-run-events';
import { Icon } from '../../components/Icons';

export interface AgentActivityBarProps {
    events: AgentRunEvent[];
    active: boolean;
    awaitingConfirmation: boolean;
    onCancel: () => void;
    onContinue: () => void;
}

export function AgentActivityBar({
    events,
    active,
    awaitingConfirmation,
    onCancel,
    onContinue,
}: AgentActivityBarProps): ReactNode {
    if (events.length === 0 && !active && !awaitingConfirmation) return null;
    const latest = events[events.length - 1];
    const locale = latest?.locale?.toLowerCase().startsWith('it') ? 'it' : 'en';
    const copy = locale === 'it'
        ? {
            active: 'Ricerca in corso',
            settled: 'Attività completata',
            confirmation: 'Conferma necessaria',
            fallback: 'L’assistente sta lavorando.',
            details: 'Cronologia attività',
            cancel: 'Annulla',
            proceed: 'Continua la ricerca',
            calls: 'chiamate',
            seconds: 's rimanenti',
            mcpDetails: 'Dettagli chiamata MCP',
            parameters: 'Parametri',
            response: 'Risposta',
            error: 'Errore',
            server: 'Server',
            runtime: 'Runtime',
            copy: 'Copia',
            copied: 'Copiato',
            copyFailed: 'Copia non riuscita',
        }
        : {
            active: 'Search in progress',
            settled: 'Activity completed',
            confirmation: 'Confirmation required',
            fallback: 'The assistant is working.',
            details: 'Activity timeline',
            cancel: 'Cancel',
            proceed: 'Continue search',
            calls: 'calls',
            seconds: 's remaining',
            mcpDetails: 'MCP call details',
            parameters: 'Parameters',
            response: 'Response',
            error: 'Error',
            server: 'Server',
            runtime: 'Runtime',
            copy: 'Copy',
            copied: 'Copied',
            copyFailed: 'Copy failed',
        };
    const progress = latest?.progress;
    const physical = progress?.physical;
    const logical = progress?.logical;
    const metric = physical && physical.estimated.likely > 0 ? physical : logical;
    const completed = metric?.completed ?? 0;
    const likely = Math.max(completed, metric?.estimated.likely ?? 0);
    const percent = likely > 0 ? Math.min(100, Math.round((completed / likely) * 100)) : (active ? 12 : 100);
    const timelineEvents = events.filter((event) => (
        (typeof event.message === 'string' && event.message !== '') || mcpDebugData(event) !== null
    ));
    const state = awaitingConfirmation ? 'confirmation' : active ? 'active' : 'settled';
    const heading = copy[state];

    return (
        <aside
            data-testid="agent-activity-bar"
            data-state={state}
            aria-live="polite"
            aria-busy={active}
            className="agent-activity-card"
        >
            <div className="agent-activity-main">
                <span className="agent-activity-icon" aria-hidden="true">
                    {active ? <Icon.Activity size={15} /> : awaitingConfirmation ? <Icon.Bolt size={15} /> : <Icon.Check size={15} />}
                </span>
                <div className="agent-activity-content">
                    <div className="agent-activity-heading-row">
                        <strong data-testid="agent-activity-heading" className="agent-activity-heading">
                            {heading}
                        </strong>
                        <div className="agent-activity-metrics">
                            {likely > 0 && <span data-testid="agent-activity-calls">{completed} / ~{likely} {copy.calls}</span>}
                            {progress?.eta_ms != null && <span>{Math.ceil(progress.eta_ms / 1000)} {copy.seconds}</span>}
                        </div>
                    </div>
                    <div data-testid="agent-activity-message" className="agent-activity-message">
                        {latest?.message ?? copy.fallback}
                    </div>
                    <div
                        className="agent-activity-progress-track"
                        role="progressbar"
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={percent}
                    >
                        <div
                            data-testid="agent-activity-progress"
                            className="agent-activity-progress-value"
                            style={{ width: `${percent}%` }}
                        />
                    </div>
                </div>
                {active && (
                    <button type="button" className="btn sm ghost" data-testid="agent-activity-cancel" onClick={onCancel}>
                        {copy.cancel}
                    </button>
                )}
                {awaitingConfirmation && (
                    <button type="button" className="btn sm primary" data-testid="agent-activity-continue" onClick={onContinue}>
                        {copy.proceed}
                    </button>
                )}
            </div>
            {(timelineEvents.length > 1 || timelineEvents.some((event) => mcpDebugData(event) !== null)) && (
                <details className="agent-activity-details">
                    <summary>
                        <span>{copy.details}</span>
                        <span className="agent-activity-count">{timelineEvents.length}</span>
                    </summary>
                    <ol>
                        {timelineEvents.map((event) => {
                            const debug = mcpDebugData(event);

                            return (
                                <li key={event.sequence} className={debug ? 'agent-activity-event has-mcp-debug' : undefined}>
                                    {event.message && <span>{event.message}</span>}
                                    {debug && (
                                        <details className="agent-mcp-debug" data-testid={`agent-mcp-debug-${event.sequence}`}>
                                            <summary>
                                                <span className="agent-mcp-debug-title">{copy.mcpDetails}</span>
                                                <span className="agent-mcp-debug-tool">{debug.tool_remote_name}</span>
                                                <span className="agent-mcp-debug-status" data-status={debug.status}>
                                                    {debug.status} · {debug.duration_ms} ms
                                                </span>
                                            </summary>
                                            <div className="agent-mcp-debug-body">
                                                <dl className="agent-mcp-debug-meta">
                                                    <div>
                                                        <dt>{copy.server}</dt>
                                                        <dd>{debug.server_name ?? debug.connection_id ?? '—'}</dd>
                                                    </div>
                                                    <div>
                                                        <dt>{copy.runtime}</dt>
                                                        <dd>{debug.runtime}</dd>
                                                    </div>
                                                    <div>
                                                        <dt>Method</dt>
                                                        <dd>{debug.method}</dd>
                                                    </div>
                                                    <div>
                                                        <dt>Tool</dt>
                                                        <dd>{debug.tool_local_name}</dd>
                                                    </div>
                                                </dl>
                                                <DebugJson
                                                    label={copy.parameters}
                                                    value={debug.parameters}
                                                    copyLabel={copy.copy}
                                                    copiedLabel={copy.copied}
                                                    copyFailedLabel={copy.copyFailed}
                                                />
                                                <DebugJson
                                                    label={copy.response}
                                                    value={debug.response}
                                                    copyLabel={copy.copy}
                                                    copiedLabel={copy.copied}
                                                    copyFailedLabel={copy.copyFailed}
                                                />
                                                {debug.error != null && (
                                                    <DebugJson
                                                        label={copy.error}
                                                        value={debug.error}
                                                        copyLabel={copy.copy}
                                                        copiedLabel={copy.copied}
                                                        copyFailedLabel={copy.copyFailed}
                                                    />
                                                )}
                                            </div>
                                        </details>
                                    )}
                                </li>
                            );
                        })}
                    </ol>
                </details>
            )}
        </aside>
    );
}

interface McpDebugData {
    method: string;
    runtime: string;
    server_name: string | null;
    connection_id: string | null;
    tool_local_name: string;
    tool_remote_name: string;
    status: string;
    duration_ms: number;
    parameters: unknown;
    response: unknown;
    error: unknown;
}

function mcpDebugData(event: AgentRunEvent): McpDebugData | null {
    const candidate = event.data.mcp_debug;
    if (candidate === null || typeof candidate !== 'object' || Array.isArray(candidate)) return null;
    const debug = candidate as Record<string, unknown>;
    if (
        typeof debug.method !== 'string'
        || typeof debug.runtime !== 'string'
        || typeof debug.tool_local_name !== 'string'
        || typeof debug.tool_remote_name !== 'string'
        || typeof debug.status !== 'string'
        || typeof debug.duration_ms !== 'number'
    ) return null;

    return {
        method: debug.method,
        runtime: debug.runtime,
        server_name: typeof debug.server_name === 'string' ? debug.server_name : null,
        connection_id: typeof debug.connection_id === 'string' ? debug.connection_id : null,
        tool_local_name: debug.tool_local_name,
        tool_remote_name: debug.tool_remote_name,
        status: debug.status,
        duration_ms: debug.duration_ms,
        parameters: debug.parameters,
        response: debug.response,
        error: debug.error,
    };
}

function DebugJson({
    label,
    value,
    copyLabel,
    copiedLabel,
    copyFailedLabel,
}: {
    label: string;
    value: unknown;
    copyLabel: string;
    copiedLabel: string;
    copyFailedLabel: string;
}): ReactNode {
    const [copyState, setCopyState] = useState<'idle' | 'copied' | 'failed'>('idle');
    const formatted = prettyJson(value);

    async function copyJson(): Promise<void> {
        if (!navigator.clipboard?.writeText) {
            setCopyState('failed');
            return;
        }
        try {
            await navigator.clipboard.writeText(formatted);
            setCopyState('copied');
            window.setTimeout(() => setCopyState('idle'), 1600);
        } catch {
            setCopyState('failed');
        }
    }

    const buttonLabel = copyState === 'copied'
        ? copiedLabel
        : copyState === 'failed'
            ? copyFailedLabel
            : copyLabel;

    return (
        <section className="agent-mcp-debug-json">
            <div className="agent-mcp-debug-json-heading">
                <h4>{label}</h4>
                <button
                    type="button"
                    className="agent-mcp-debug-copy"
                    aria-label={`${buttonLabel}: ${label}`}
                    onClick={() => void copyJson()}
                    data-state={copyState}
                >
                    {copyState === 'copied' ? <Icon.Check size={11} /> : <Icon.Copy size={11} />}
                    {buttonLabel}
                </button>
            </div>
            <pre>{formatted}</pre>
        </section>
    );
}

function prettyJson(value: unknown): string {
    if (value === undefined) return 'null';

    try {
        return JSON.stringify(value, null, 2) ?? 'null';
    } catch {
        return String(value);
    }
}
