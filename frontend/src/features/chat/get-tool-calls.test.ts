import { describe, expect, it } from 'vitest';
import type { UIMessage } from 'ai';

import { getToolCalls } from './message-shape-adapters';
import type { Message as AppMessage } from './chat.api';

function makeAppMessage(toolCalls: unknown): AppMessage {
    return {
        id: 42,
        conversation_id: 1,
        role: 'assistant',
        content: 'OK',
        confidence: null,
        refusal_reason: null,
        metadata: { tool_calls: toolCalls },
        created_at: null,
        updated_at: null,
    } as unknown as AppMessage;
}

function makeUiMessage(parts: Array<Record<string, unknown>>): UIMessage {
    return {
        id: 'msg-1',
        role: 'assistant',
        parts,
    } as unknown as UIMessage;
}

describe('getToolCalls — legacy AppMessage shape', () => {
    it('returns an empty array when metadata.tool_calls is missing', () => {
        expect(getToolCalls(makeAppMessage(undefined))).toEqual([]);
    });

    it('returns an empty array when metadata.tool_calls is not an array', () => {
        expect(getToolCalls(makeAppMessage('not-an-array'))).toEqual([]);
    });

    it('normalises a valid tool call from metadata.tool_calls', () => {
        const calls = getToolCalls(
            makeAppMessage([
                {
                    id: 'tool_1',
                    name: 'list_repositories',
                    status: 'ok',
                    server_name: 'github',
                    server_id: 7,
                    arguments: { owner: 'lopadova' },
                    result: { repositories: ['a'] },
                },
            ]),
        );

        expect(calls).toHaveLength(1);
        expect(calls[0]).toMatchObject({
            id: 'tool_1',
            name: 'list_repositories',
            status: 'ok',
            server_name: 'github',
            server_id: 7,
        });
    });

    it('discards tool calls without id or name (invariant guard)', () => {
        const calls = getToolCalls(
            makeAppMessage([
                { id: 'tool_1', name: 'ok_call' },
                { id: '', name: 'no_id' },
                { id: 'tool_3', name: '' },
            ]),
        );
        expect(calls).toHaveLength(1);
        expect(calls[0].name).toBe('ok_call');
    });

    it('defaults unknown status to "ok"', () => {
        const calls = getToolCalls(
            makeAppMessage([{ id: 't', name: 'n', status: 'made-up' }]),
        );
        expect(calls[0].status).toBe('ok');
    });

    it('preserves each known status (pending/ok/error/timeout/denied)', () => {
        const statuses = ['pending', 'ok', 'error', 'timeout', 'denied'] as const;
        for (const status of statuses) {
            const [call] = getToolCalls(
                makeAppMessage([{ id: 't', name: 'n', status }]),
            );
            expect(call.status).toBe(status);
        }
    });
});

describe('getToolCalls — SDK UIMessage shape', () => {
    it('extracts data-tool-call parts from the UIMessage parts array', () => {
        const calls = getToolCalls(
            makeUiMessage([
                { type: 'text', text: 'Hello' },
                {
                    type: 'data-tool-call',
                    data: {
                        id: 'tool_99',
                        name: 'add',
                        status: 'ok',
                        server_name: 'math-mcp',
                        arguments: { a: 1, b: 2 },
                    },
                },
            ]),
        );
        expect(calls).toHaveLength(1);
        expect(calls[0]).toMatchObject({
            id: 'tool_99',
            name: 'add',
            status: 'ok',
            server_name: 'math-mcp',
        });
    });

    it('ignores parts of other types (text-delta, source-url, …)', () => {
        const calls = getToolCalls(
            makeUiMessage([
                { type: 'text-delta', delta: 'hi' },
                { type: 'source-url', url: 'http://x' },
                { type: 'data-confidence', data: { confidence: 80 } },
            ]),
        );
        expect(calls).toEqual([]);
    });

    it('skips data-tool-call parts that lack id or name', () => {
        const calls = getToolCalls(
            makeUiMessage([
                { type: 'data-tool-call', data: { name: 'no_id' } },
                { type: 'data-tool-call', data: { id: 'has_id_no_name' } },
                { type: 'data-tool-call', data: { id: 't1', name: 'valid' } },
            ]),
        );
        expect(calls).toHaveLength(1);
        expect(calls[0].name).toBe('valid');
    });
});
