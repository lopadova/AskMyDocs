import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { waitFor } from '@testing-library/react';
import { chatApi, type ConversationActivity, type Message } from './chat.api';
import { clearAgentChatSessions, getAgentChatSession } from './agent-chat-session';

const reply: Message = { id: 11, role: 'assistant', content: 'Saved answer', metadata: {}, rating: null, created_at: '2026-10-21T12:00:00Z' };
const snapshot = (status = 'completed'): ConversationActivity => ({
    conversation_id: 7, last_read_message_id: 0, latest_reply: { message_id: reply.id, created_at: reply.created_at },
    run: { run_id: 'run-1', status, locale: 'it', events_url: '/events', cancel_url: '/cancel', continue_url: '/continue', confirmation: null },
});
const session = () => getAgentChatSession(7, 1, 'acme');

beforeEach(() => {
    clearAgentChatSessions();
    vi.spyOn(chatApi, 'listMessages').mockResolvedValue([reply]);
    vi.spyOn(chatApi, 'markConversationRead').mockImplementation(async (_id, messageId) => ({ last_read_message_id: messageId }));
});
afterEach(() => {
    clearAgentChatSessions();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('durable chat activity recovery', () => {
    it('recovers the existing run after reload and replays progress without posting the question again', async () => {
        const start = vi.spyOn(chatApi, 'startAgentTurn');
        const streams: ReadableStreamDefaultController<Uint8Array>[] = [];
        const signals: AbortSignal[] = [];
        const fetchEvents = vi.fn(async (_url: RequestInfo | URL, init?: RequestInit) => {
            signals.push(init!.signal!);
            return new Response(new ReadableStream<Uint8Array>({ start(controller) { streams.push(controller); } }));
        });
        vi.stubGlobal('fetch', fetchEvents);
        const active = { ...snapshot('running'), latest_reply: null };
        session().restore(active);
        session().restore(active);
        await waitFor(() => expect(fetchEvents).toHaveBeenCalledOnce());
        clearAgentChatSessions(); // full page/application restart
        expect(signals[0]!.aborted).toBe(true);
        session().restore(active);
        await waitFor(() => expect(fetchEvents).toHaveBeenCalledTimes(2));
        const leave = session().attach();
        const question: Message = { ...reply, id: 10, role: 'user', content: 'Original question' };
        session().hydrate([question]);
        expect(session().store.getState().messages).toEqual([question]);
        expect(session().store.getState().status).toBe('streaming');
        leave();
        const event = { run_id: 'run-1', sequence: 1, type: 'run.completed', phase: 'run', locale: 'it', message_key: null,
            message_params: {}, message: null, progress: null, can_cancel: false, data: {}, created_at: reply.created_at };
        streams[1]!.enqueue(new TextEncoder().encode(`data: ${JSON.stringify(event)}\n\n`));
        await waitFor(() => expect(session().store.getState().messages).toEqual([reply]));
        expect(session().store.getState().unreadAt).toBe(Date.parse(reply.created_at));
        expect(start).not.toHaveBeenCalled();
        expect(chatApi.markConversationRead).not.toHaveBeenCalled();
        // A stale running snapshot cannot reopen an already completed stream.
        session().restore(active);
        expect(fetchEvents).toHaveBeenCalledTimes(2);
    });

    it('restores replies completed while the app was closed until explicit entry, then keeps them read after reopening', async () => {
        const request = vi.fn();
        vi.stubGlobal('fetch', request);
        session().restore(snapshot());
        expect(session().store.getState().unreadAt).toBe(Date.parse(reply.created_at));
        clearAgentChatSessions();
        session().restore(snapshot());
        expect(session().store.getState().unreadAt).toBe(Date.parse(reply.created_at));
        expect(chatApi.markConversationRead).not.toHaveBeenCalled();
        const leave = session().attach();
        await waitFor(() => expect(chatApi.markConversationRead).toHaveBeenCalledWith(7, 11, 'acme'));
        expect(session().store.getState().unreadAt).toBeNull();
        leave();
        // A snapshot requested before the receipt must not resurrect its badge.
        session().restore(snapshot());
        expect(session().store.getState().unreadAt).toBeNull();
        clearAgentChatSessions();
        session().restore({ ...snapshot(), last_read_message_id: 11 });
        expect(session().store.getState().unreadAt).toBeNull();
        expect(request).not.toHaveBeenCalled();
    });

    it('does not let a delayed read receipt clear the next reply after the user leaves', async () => {
        let acknowledge!: (value: { last_read_message_id: number }) => void;
        vi.mocked(chatApi.markConversationRead).mockImplementationOnce(() => new Promise((resolve) => { acknowledge = resolve; }));
        session().restore(snapshot());
        const leave = session().attach();
        leave();
        session().restore({ ...snapshot(), latest_reply: { message_id: 12, created_at: '2026-10-21T12:05:00Z' } });
        acknowledge({ last_read_message_id: 11 });
        await Promise.resolve();
        expect(session().store.getState().unreadAt).toBe(Date.parse('2026-10-21T12:05:00Z'));
        expect(chatApi.markConversationRead).toHaveBeenCalledTimes(1);
        expect(chatApi.markConversationRead).toHaveBeenCalledWith(7, 11, 'acme');
    });

    it('retries a failed receipt during reconciliation even after leaving the chat', async () => {
        vi.mocked(chatApi.markConversationRead).mockRejectedValueOnce(new Error('Offline'));
        session().restore(snapshot());
        session().attach()();
        await waitFor(() => expect(chatApi.markConversationRead).toHaveBeenCalledTimes(1));
        session().restore(snapshot());
        await waitFor(() => expect(chatApi.markConversationRead).toHaveBeenCalledTimes(2));
        expect(session().store.getState().unreadAt).toBeNull();
    });

    it('recovers confirmation and follows a continuation performed in another app instance', async () => {
        const request = vi.fn(async () => new Response(new ReadableStream<Uint8Array>()));
        vi.stubGlobal('fetch', request);
        session().restore({ ...snapshot('awaiting_confirmation'), latest_reply: null,
            run: { ...snapshot().run!, status: 'awaiting_confirmation', confirmation: { physicalExtension: 30, logicalExtension: 2 } } });
        expect(session().store.getState().confirmation).toEqual({ physicalExtension: 30, logicalExtension: 2 });
        expect(request).not.toHaveBeenCalled();
        session().restore({ ...snapshot('queued'), latest_reply: null });
        await waitFor(() => expect(request).toHaveBeenCalledOnce());
        expect(session().store.getState().status).toBe('streaming');
    });

    it('keeps newer unread replies when an older activity response arrives late', () => {
        session().restore({ ...snapshot(), latest_reply: { message_id: 12, created_at: '2026-10-21T12:05:00Z' } });
        session().restore({ ...snapshot(), last_read_message_id: 11 });
        expect(session().store.getState().unreadAt).toBe(Date.parse('2026-10-21T12:05:00Z'));
    });

    it('does not reintroduce a badge for a reply read just before its run publishes completion', async () => {
        const event = { run_id: 'run-1', sequence: 1, type: 'run.completed', phase: 'run', locale: 'it', message_key: null,
            message_params: {}, message: null, progress: null, can_cancel: false, data: {}, created_at: reply.created_at };
        vi.stubGlobal('fetch', vi.fn(async () => new Response(`data: ${JSON.stringify(event)}\n\n`)));
        session().restore(snapshot('running'));
        const leave = session().attach();
        session().hydrate([reply]);
        leave();
        await waitFor(() => expect(session().store.getState().status).toBe('ready'));
        await waitFor(() => expect(chatApi.listMessages).toHaveBeenCalledOnce());
        expect(session().store.getState().unreadAt).toBeNull();
        expect(chatApi.markConversationRead).toHaveBeenCalledWith(7, 11, 'acme');
    });
});
