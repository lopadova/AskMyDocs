import { afterEach, describe, expect, it, vi } from 'vitest';
import { consumeAgentRun, type AgentRunEvent } from './agent-run-events';

function event(sequence: number, type: string): AgentRunEvent {
    return {
        run_id: 'run-1',
        sequence,
        type,
        phase: type.split('.')[0],
        locale: 'it-IT',
        message_key: type,
        message_params: {},
        message: type,
        progress: null,
        can_cancel: !type.startsWith('run.completed'),
        data: type === 'run.completed' ? { response: { answer: 'Fatto' } } : {},
        created_at: null,
    };
}

function streamResponse(frames: string[], splitAt?: number): Response {
    const body = frames.join('');
    const chunks = splitAt === undefined ? [body] : [body.slice(0, splitAt), body.slice(splitAt)];
    return new Response(new ReadableStream<Uint8Array>({
        start(controller) {
            for (const chunk of chunks) controller.enqueue(new TextEncoder().encode(chunk));
            controller.close();
        },
    }), { status: 200, headers: { 'Content-Type': 'text/event-stream' } });
}

function frame(value: AgentRunEvent): string {
    return `id: ${value.sequence}\nevent: ${value.type}\ndata: ${JSON.stringify(value)}\n\n`;
}

describe('consumeAgentRun', () => {
    afterEach(() => vi.useRealTimers());

    it('backs off empty EOFs instead of exhausting the reconnect limit in a tight loop', async () => {
        vi.useFakeTimers();
        const open = vi.fn(async () => streamResponse([]));
        const finished = consumeAgentRun({ open, onEvent: vi.fn(), maxConnections: 3 }).catch((error: unknown) => error);
        await vi.advanceTimersByTimeAsync(0);
        expect(open).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(499);
        expect(open).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(open).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(999);
        expect(open).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(1);
        expect(open).toHaveBeenCalledTimes(3);
        expect(await finished).toMatchObject({ code: 'reconnect_limit' });
    });

    it('resumes from the same cursor after empty polls and finishes on the server failure snapshot', async () => {
        const open = vi.fn().mockResolvedValueOnce(streamResponse([frame(event(1, 'research.planned'))]))
            .mockResolvedValueOnce(streamResponse([]))
            .mockResolvedValueOnce(streamResponse([frame({ ...event(2, 'run.failed'), data: { status_snapshot: true } })]));
        const seen = vi.fn();
        const result = await consumeAgentRun({ open, onEvent: seen, reconnectDelayMs: 0 });
        expect(result.reason).toBe('failed');
        expect(open.mock.calls.map(([cursor]) => cursor)).toEqual([0, 1, 1]);
        expect(seen).toHaveBeenCalledTimes(2);
    });

    it('cancels both reconnect backoff and a blocked stream reader', async () => {
        vi.useFakeTimers();
        const waiting = new AbortController();
        const open = vi.fn(async () => streamResponse([]));
        const backoff = consumeAgentRun({ open, onEvent: vi.fn(), signal: waiting.signal }).catch((error: unknown) => error);
        await vi.advanceTimersByTimeAsync(0);
        waiting.abort();
        expect(await backoff).toMatchObject({ name: 'AbortError' });
        expect(open).toHaveBeenCalledOnce();
        expect(vi.getTimerCount()).toBe(0);

        const reading = new AbortController();
        const cancel = vi.fn();
        const stream = consumeAgentRun({
            open: async () => new Response(new ReadableStream({ cancel })),
            onEvent: vi.fn(), signal: reading.signal, maxConnections: 1,
        }).catch((error: unknown) => error);
        await vi.advanceTimersByTimeAsync(0);
        reading.abort();
        expect(await stream).toMatchObject({ name: 'AbortError' });
        expect(cancel).toHaveBeenCalledOnce();
    });
    it('reconnects from the cursor and parses frames split across chunks', async () => {
        const seen: AgentRunEvent[] = [];
        const cursors: number[] = [];
        const open = vi.fn(async (after: number) => {
            cursors.push(after);
            return after === 0
                ? streamResponse([frame(event(1, 'run.started'))], 11)
                : streamResponse([frame(event(2, 'run.completed'))], 27);
        });

        const result = await consumeAgentRun({ open, onEvent: (value) => seen.push(value) });

        expect(cursors).toEqual([0, 1]);
        expect(seen.map((value) => value.type)).toEqual(['run.started', 'run.completed']);
        expect(result).toMatchObject({ reason: 'completed', lastSequence: 2 });
    });

    it('ignores duplicate replayed sequences', async () => {
        const seen: number[] = [];
        let calls = 0;
        const result = await consumeAgentRun({
            open: async () => {
                calls++;
                return calls === 1
                    ? streamResponse([frame(event(1, 'run.started'))])
                    : streamResponse([frame(event(1, 'run.started')), frame(event(2, 'run.partial'))]);
            },
            onEvent: (value) => seen.push(value.sequence),
        });

        expect(seen).toEqual([1, 2]);
        expect(result.reason).toBe('partial');
    });

    it('stops on confirmation and surfaces structured HTTP failures', async () => {
        const waiting = await consumeAgentRun({
            open: async () => streamResponse([frame(event(1, 'run.awaiting_confirmation'))]),
            onEvent: () => undefined,
        });
        expect(waiting.reason).toBe('awaiting_confirmation');

        await expect(consumeAgentRun({
            open: async () => new Response(JSON.stringify({ error: 'run_hidden', message: 'Not found' }), {
                status: 404,
                headers: { 'Content-Type': 'application/json' },
            }),
            onEvent: () => undefined,
        })).rejects.toMatchObject({ status: 404, code: 'run_hidden' });
    });
});
