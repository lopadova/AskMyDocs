import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useTeamStore } from '../../lib/team-store';
import { chatApi, type RealtimeAgentConnection } from './chat.api';
import { useRealtimeAgent } from './use-realtime-agent';

const state = {
    schema: 'realtime-agent-state@1' as const,
    session: { id: '01KSESSION', revision: 1, status: 'active' },
    pending: { actions: [], confirmations: [] },
};

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    useTeamStore.getState().clear();
});

describe('useRealtimeAgent', () => {
    it('connects the Fake driver and tenant-scopes every control request', async () => {
        useTeamStore.setState({ currentTeam: 'acme' });
        const descriptor: RealtimeAgentConnection = {
            session_id: '01KSESSION',
            provider: 'fake',
            connection: { transport: 'fake' },
            state,
            conversation_id: 7,
            expires_at: '2026-09-15T16:00:00Z',
        };
        vi.spyOn(chatApi, 'startRealtimeAgent').mockResolvedValue(descriptor);
        const request = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => new Response(JSON.stringify({ state }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        }));
        vi.stubGlobal('fetch', request);
        const onRequireConversation = vi.fn().mockResolvedValue(7);
        const onAdoptRun = vi.fn().mockResolvedValue(undefined);
        const filters = { languages: ['it'] };
        const liveSources = { api: [], mcp: ['mcp:search'] };
        const { result } = renderHook(() => useRealtimeAgent({
            conversationId: 7,
            filters,
            liveSources,
            availability: { available: true, reason: null },
            onRequireConversation,
            onAdoptRun,
        }));

        await act(async () => result.current.start());

        expect(result.current.status).toBe('listening');
        expect(result.current.active).toBe(true);
        expect(onRequireConversation).not.toHaveBeenCalled();
        expect(chatApi.startRealtimeAgent).toHaveBeenCalledWith(7, filters, liveSources);
        const firstInit = request.mock.calls[0]?.[1] as RequestInit;
        expect(new Headers(firstInit.headers).get('X-Tenant-Id')).toBe('acme');
        expect(new Headers(firstInit.headers).get('X-Requested-With')).toBe('XMLHttpRequest');

        await act(async () => result.current.stop());
        expect(result.current.status).toBe('idle');
        expect(request.mock.calls.some(([, init]) => (init as RequestInit).method === 'DELETE')).toBe(true);
    });
});
