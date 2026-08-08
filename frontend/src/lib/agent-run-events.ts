export interface AgentProgressEstimate {
    completed: number;
    estimated: { min: number; likely: number; max: number };
}

export interface AgentProgress {
    logical: AgentProgressEstimate;
    physical: AgentProgressEstimate;
    eta_ms: number | null;
}

export interface AgentRunEvent {
    run_id: string;
    sequence: number;
    type: string;
    phase: string | null;
    locale: string;
    message_key: string | null;
    message_params: Record<string, string | number | boolean | null>;
    message: string | null;
    progress: AgentProgress | null;
    can_cancel: boolean;
    data: Record<string, unknown>;
    created_at: string | null;
}

export type AgentRunStopReason =
    | 'completed'
    | 'partial'
    | 'failed'
    | 'cancelled'
    | 'awaiting_confirmation';

export interface AgentRunStreamResult {
    reason: AgentRunStopReason;
    event: AgentRunEvent;
    lastSequence: number;
}

export class AgentRunStreamError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly code?: string,
    ) {
        super(message);
        this.name = 'AgentRunStreamError';
    }
}

export interface ConsumeAgentRunOptions {
    open: (afterSequence: number, signal: AbortSignal) => Promise<Response>;
    onEvent: (event: AgentRunEvent) => void;
    signal?: AbortSignal;
    maxConnections?: number;
}

const STOP_EVENTS: Record<string, AgentRunStopReason> = {
    'run.completed': 'completed',
    'run.partial': 'partial',
    'run.failed': 'failed',
    'run.cancelled': 'cancelled',
    'run.awaiting_confirmation': 'awaiting_confirmation',
};

/**
 * Consume a resumable AgentRun SSE feed. The server intentionally closes long
 * polls, so clean EOF reconnects with the last sequence instead of being
 * treated as completion. Duplicate replayed frames are ignored.
 */
export async function consumeAgentRun(options: ConsumeAgentRunOptions): Promise<AgentRunStreamResult> {
    const controller = new AbortController();
    const relayAbort = () => controller.abort(options.signal?.reason);
    if (options.signal?.aborted) {
        relayAbort();
    } else {
        options.signal?.addEventListener('abort', relayAbort, { once: true });
    }

    let lastSequence = 0;
    const maxConnections = Math.max(1, options.maxConnections ?? 100);
    try {
        for (let connection = 0; connection < maxConnections; connection++) {
            throwIfAborted(controller.signal);
            const response = await options.open(lastSequence, controller.signal);
            if (!response.ok) {
                throw await responseError(response);
            }
            if (!response.body) {
                throw new AgentRunStreamError('The agent event stream has no response body.', response.status, 'empty_stream');
            }

            let stopped: AgentRunStreamResult | null = null;
            for await (const frame of parseSse(response.body, controller.signal)) {
                if (frame.data === '') continue;
                let event: AgentRunEvent;
                try {
                    event = JSON.parse(frame.data) as AgentRunEvent;
                } catch {
                    throw new AgentRunStreamError('The agent event stream returned invalid JSON.', 0, 'invalid_event');
                }
                const sequence = Number.isInteger(event.sequence) ? event.sequence : frame.id;
                if (!Number.isInteger(sequence) || sequence <= lastSequence) continue;
                event.sequence = sequence;
                lastSequence = sequence;
                options.onEvent(event);

                const reason = STOP_EVENTS[event.type];
                if (reason) {
                    stopped = { reason, event, lastSequence };
                    break;
                }
            }
            if (stopped) return stopped;
        }

        throw new AgentRunStreamError(
            'The agent event stream closed too many times before completion.',
            0,
            'reconnect_limit',
        );
    } finally {
        options.signal?.removeEventListener('abort', relayAbort);
        controller.abort();
    }
}

interface SseFrame {
    id: number;
    data: string;
}

async function* parseSse(stream: ReadableStream<Uint8Array>, signal: AbortSignal): AsyncGenerator<SseFrame> {
    const reader = stream.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    try {
        while (true) {
            throwIfAborted(signal);
            const { done, value } = await reader.read();
            buffer += decoder.decode(value, { stream: !done }).replace(/\r\n/g, '\n');
            let boundary = buffer.indexOf('\n\n');
            while (boundary >= 0) {
                const raw = buffer.slice(0, boundary);
                buffer = buffer.slice(boundary + 2);
                const frame = parseFrame(raw);
                if (frame) yield frame;
                boundary = buffer.indexOf('\n\n');
            }
            if (done) break;
        }
        const trailing = parseFrame(buffer);
        if (trailing) yield trailing;
    } finally {
        await reader.cancel().catch(() => undefined);
        reader.releaseLock();
    }
}

function parseFrame(raw: string): SseFrame | null {
    let id = 0;
    const data: string[] = [];
    for (const line of raw.split('\n')) {
        if (line.startsWith(':')) continue;
        if (line.startsWith('id:')) id = Number(line.slice(3).trim());
        if (line.startsWith('data:')) data.push(line.slice(5).trimStart());
    }

    return data.length > 0 ? { id, data: data.join('\n') } : null;
}

async function responseError(response: Response): Promise<AgentRunStreamError> {
    let body: Record<string, unknown> = {};
    try {
        body = await response.clone().json() as Record<string, unknown>;
    } catch {
        // The status remains authoritative when a proxy returns non-JSON.
    }
    const code = typeof body.error === 'string' ? body.error : undefined;
    const message = typeof body.message === 'string'
        ? body.message
        : code ?? `Agent event request failed (${response.status}).`;

    return new AgentRunStreamError(message, response.status, code);
}

function throwIfAborted(signal: AbortSignal): void {
    if (signal.aborted) {
        throw signal.reason instanceof Error
            ? signal.reason
            : new DOMException('The operation was aborted.', 'AbortError');
    }
}
