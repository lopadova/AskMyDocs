import { describe, it, expect } from 'vitest';
import {
    mapStatusToDataState,
    type DataState,
    type MapStatusInput,
} from './map-status-to-data-state';

/**
 * v4.0/W3.2 — Vitest unit test for the SDK-status → data-state
 * adapter. Per PLAN §7.1 these test cases are the contract; the
 * Playwright suite assumes this mapping is byte-stable.
 */

describe('mapStatusToDataState', () => {
    function input(overrides: Partial<MapStatusInput>): MapStatusInput {
        return {
            conversationId: 1,
            isLoading: false,
            isError: false,
            messageCount: 0,
            ...overrides,
        };
    }

    const cases: Array<[string, Partial<MapStatusInput>, DataState]> = [
        ['conversationId null overrides everything else', { conversationId: null, isError: true }, 'idle'],
        ['initial-fetch isLoading produces loading', { isLoading: true }, 'loading'],
        ['initial-fetch isError produces error', { isError: true }, 'error'],
        ['SDK error status overrides ready', { sdkStatus: 'error' }, 'error'],
        ['SDK submitted (awaiting first chunk) is loading', { sdkStatus: 'submitted' }, 'loading'],
        ['SDK streaming (chunks arriving) is loading', { sdkStatus: 'streaming' }, 'loading'],
        ['SDK ready + empty message list is empty', { sdkStatus: 'ready', messageCount: 0 }, 'empty'],
        ['SDK ready + non-empty message list is ready', { sdkStatus: 'ready', messageCount: 3 }, 'ready'],
        ['no SDK status + empty list is empty (TanStack-only path)', { messageCount: 0 }, 'empty'],
        ['no SDK status + non-empty list is ready (TanStack-only path)', { messageCount: 5 }, 'ready'],
    ];

    it.each(cases)('%s', (_label, overrides, expected) => {
        expect(mapStatusToDataState(input(overrides))).toBe(expected);
    });

    it('isError takes precedence over SDK streaming (catastrophic refetch fail)', () => {
        // The TanStack history refetch errored AND the SDK is still
        // streaming a reply — the user-visible state should reflect
        // the broken history. We surface error not loading.
        const result = mapStatusToDataState(
            input({ isError: true, sdkStatus: 'streaming', messageCount: 2 }),
        );
        expect(result).toBe('error');
    });

    it('SDK error takes precedence over isLoading=false ready=true (post-stream failure)', () => {
        // Stream completed normally (isLoading=false, message persisted)
        // but the SDK's onError fired afterwards (e.g. malformed finish
        // chunk). User should see error, not stale ready.
        const result = mapStatusToDataState(
            input({ isLoading: false, sdkStatus: 'error', messageCount: 2 }),
        );
        expect(result).toBe('error');
    });

    it('null conversation never surfaces as error even when isError=true', () => {
        // `idle` is the new-conversation landing state. Surfacing error
        // here would render the error pane on a blank composer screen,
        // which is wrong — there's nothing for an error to refer to.
        const result = mapStatusToDataState(
            input({ conversationId: null, isError: true, sdkStatus: 'error' }),
        );
        expect(result).toBe('idle');
    });
});
