import { api } from '../../../lib/api';

/*
 * v4.5/W3 — Connector admin HTTP client. Mirrors the
 * ConnectorAdminController contract (see `routes/api.php` +
 * `app/Http/Controllers/Api/Admin/ConnectorAdminController.php`).
 *
 * R9 — every field name + URL here MUST match the backend source of
 * truth. The status enum is mirrored from
 * `App\Models\ConnectorInstallation::STATUSES`. The `display_name` /
 * `icon_url` / `oauth_scopes` / `installation` shape mirrors the JSON
 * envelope returned by `ConnectorAdminController::index()`.
 */

export type ConnectorStatus = 'pending' | 'active' | 'disabled' | 'errored';

export interface ConnectorInstallationDto {
    id: number;
    status: ConnectorStatus;
    last_sync_at: string | null;
    error: Record<string, unknown> | null;
}

export interface ConnectorEntry {
    key: string;
    display_name: string;
    icon_url: string;
    oauth_scopes: string[];
    installation: ConnectorInstallationDto | null;
}

export interface ConnectorListResponse {
    data: ConnectorEntry[];
}

export interface StartInstallResponse {
    data: {
        installation_id: number;
        redirect_to: string;
    };
}

export interface SyncNowResponse {
    data: {
        installation_id: number;
        queued: true;
    };
}

/*
 * Backend `ConnectorAdminController::disable()` returns the minimal
 * `{ installation_id, status }` envelope — the FE refetches the list
 * after a successful disable, so the partial payload is enough to
 * acknowledge state-change. Do NOT widen this to a full DTO without
 * also widening the BE response (Copilot inline #1 on PR #151).
 */
export interface DisableResponse {
    data: {
        installation_id: number;
        status: ConnectorStatus;
    };
}

export const adminConnectorsApi = {
    async list(): Promise<ConnectorEntry[]> {
        const { data } = await api.get<ConnectorListResponse>('/api/admin/connectors');
        return data.data;
    },

    async startInstall(key: string): Promise<StartInstallResponse['data']> {
        const { data } = await api.get<StartInstallResponse>(
            `/api/admin/connectors/${encodeURIComponent(key)}/install`,
        );
        return data.data;
    },

    async syncNow(installationId: number): Promise<SyncNowResponse['data']> {
        const { data } = await api.post<SyncNowResponse>(
            `/api/admin/connectors/${installationId}/sync-now`,
        );
        return data.data;
    },

    async disable(installationId: number): Promise<DisableResponse['data']> {
        const { data } = await api.post<DisableResponse>(
            `/api/admin/connectors/${installationId}/disable`,
        );
        return data.data;
    },

    async destroy(installationId: number): Promise<void> {
        await api.delete(`/api/admin/connectors/${installationId}`);
    },
};
