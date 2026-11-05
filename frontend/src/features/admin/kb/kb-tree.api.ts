import { useQuery } from '@tanstack/react-query';
import { adminKbApi, type KbProjectsResponse, type KbTreeQuery, type KbTreeResponse } from '../admin.api';

/* The admin explorer uses bounded keyset pages and server-side search.
 * Keep the shared cache prefix so mutations invalidate every visited page. */

export const KB_TREE_KEY = ['admin', 'kb', 'tree'] as const;

export function useKbTree(q: KbTreeQuery = {}) {
    return useQuery<KbTreeResponse>({
        queryKey: [...KB_TREE_KEY, q],
        queryFn: () => adminKbApi.tree(q),
        staleTime: 15_000,
    });
}

// Copilot #5 fix: populate the KbView project filter from the DB
// instead of the previous hard-coded `hr-portal` / `engineering`
// pair. Shares the 15s staleTime with `useKbTree` — the project
// list turns over at most on ingest, not per keystroke.
export const KB_PROJECTS_KEY = ['admin', 'kb', 'projects'] as const;

export function useKbProjects() {
    return useQuery<KbProjectsResponse>({
        queryKey: [...KB_PROJECTS_KEY],
        queryFn: () => adminKbApi.projects(),
        staleTime: 60_000,
    });
}
