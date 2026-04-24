import { useQuery } from '@tanstack/react-query';
import { adminKbApi, type KbTreeQuery, type KbTreeResponse } from '../admin.api';

/*
 * Phase G1 — KB tree explorer query hook. A single read — the tree
 * endpoint returns folders + doc leaves in one round-trip, so there
 * is no need for the paginated-list pattern used elsewhere in the
 * admin area.
 *
 * Detail queries (chunks / rendered body / history) land in G2
 * under a sibling hook + endpoint; do not fold them into this
 * query key.
 */

export const KB_TREE_KEY = ['admin', 'kb', 'tree'] as const;

export function useKbTree(q: KbTreeQuery = {}) {
    return useQuery<KbTreeResponse>({
        queryKey: [...KB_TREE_KEY, q],
        queryFn: () => adminKbApi.tree(q),
        staleTime: 15_000,
    });
}
