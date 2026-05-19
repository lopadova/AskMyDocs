import { api } from '../../lib/api';

/**
 * v8.0/W1.4 — Notification feed API client.
 *
 * Wraps `/api/notifications/*` for the React NotificationBell +
 * NotificationPanel. Follows the `{ data, meta }` envelope used
 * by the rest of the admin surface.
 */

export type NotificationState = 'unread' | 'read' | 'dismissed' | 'all';

/**
 * Mirrors `App\Models\NotificationEvent::EVENT_*` constants. Keep
 * in lockstep with the BE enum (single source of truth lives there).
 */
export type NotificationEventType =
    | 'kb_doc_created'
    | 'kb_doc_modified'
    | 'kb_canonical_promoted'
    | 'kb_decision_debt_threshold'
    | 'collection_new_member';

export interface NotificationRow {
    id: number;
    tenant_id: string;
    user_id: number | null;
    event_type: NotificationEventType | string;
    payload: Record<string, unknown>;
    channel_dispatch_log: Array<{
        channel: string;
        status: string;
        at: string;
        error?: string;
    }>;
    created_at: string;
    read_at: string | null;
    dismissed_at: string | null;
}

export interface NotificationListResponse {
    data: NotificationRow[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        state: NotificationState;
    };
}

export const notificationsApi = {
    async list(params: {
        state?: NotificationState;
        eventType?: string;
        perPage?: number;
        page?: number;
    } = {}): Promise<NotificationListResponse> {
        const { data } = await api.get<NotificationListResponse>('/api/notifications', {
            params: {
                state: params.state,
                event_type: params.eventType,
                per_page: params.perPage,
                page: params.page,
            },
        });
        return data;
    },

    async unreadCount(): Promise<number> {
        const { data } = await api.get<{ unread_count: number }>('/api/notifications/unread-count');
        return data.unread_count;
    },

    async markRead(id: number): Promise<NotificationRow> {
        const { data } = await api.post<{ data: NotificationRow }>(`/api/notifications/${id}/mark-read`);
        return data.data;
    },

    async dismiss(id: number): Promise<NotificationRow> {
        const { data } = await api.post<{ data: NotificationRow }>(`/api/notifications/${id}/dismiss`);
        return data.data;
    },

    async markAllRead(params: { eventType?: string } = {}): Promise<number> {
        // Copilot iter-2 #3 — forward the current filter so the BE
        // only flips rows the user actually sees in the panel.
        const body: Record<string, string> = {};
        if (params.eventType) {
            body.event_type = params.eventType;
        }
        const { data } = await api.post<{ marked_read: number }>('/api/notifications/mark-all-read', body);
        return data.marked_read;
    },
};
