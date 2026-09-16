import { api } from '../../lib/api';

/**
 * User-created folders grouping chat sessions in the Sessions workspace.
 *
 * Private per (tenant, user): the tenant comes from the shared axios
 * client's X-Tenant-Id interceptor, the user from the session, so
 * nothing here passes an owner id. Another user's folder answers 404,
 * never 403 — the API deliberately does not confirm that the id exists.
 */
export interface ChatFolder {
    id: number;
    name: string;
    position: number;
    created_at: string;
    updated_at: string;
}

/** Shared cache key. Nested under nothing — folders are a flat list. */
export const CHAT_FOLDERS_QUERY_KEY = ['chat-folders'] as const;

export const chatFoldersApi = {
    async list(): Promise<ChatFolder[]> {
        const { data } = await api.get<{ data: ChatFolder[] }>('/api/chat-folders');
        return data.data;
    },

    async create(name: string): Promise<ChatFolder> {
        const { data } = await api.post<{ data: ChatFolder }>('/api/chat-folders', { name });
        return data.data;
    },

    async rename(id: number, name: string): Promise<ChatFolder> {
        const { data } = await api.patch<{ data: ChatFolder }>(`/api/chat-folders/${id}`, { name });
        return data.data;
    },

    /**
     * Delete a folder. Its sessions are UNFILED, not deleted — the BE FK
     * is nullOnDelete. Invalidate BOTH ['chat-folders'] and
     * ['conversations'] afterwards, since every filed thread's
     * chat_folder_id just became null.
     */
    async remove(id: number): Promise<void> {
        await api.delete(`/api/chat-folders/${id}`);
    },
};
