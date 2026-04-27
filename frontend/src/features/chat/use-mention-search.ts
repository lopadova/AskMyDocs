import { useQuery } from '@tanstack/react-query';
import { api } from '../../lib/api';

/**
 * T2.8 — autocomplete search hook for the chat composer's @mention
 * popover. Wraps `GET /api/kb/documents/search` (T2.6) with a TanStack
 * Query layer that:
 *   - debounces via the consumer (Composer manages query keystrokes)
 *   - aborts in-flight requests on query change (TanStack signal +
 *     query-key change drops the previous fetch automatically)
 *   - caches per-query for 30s (typing the same prefix again is free)
 *   - returns `[]` instantly when the query is too short to be useful
 *
 * Race-condition guard: TanStack Query keys requests by their query
 * key — when the query changes, the in-flight previous fetch is no
 * longer the active one. Combined with `signal.aborted` early-return
 * inside the fetcher, this prevents N+1 results from clobbering N
 * after a fast typist outpaces the network.
 */

export interface MentionResult {
    id: number;
    project_key: string;
    title: string;
    source_path: string;
    source_type: string | null;
    canonical_type: string | null;
}

interface UseMentionSearchArgs {
    query: string;
    projectKeys?: string[];
    /** When false, the hook stays idle (used to gate by popover open state). */
    enabled?: boolean;
    /** Minimum query length before issuing the request. Default 1. */
    minQueryLength?: number;
}

const STALE_TIME_MS = 30_000;

export function useMentionSearch({
    query,
    projectKeys,
    enabled = true,
    minQueryLength = 1,
}: UseMentionSearchArgs) {
    const trimmed = query.trim();
    const isLongEnough = trimmed.length >= minQueryLength;

    return useQuery<{ data: MentionResult[] }>({
        queryKey: ['mention-search', trimmed, projectKeys ?? []],
        // Bail out early when the input is too short. `enabled: false`
        // keeps the hook idle (`isLoading=false`, `data=undefined`) so
        // the popover renders nothing instead of flashing a stale list.
        enabled: enabled && isLongEnough,
        staleTime: STALE_TIME_MS,
        queryFn: async ({ signal }) => {
            const params = new URLSearchParams();
            params.set('q', trimmed);
            // Append `project_keys[]=...` for each scoped project so
            // Laravel parses an array (matches T2.6's validation).
            for (const k of projectKeys ?? []) {
                params.append('project_keys[]', k);
            }
            // Endpoint lives under routes/api.php (Laravel prefixes
            // /api automatically). Other chat.api.ts calls hit
            // routes/web.php (`/conversations/...`) which is why this
            // path differs from the rest of the file.
            const { data } = await api.get<{ data: MentionResult[] }>(
                `/api/kb/documents/search?${params.toString()}`,
                { signal },
            );
            return data;
        },
    });
}
