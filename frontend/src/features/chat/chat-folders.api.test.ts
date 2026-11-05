import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from '../../lib/api';
import { chatFoldersApi } from './chat-folders.api';
import { chatApi } from './chat.api';

/**
 * R20: the request/response shapes here must mirror the BE contract
 * exactly — ChatFolderController answers a `{data: …}` envelope, while
 * the conversations endpoints answer a BARE array (a legacy shape R27
 * forbids re-wrapping). Getting either wrong fails at runtime only, so
 * it is pinned here.
 */

afterEach(() => vi.restoreAllMocks());

const folder = {
    id: 3,
    name: 'Issue 42',
    position: 0,
    created_at: '2026-09-16T10:00:00Z',
    updated_at: '2026-09-16T10:00:00Z',
};

describe('chatFoldersApi', () => {
    it('unwraps the data envelope when listing', async () => {
        const get = vi.spyOn(api, 'get').mockResolvedValue({ data: { data: [folder] } });

        await expect(chatFoldersApi.list()).resolves.toEqual([folder]);
        expect(get).toHaveBeenCalledWith('/api/chat-folders');
    });

    it('posts just the name and unwraps the created folder', async () => {
        const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { data: folder } });

        await expect(chatFoldersApi.create('Issue 42')).resolves.toEqual(folder);
        // No owner or tenant id: the session and the X-Tenant-Id
        // interceptor carry both.
        expect(post).toHaveBeenCalledWith('/api/chat-folders', { name: 'Issue 42' });
    });

    it('renames through PATCH on the folder id', async () => {
        const patch = vi.spyOn(api, 'patch').mockResolvedValue({ data: { data: folder } });

        await chatFoldersApi.rename(3, 'Renamed');

        expect(patch).toHaveBeenCalledWith('/api/chat-folders/3', { name: 'Renamed' });
    });

    it('deletes by id', async () => {
        const del = vi.spyOn(api, 'delete').mockResolvedValue({ data: null });

        await chatFoldersApi.remove(3);

        expect(del).toHaveBeenCalledWith('/api/chat-folders/3');
    });
});

describe('chatApi.listConversations', () => {
    it('sends no params for the default active listing', async () => {
        const get = vi.spyOn(api, 'get').mockResolvedValue({ data: [] });

        await chatApi.listConversations();

        // `active` is the server default, so the plain list stays a bare
        // GET and the existing ['conversations'] cache entry is untouched.
        expect(get).toHaveBeenCalledWith('/conversations', { params: undefined });
    });

    it('asks for the archived slice explicitly', async () => {
        const get = vi.spyOn(api, 'get').mockResolvedValue({ data: [] });

        await chatApi.listConversations('archived');

        expect(get).toHaveBeenCalledWith('/conversations', { params: { archived: 1 } });
    });

    it('asks for everything with archived=all', async () => {
        const get = vi.spyOn(api, 'get').mockResolvedValue({ data: [] });

        await chatApi.listConversations('all');

        expect(get).toHaveBeenCalledWith('/conversations', { params: { archived: 'all' } });
    });
});

describe('chatApi.organizeConversation', () => {
    it('sends pin as a BOOLEAN even though the response carries a timestamp', async () => {
        const patch = vi.spyOn(api, 'patch').mockResolvedValue({
            data: { id: 7, pinned_at: '2026-09-16T10:00:00Z' },
        });

        const updated = await chatApi.organizeConversation(7, { pinned: true });

        expect(patch).toHaveBeenCalledWith('/conversations/7', { pinned: true });
        // The client never invents a date — it reads back when it happened.
        expect(updated.pinned_at).toBe('2026-09-16T10:00:00Z');
    });

    it('sends a null folder id to unfile rather than omitting the key', async () => {
        // Omitting it would mean "leave the folder alone"; null means
        // "unfile". The distinction is the whole point of the partial PATCH.
        const patch = vi.spyOn(api, 'patch').mockResolvedValue({ data: { id: 7 } });

        await chatApi.organizeConversation(7, { chat_folder_id: null });

        expect(patch).toHaveBeenCalledWith('/conversations/7', { chat_folder_id: null });
    });

    it('passes importance through as the machine-readable value', async () => {
        const patch = vi.spyOn(api, 'patch').mockResolvedValue({ data: { id: 7 } });

        await chatApi.organizeConversation(7, { importance: 'critical' });

        expect(patch).toHaveBeenCalledWith('/conversations/7', { importance: 'critical' });
    });
});
