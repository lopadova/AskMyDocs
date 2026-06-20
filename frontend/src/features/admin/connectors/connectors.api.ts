import { api } from '../../../lib/api';

/*
 * v4.5/W3 — Connector admin HTTP client. Mirrors the
 * ConnectorAdminController contract (see `routes/api.php` +
 * `app/Http/Controllers/Api/Admin/ConnectorAdminController.php`).
 *
 * R9 — every field name + URL here MUST match the backend source of
 * truth. The status enum is mirrored from
 * `Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation::STATUSES`
 * (v4.6 package extraction; previously `App\Models\ConnectorInstallation`).
 * The `display_name` / `icon_url` / `oauth_scopes` / `installation`
 * shape mirrors the JSON envelope returned by
 * `ConnectorAdminController::index()`.
 */

export type ConnectorStatus = 'pending' | 'active' | 'disabled' | 'errored';

export interface ConnectorInstallationDto {
    id: number;
    status: ConnectorStatus;
    last_sync_at: string | null;
    error: Record<string, unknown> | null;
}

/*
 * v8.17 — credential-based connectors (IMAP). `auth_kind` discriminates an
 * OAuth-redirect connector (`oauth`, the default) from a credential-form one
 * (`credential`). For credential connectors the BE ships `credential_form_schema`
 * — the ordered field definitions the FE renders, mirroring
 * `Padosoft\AskMyDocsConnectorBase\Support\CredentialField::toArray()`. The form
 * is driven ENTIRELY by this schema (no IMAP-specific FE branch).
 */
export type ConnectorAuthKind = 'oauth' | 'credential';

export interface CredentialFieldSchema {
    name: string;
    label: string;
    type: 'text' | 'number' | 'password' | 'select' | 'checkbox';
    target: 'auth_mode' | 'provider' | 'connection' | 'secret' | 'config';
    required: boolean;
    secret: boolean;
    default: string | number | boolean | null;
    options: Record<string, string>;
    showIf: { field: string; equals: string | number | boolean } | null;
    help: string | null;
    group: string | null;
}

export interface ConnectorEntry {
    key: string;
    display_name: string;
    icon_url: string;
    oauth_scopes: string[];
    auth_kind: ConnectorAuthKind;
    credential_form_schema: CredentialFieldSchema[] | null;
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

/*
 * v8.17 — `ConnectorAdminController::configure()` envelope. `status` reflects the
 * upserted installation (active for a successful basic-auth ping; pending for
 * xoauth2 awaiting the provider redirect). `redirect_to` is non-null ONLY for
 * xoauth2 — the provider authorize URL the browser must visit; the existing
 * `oauth/callback` finishes the flow. A failed basic-auth credential returns
 * HTTP 422 `{ error }` (handled by the mutation, not this shape).
 */
export interface ConfigureResponse {
    data: {
        id: number;
        status: ConnectorStatus;
        last_sync_at: string | null;
        error: Record<string, unknown> | null;
        redirect_to: string | null;
    };
}

/**
 * Submitted credential-form values keyed by field `name` (+ optional project_key).
 * Deliberately excludes `null`: the form OMITS an emptied optional field rather
 * than sending null, so the BE applies the schema default — sending `{ field: null }`
 * would be a contract violation.
 */
export type ConfigureConnectorPayload = Record<string, string | number | boolean>;

export const adminConnectorsApi = {
    async list(): Promise<ConnectorEntry[]> {
        const { data } = await api.get<ConnectorListResponse>('/api/admin/connectors');
        return data.data;
    },

    async configure(
        key: string,
        payload: ConfigureConnectorPayload,
    ): Promise<ConfigureResponse['data']> {
        const { data } = await api.post<ConfigureResponse>(
            `/api/admin/connectors/${encodeURIComponent(key)}/configure`,
            payload,
        );
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
