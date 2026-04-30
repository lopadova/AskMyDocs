/**
 * v4.0/W3.2 — adapter that maps Vercel AI SDK's `useChat()` status
 * enum to AskMyDocs's `data-state` attribute on `chat-thread`. Per
 * PLAN §7.1 this is the single source of truth for the mapping —
 * the existing Playwright suite reads `data-state` and the contract
 * MUST stay byte-identical to pre-W3.2 behaviour:
 *
 *   | input                                           | output      |
 *   |-------------------------------------------------|-------------|
 *   | conversationId === null                         | 'idle'      |
 *   | isLoading === true (initial fetch)              | 'loading'   |
 *   | sdkStatus === 'submitted' (waiting first chunk) | 'loading'   |
 *   | sdkStatus === 'streaming' (chunks arriving)     | 'loading'   |
 *   | isError === true                                | 'error'     |
 *   | sdkStatus === 'error'                           | 'error'     |
 *   | sdkStatus === 'ready' && messageCount === 0     | 'empty'     |
 *   | sdkStatus === 'ready' && messageCount > 0       | 'ready'     |
 *
 * The SDK statuses come from `@ai-sdk/react` v3 — see the
 * `UseChatHelpers['status']` union type. Names are stable across
 * SDK minor versions per Vercel's documented backwards compat.
 *
 * `isLoading` / `isError` parameters cover the INITIAL message-list
 * fetch (TanStack Query) which happens BEFORE the SDK takes over the
 * streaming lifecycle. The streaming route is `useChat()` only; the
 * initial GET /messages fetch is still TanStack Query because the SDK
 * doesn't manage server-fetched history beyond `initialMessages`.
 *
 * Test coverage lives in `map-status-to-data-state.test.ts`.
 */

export type DataState = 'idle' | 'loading' | 'ready' | 'empty' | 'error';

/**
 * Vercel AI SDK status union, derived from the SDK's own type
 * exports via a type-only import so `@ai-sdk/react` doesn't pull
 * runtime code into this pure-helper module AND any future status
 * additions / renames in the SDK surface as TypeScript errors here
 * (instead of silently drifting). Kept in sync with the SDK version
 * pinned in `package.json` (^3.0.x).
 */
import type { UseChatHelpers } from '@ai-sdk/react';
import type { UIMessage } from 'ai';
export type SdkStatus = UseChatHelpers<UIMessage>['status'];

export interface MapStatusInput {
    conversationId: number | null;
    isLoading: boolean;
    isError: boolean;
    messageCount: number;
    sdkStatus?: SdkStatus;
}

export function mapStatusToDataState(input: MapStatusInput): DataState {
    if (input.conversationId === null) {
        return 'idle';
    }
    if (input.isError) {
        return 'error';
    }
    if (input.sdkStatus === 'error') {
        return 'error';
    }
    if (input.isLoading) {
        return 'loading';
    }
    if (input.sdkStatus === 'submitted' || input.sdkStatus === 'streaming') {
        return 'loading';
    }
    if (input.messageCount === 0) {
        return 'empty';
    }
    return 'ready';
}
