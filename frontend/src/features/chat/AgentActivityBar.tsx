import { useId, useState, type ReactNode } from 'react';
import type { AgentRunEvent } from '../../lib/agent-run-events';
import { Icon } from '../../components/Icons';
import { Button } from '../../components/Button';

export interface AgentActivityBarProps {
    events: AgentRunEvent[];
    active: boolean;
    awaitingConfirmation: boolean;
    onCancel: () => void;
    onContinue: () => void;
    instanceId?: string;
    embedded?: boolean;
}

type ActivityState = 'active' | 'settled' | 'confirmation' | 'failed';
type ActivityStageKind = 'starting' | 'documents' | 'planning' | 'api' | 'mcp' | 'analyzing' | 'ready' | 'confirmation' | 'error';

interface ActivityStage {
    kind: ActivityStageKind;
    title: string;
    detail: string;
}

const RING_RADIUS = 18;
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS;

export function AgentActivityBar({
    events,
    active,
    awaitingConfirmation,
    onCancel,
    onContinue,
    instanceId,
    embedded = false,
}: AgentActivityBarProps): ReactNode {
    const [expanded, setExpanded] = useState(false);
    const generatedId = useId();
    const timelineId = `agent-activity-timeline-${instanceId ?? generatedId}`;
    if (events.length === 0 && !active && !awaitingConfirmation) return null;
    const latest = events[events.length - 1];
    const locale = latest?.locale?.toLowerCase().startsWith('it') ? 'it' : 'en';
    const copy = locale === 'it'
        ? {
            fallback: 'L’assistente sta lavorando.',
            details: 'Cronologia attività',
            showDetails: 'Mostra attività',
            hideDetails: 'Nascondi attività',
            cancel: 'Annulla',
            proceed: 'Continua la ricerca',
            calls: 'chiamate',
            seconds: 's rimanenti',
            events: 'eventi',
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
            fallback: 'The assistant is working.',
            details: 'Activity timeline',
            showDetails: 'Show activity',
            hideDetails: 'Hide activity',
            cancel: 'Cancel',
            proceed: 'Continue search',
            calls: 'calls',
            seconds: 's remaining',
            events: 'events',
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
    const terminalFailure = latest?.type === 'run.failed' || latest?.type === 'run.cancelled';
    const state: ActivityState = awaitingConfirmation
        ? 'confirmation'
        : active
            ? 'active'
            : terminalFailure
                ? 'failed'
                : 'settled';
    const percent = activityPercent(latest, likely, completed, state);
    const stage = activityStage(latest, state, locale);
    const timelineEvents = events.filter((event) => (
        (typeof event.message === 'string' && event.message !== '') || mcpDebugData(event) !== null
    ));
    const progressOffset = RING_CIRCUMFERENCE * (1 - percent / 100);

    return (
        <aside
            data-testid="agent-activity-bar"
            data-state={state}
            aria-live="polite"
            aria-busy={active}
            className={embedded ? 'agent-activity-card is-embedded' : 'agent-activity-card'}
        >
            <div className="agent-activity-main">
                <div
                    className="agent-activity-ring"
                    data-active={active || undefined}
                    data-kind={stage.kind}
                    role="progressbar"
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={percent}
                    aria-label={`${stage.title}: ${percent}%`}
                >
                    <svg viewBox="0 0 44 44" aria-hidden="true">
                        <circle className="agent-activity-ring-track" cx="22" cy="22" r={RING_RADIUS} />
                        <circle
                            data-testid="agent-activity-progress"
                            className="agent-activity-ring-value"
                            cx="22"
                            cy="22"
                            r={RING_RADIUS}
                            strokeDasharray={RING_CIRCUMFERENCE}
                            strokeDashoffset={progressOffset}
                        />
                    </svg>
                    <span className="agent-activity-stage-icon" data-live={active || undefined} aria-hidden="true">
                        {stageIcon(stage.kind)}
                    </span>
                </div>
                <div className="agent-activity-content">
                    <div className="agent-activity-heading-row">
                        <strong data-testid="agent-activity-heading" className="agent-activity-heading">
                            {active && <span className="agent-activity-live" aria-hidden="true" />}
                            {stage.title}
                        </strong>
                        <div className="agent-activity-metrics">
                            {likely > 0 && <span data-testid="agent-activity-calls">{completed} / ~{likely} {copy.calls}</span>}
                            {progress?.eta_ms != null && <span>{Math.ceil(progress.eta_ms / 1000)} {copy.seconds}</span>}
                        </div>
                    </div>
                    <div data-testid="agent-activity-message" className="agent-activity-message">
                        {stage.detail || latest?.message || copy.fallback}
                    </div>
                </div>
                {timelineEvents.length > 0 && (
                    <Button
                        variant="quiet"
                        size="sm"
                        className="agent-activity-details-toggle"
                        aria-expanded={expanded}
                        aria-controls={timelineId}
                        aria-label={expanded ? copy.hideDetails : copy.showDetails}
                        onClick={() => setExpanded((value) => !value)}
                    >
                        <Icon.Eye size={13} />
                        <span>{expanded ? copy.hideDetails : copy.details}</span>
                        <span className="agent-activity-count">{timelineEvents.length}</span>
                    </Button>
                )}
                {active && (
                    <Button
                        variant="secondary"
                        size="sm"
                        className="agent-activity-cancel"
                        aria-label={copy.cancel}
                        data-testid="agent-activity-cancel"
                        onClick={onCancel}
                    >
                        <Icon.Close size={12} />
                        <span>{copy.cancel}</span>
                    </Button>
                )}
                {awaitingConfirmation && (
                    <Button variant="primary" size="sm" data-testid="agent-activity-continue" onClick={onContinue}>
                        {copy.proceed}
                    </Button>
                )}
            </div>
            {expanded && timelineEvents.length > 0 && (
                <section className="agent-activity-details" id={timelineId} data-testid="agent-activity-timeline">
                    <div className="agent-activity-details-header">
                        <strong>{copy.details}</strong>
                        <span>{timelineEvents.length} {copy.events}</span>
                    </div>
                    <ol>
                        {timelineEvents.map((event) => {
                            const debug = mcpDebugData(event);
                            const eventState = event.type === 'run.failed' || event.type === 'run.cancelled'
                                ? 'failed'
                                : event.type === 'run.awaiting_confirmation'
                                    ? 'confirmation'
                                    : event.type === 'run.completed' || event.type === 'run.partial'
                                        ? 'settled'
                                        : 'active';
                            const eventStage = activityStage(event, eventState, locale);

                            return (
                                <li
                                    key={event.sequence}
                                    className={debug ? 'agent-activity-event has-mcp-debug' : 'agent-activity-event'}
                                    data-kind={eventStage.kind}
                                >
                                    <span className="agent-activity-event-icon" aria-hidden="true">{stageIcon(eventStage.kind, 12)}</span>
                                    <div className="agent-activity-event-content">
                                        <div className="agent-activity-event-heading">
                                            <strong>{eventStage.title}</strong>
                                            {event.created_at && <time dateTime={event.created_at}>{eventTime(event.created_at, locale)}</time>}
                                        </div>
                                        {event.message && <span className="agent-activity-event-message">{event.message}</span>}
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
                                                        variant="parameters"
                                                        locale={locale}
                                                        copyLabel={copy.copy}
                                                        copiedLabel={copy.copied}
                                                        copyFailedLabel={copy.copyFailed}
                                                    />
                                                    <DebugJson
                                                        label={copy.response}
                                                        value={debug.response}
                                                        variant="response"
                                                        locale={locale}
                                                        copyLabel={copy.copy}
                                                        copiedLabel={copy.copied}
                                                        copyFailedLabel={copy.copyFailed}
                                                    />
                                                    {debug.error != null && (
                                                        <DebugJson
                                                            label={copy.error}
                                                            value={debug.error}
                                                            variant="error"
                                                            locale={locale}
                                                            copyLabel={copy.copy}
                                                            copiedLabel={copy.copied}
                                                            copyFailedLabel={copy.copyFailed}
                                                        />
                                                    )}
                                                </div>
                                            </details>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                </section>
            )}
        </aside>
    );
}

function activityPercent(
    event: AgentRunEvent | undefined,
    likely: number,
    completed: number,
    state: ActivityState,
): number {
    if (state === 'settled' || state === 'failed') return 100;
    if (state === 'confirmation') return 72;
    if (likely > 0) return Math.max(8, Math.min(94, Math.round((completed / likely) * 100)));

    const phase = event?.type.split('.')[0];
    if (phase === 'retrieval') return 18;
    if (phase === 'plan') return 34;
    if (phase === 'tool') return event?.type === 'tool.completed' ? 68 : 52;
    if (phase === 'synthesis') return 86;

    return 8;
}

function activityStage(
    event: AgentRunEvent | undefined,
    state: ActivityState,
    locale: 'it' | 'en',
): ActivityStage {
    const italian = locale === 'it';
    if (state === 'confirmation') {
        return {
            kind: 'confirmation',
            title: italian ? 'Serve una conferma' : 'Confirmation needed',
            detail: event?.message ?? '',
        };
    }
    if (state === 'failed') {
        return {
            kind: 'error',
            title: event?.type === 'run.cancelled'
                ? (italian ? 'Attività annullata' : 'Activity cancelled')
                : (italian ? 'Qualcosa non ha funzionato' : 'Something went wrong'),
            detail: event?.message ?? '',
        };
    }
    if (event?.type === 'run.completed' || event?.type === 'run.partial' || state === 'settled') {
        return {
            kind: 'ready',
            title: italian ? 'Risultato pronto' : 'Result ready',
            detail: event?.message ?? '',
        };
    }

    const toolKind = toolKindFor(event);
    const toolName = toolNameFor(event);
    const serverName = stringValue(event?.data.mcp_server_name) ?? mcpDebugDataForEvent(event)?.server_name;
    const mcpDetail = [serverName, toolName].filter(Boolean).join(' · ');

    if (event?.type === 'tool.started' || event?.type === 'tool.progress') {
        if (toolKind === 'mcp') {
            return {
                kind: 'mcp',
                title: italian ? 'Chiamata MCP' : 'Calling MCP',
                detail: mcpDetail,
            };
        }

        return {
            kind: 'api',
            title: italian ? 'Chiamata API' : 'Calling API',
            detail: toolName,
        };
    }
    if (event?.type === 'tool.completed') {
        return {
            kind: 'analyzing',
            title: italian ? 'Analisi del risultato' : 'Analyzing result',
            detail: toolKind === 'mcp' ? mcpDetail : toolName,
        };
    }
    if (event?.type === 'tool.failed') {
        return {
            kind: 'error',
            title: italian ? 'Chiamata non riuscita' : 'Call failed',
            detail: toolKind === 'mcp' ? mcpDetail : toolName,
        };
    }
    if (event?.type.startsWith('retrieval.')) {
        return event.type === 'retrieval.started'
            ? {
                  kind: 'documents',
                  title: italian ? 'Ricerca nei documenti' : 'Searching documents',
                  detail: italian ? 'Knowledge base' : 'Knowledge base',
              }
            : {
                  kind: 'analyzing',
                  title: italian ? 'Analisi delle fonti' : 'Analyzing sources',
                  detail: event.message ?? '',
              };
    }
    if (event?.type.startsWith('plan.')) {
        return {
            kind: 'planning',
            title: italian ? 'Pianificazione' : 'Planning',
            detail: event.message ?? '',
        };
    }
    if (event?.type.startsWith('synthesis.')) {
        return {
            kind: 'analyzing',
            title: italian ? 'Preparazione della risposta' : 'Preparing the answer',
            detail: event.message ?? '',
        };
    }

    return {
        kind: 'starting',
        title: italian ? 'Avvio della ricerca' : 'Starting search',
        detail: event?.message ?? '',
    };
}

function stageIcon(kind: ActivityStageKind, size = 16): ReactNode {
    if (kind === 'documents') return <Icon.Search size={size} />;
    if (kind === 'planning' || kind === 'analyzing') return <Icon.Brain size={size} />;
    if (kind === 'api') return <Icon.Api size={size} />;
    if (kind === 'mcp') return <Icon.Mcp size={size} />;
    if (kind === 'ready') return <Icon.Check size={size} />;
    if (kind === 'confirmation') return <Icon.Bolt size={size} />;
    if (kind === 'error') return <Icon.Alert size={size} />;

    return <Icon.Activity size={size} />;
}

function toolKindFor(event: AgentRunEvent | undefined): 'api' | 'mcp' {
    const explicit = stringValue(event?.data.tool_kind);
    if (explicit === 'mcp') return 'mcp';
    if (mcpDebugDataForEvent(event) !== null) return 'mcp';
    const name = stringValue(event?.data.tool);

    return name?.startsWith('mcp_') ? 'mcp' : 'api';
}

function toolNameFor(event: AgentRunEvent | undefined): string {
    const name = stringValue(event?.data.mcp_tool_name)
        ?? stringValue(event?.data.tool_display_name)
        ?? stringValue(event?.message_params.tool)
        ?? stringValue(event?.data.tool)
        ?? '';

    return name.replaceAll('_', ' ');
}

function mcpDebugDataForEvent(event: AgentRunEvent | undefined): McpDebugData | null {
    return event ? mcpDebugData(event) : null;
}

function stringValue(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

function eventTime(value: string, locale: 'it' | 'en'): string {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';

    return new Intl.DateTimeFormat(locale === 'it' ? 'it-IT' : 'en-US', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).format(date);
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
    variant,
    locale,
    copyLabel,
    copiedLabel,
    copyFailedLabel,
}: {
    label: string;
    value: unknown;
    variant: 'parameters' | 'response' | 'error';
    locale: 'it' | 'en';
    copyLabel: string;
    copiedLabel: string;
    copyFailedLabel: string;
}): ReactNode {
    const [copyState, setCopyState] = useState<'idle' | 'copied' | 'failed'>('idle');
    const formatted = prettyJson(value);
    const normalized = normalizeDebugValue(value);
    const inspectorCopy = locale === 'it'
        ? { fields: 'campi', items: 'elementi', empty: 'Vuoto', raw: 'JSON grezzo', more: 'altri valori' }
        : { fields: 'fields', items: 'items', empty: 'Empty', raw: 'Raw JSON', more: 'more values' };

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
        <section className="agent-mcp-debug-json" data-variant={variant}>
            <div className="agent-mcp-debug-json-heading">
                <div className="agent-mcp-debug-json-title">
                    <span className="agent-mcp-debug-json-icon" aria-hidden="true">
                        {variant === 'parameters'
                            ? <Icon.Api size={12} />
                            : variant === 'response'
                                ? <Icon.Activity size={12} />
                                : <Icon.Alert size={12} />}
                    </span>
                    <h4>{label}</h4>
                    <span className="agent-mcp-debug-json-summary">{debugValueSummary(normalized, inspectorCopy)}</span>
                </div>
                <Button
                    variant="quiet"
                    size="sm"
                    className="agent-mcp-debug-copy"
                    aria-label={`${buttonLabel}: ${label}`}
                    onClick={() => void copyJson()}
                    data-state={copyState}
                    leadingIcon={copyState === 'copied' ? <Icon.Check size={11} /> : <Icon.Copy size={11} />}
                >
                    {buttonLabel}
                </Button>
            </div>
            <div className="agent-mcp-debug-inspector" data-testid={`agent-mcp-debug-${variant}`}>
                <DebugValueRoot value={normalized} copy={inspectorCopy} />
            </div>
            <details className="agent-mcp-debug-raw">
                <summary>{inspectorCopy.raw}</summary>
                <pre>{formatted}</pre>
            </details>
        </section>
    );
}

interface DebugInspectorCopy {
    fields: string;
    items: string;
    empty: string;
    raw: string;
    more: string;
}

const MAX_DEBUG_ENTRIES = 100;

function DebugValueRoot({ value, copy }: { value: unknown; copy: DebugInspectorCopy }): ReactNode {
    if (Array.isArray(value)) {
        return <DebugEntries entries={value.map((item, index) => [String(index), item])} depth={0} copy={copy} />;
    }
    if (isDebugRecord(value)) {
        return <DebugEntries entries={Object.entries(value)} depth={0} copy={copy} />;
    }

    return <DebugPrimitive value={value} copy={copy} />;
}

function DebugEntries({
    entries,
    depth,
    copy,
}: {
    entries: Array<[string, unknown]>;
    depth: number;
    copy: DebugInspectorCopy;
}): ReactNode {
    if (entries.length === 0) return <span className="agent-mcp-debug-empty">{copy.empty}</span>;
    const visible = entries.slice(0, MAX_DEBUG_ENTRIES);
    const remaining = entries.length - visible.length;

    return (
        <div className="agent-mcp-debug-entries">
            {visible.map(([key, entryValue]) => (
                <div className="agent-mcp-debug-entry" key={`${depth}-${key}`}>
                    <span className="agent-mcp-debug-key" title={key}>{key}</span>
                    <div className="agent-mcp-debug-value">
                        <DebugValue value={entryValue} depth={depth + 1} copy={copy} />
                    </div>
                </div>
            ))}
            {remaining > 0 && (
                <div className="agent-mcp-debug-more">+{remaining} {copy.more}</div>
            )}
        </div>
    );
}

function DebugValue({
    value,
    depth,
    copy,
}: {
    value: unknown;
    depth: number;
    copy: DebugInspectorCopy;
}): ReactNode {
    const entries = Array.isArray(value)
        ? value.map((item, index): [string, unknown] => [String(index), item])
        : isDebugRecord(value)
            ? Object.entries(value)
            : null;
    if (entries === null) return <DebugPrimitive value={value} copy={copy} />;

    const isArray = Array.isArray(value);
    return (
        <details className="agent-mcp-debug-node" open={depth < 2}>
            <summary>
                <span className="agent-mcp-debug-node-kind">{isArray ? 'Array' : 'Object'}</span>
                <span>{entries.length} {isArray ? copy.items : copy.fields}</span>
            </summary>
            <DebugEntries entries={entries} depth={depth} copy={copy} />
        </details>
    );
}

function DebugPrimitive({ value, copy }: { value: unknown; copy: DebugInspectorCopy }): ReactNode {
    if (value === null || value === undefined) {
        return <span className="agent-mcp-debug-primitive" data-type="null">null</span>;
    }
    if (typeof value === 'boolean' || typeof value === 'number') {
        return <span className="agent-mcp-debug-primitive" data-type={typeof value}>{String(value)}</span>;
    }
    if (typeof value === 'string') {
        return (
            <span className="agent-mcp-debug-primitive" data-type="string">
                {value === '' ? copy.empty : value}
            </span>
        );
    }

    return <span className="agent-mcp-debug-primitive" data-type="unknown">{String(value)}</span>;
}

function debugValueSummary(value: unknown, copy: DebugInspectorCopy): string {
    if (Array.isArray(value)) return `${value.length} ${copy.items}`;
    if (isDebugRecord(value)) return `${Object.keys(value).length} ${copy.fields}`;
    if (value === null || value === undefined || value === '') return copy.empty;

    return typeof value;
}

function normalizeDebugValue(value: unknown, depth = 0): unknown {
    if (depth >= 12) return value;
    if (typeof value === 'string') {
        const parsed = parseEmbeddedJson(value);
        return parsed === value ? value : normalizeDebugValue(parsed, depth + 1);
    }
    if (Array.isArray(value)) {
        return value.map((item) => normalizeDebugValue(item, depth + 1));
    }
    if (isDebugRecord(value)) {
        return Object.fromEntries(
            Object.entries(value).map(([key, entryValue]) => [key, normalizeDebugValue(entryValue, depth + 1)]),
        );
    }

    return value;
}

function parseEmbeddedJson(value: string): unknown {
    const trimmed = value.trim();
    if (! ((trimmed.startsWith('{') && trimmed.endsWith('}')) || (trimmed.startsWith('[') && trimmed.endsWith(']')))) {
        return value;
    }
    try {
        return JSON.parse(trimmed) as unknown;
    } catch {
        const documents = trimmed.split(/\n\s*\n(?=\s*[\[{])/);
        if (documents.length < 2) return value;
        try {
            return documents.map((document) => JSON.parse(document) as unknown);
        } catch {
            return value;
        }
    }
}

function isDebugRecord(value: unknown): value is Record<string, unknown> {
    return value !== null && typeof value === 'object' && ! Array.isArray(value);
}

function prettyJson(value: unknown): string {
    if (value === undefined) return 'null';

    try {
        return JSON.stringify(value, null, 2) ?? 'null';
    } catch {
        return String(value);
    }
}
