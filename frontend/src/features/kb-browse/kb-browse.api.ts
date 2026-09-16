import { api } from '../../lib/api';
import type { KbTreeMode, KbTreeResponse } from '../admin/admin.api';

/**
 * Reader-side KB tree.
 *
 * Deliberately NOT the admin `/api/admin/kb/tree`: that route is gated
 * `role:admin|super-admin`, so a viewer or editor would get a 403. Both
 * endpoints are served by the same `KbTreeService` and return the same
 * envelope, which is why the admin `KbTreeResponse` type is reused
 * verbatim rather than cloned.
 *
 * There is no `with_trashed` parameter by design: the reader endpoint
 * never returns soft-deleted documents (R2).
 */
export const kbBrowseApi = {
    async tree(project: string | null, mode: KbTreeMode): Promise<KbTreeResponse> {
        const { data } = await api.get<KbTreeResponse>('/api/kb/tree', {
            params: {
                ...(project !== null && project !== '' ? { project } : {}),
                mode,
            },
        });
        return data;
    },
};
