import { QueryClient } from '@tanstack/react-query';

/*
 * Single QueryClient for the SPA. Defaults are conservative — retry on
 * 5xx only, no auto-refetch on window focus (dashboards do that
 * explicitly in PR6 via `refetchInterval`). When you add a mutation
 * that writes to the server, invalidate the affected query key
 * manually: the client has no automatic invalidation policy.
 */
export const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            retry: (failureCount, error) => {
                const status = (error as { response?: { status?: number } })?.response?.status;
                if (status && status >= 400 && status < 500) {
                    return false;
                }
                return failureCount < 2;
            },
            refetchOnWindowFocus: false,
            staleTime: 30_000,
        },
    },
});
