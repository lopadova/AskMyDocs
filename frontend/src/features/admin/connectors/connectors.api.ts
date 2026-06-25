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
 * The `display_name` / `icon_url` / `oauth_scopes` / `installations[]`
 * shape mirrors the JSON envelope returned by
 * `ConnectorAdminController::index()`. v8.20 multi-account: the primary
 * contract is the `installations[]` LIST (each with `label` + `project_key`);
 * the BE still emits a back-compat single `installation` key the FE ignores.
 */

export type ConnectorStatus = 'pending' | 'active' | 'disabled' | 'errored';

export interface ConnectorInstallationDto {
    id: number;
    // v8.20 multi-account: `label` disambiguates the N accounts a tenant connects
    // on one connector; `project_key` is the optional KB project binding (null =
    // the tenant default).
    label: string;
    project_key: string | null;
    status: ConnectorStatus;
    last_sync_at: string | null;
    error: Record<string, unknown> | null;
    // v8.24 — picker-owned connection settings (read surface; mirrors
    // ConnectorInstallationResource). `folders.include` is the sync whitelist
    // (empty = sync all non-excluded folders); `date_window_days` is the look-back
    // window (null = the connector default).
    folders: { include: string[] };
    date_window_days: number | null;
    // v8.25 — the connector's FULL editable settings schema + current values. The
    // schema-driven settings form renders `connection_settings_schema` (grouped by
    // `group`) seeded from `settings` (a nested partial of config_json keyed by the
    // field's dotted name). [] / {} when the connector advertises no settings.
    // Optional in the type (additive); the BE emits both on every connector.
    connection_settings_schema?: CredentialFieldSchema[];
    settings?: Record<string, unknown>;
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
    // v8.25 — `multiselect` (a list chosen from a fixed or live option set) and
    // `tags` (an open free-text string list) are the connection-settings types.
    type: 'text' | 'number' | 'password' | 'select' | 'checkbox' | 'multiselect' | 'tags';
    label: string;
    target: 'auth_mode' | 'provider' | 'connection' | 'secret' | 'config';
    required: boolean;
    secret: boolean;
    default: string | number | boolean | string[] | null;
    options: Record<string, string>;
    showIf: { field: string; equals: string | number | boolean } | null;
    help: string | null;
    group: string | null;
    // v8.25 — for a live `multiselect`, names the discovery source the host
    // queries to populate the choices (`'folders'` → the live folder list).
    // Optional (additive); the BE emits it (null for non-discovery fields).
    discovery?: string | null;
}

export interface ConnectorEntry {
    key: string;
    display_name: string;
    icon_url: string;
    oauth_scopes: string[];
    auth_kind: ConnectorAuthKind;
    credential_form_schema: CredentialFieldSchema[] | null;
    // v8.20 — the LIST of accounts the active tenant has connected on this
    // connector (empty when none). The BE also still emits a back-compat single
    // `installation` key; the FE reads `installations` exclusively.
    installations: ConnectorInstallationDto[];
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

/**
 * v8.20 — parameters for adding/re-granting an OAuth account: `label` names the
 * account (a distinct label = a new account; the same label re-grants), and an
 * optional `projectKey` binds it to a real project (omit = tenant default).
 */
export interface StartInstallParams {
    key: string;
    label: string;
    projectKey?: string | null;
}

/** v8.20 — PATCH metadata edit of an existing account (label / project binding). */
export interface UpdateInstallationParams {
    installationId: number;
    label?: string;
    /**
     * Empty string OR null clears the binding (inherit the tenant default);
     * `undefined` leaves the existing binding untouched (the key is omitted from
     * the PATCH body). The wrapper maps a present null → '' on the wire.
     */
    project_key?: string | null;
    /**
     * v8.24 — the connection-settings the folder picker edits. `folders.include`
     * is the exact-path sync whitelist (empty array = clear → sync all
     * non-excluded folders). `undefined` leaves it untouched.
     */
    folders?: { include: string[] };
    /**
     * v8.24 — sync look-back window in days. A number sets it; an explicit `null`
     * CLEARS the override back to the connector default (the backend unsets the
     * config_json key); `undefined` leaves it untouched (key omitted from PATCH).
     */
    date_window_days?: number | null;
    /**
     * v8.25 — the generic settings object: a nested partial of config_json keyed
     * by each schema field's dotted name (the schema-driven settings form sends
     * this). `undefined` leaves settings untouched.
     */
    settings?: Record<string, unknown>;
}

/** v8.24 — live IMAP folder list for the connection-settings picker. */
export interface FolderListResponse {
    data: { folders: string[] };
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
        label: string;
        project_key: string | null;
        status: ConnectorStatus;
        last_sync_at: string | null;
        error: Record<string, unknown> | null;
        redirect_to: string | null;
    };
}

/** v8.20 — PATCH edit envelope: the updated account (ConnectorInstallationResource). */
export interface UpdateInstallationResponse {
    data: ConnectorInstallationDto;
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

    async startInstall(params: StartInstallParams): Promise<StartInstallResponse['data']> {
        const query: Record<string, string> = { label: params.label };
        // Only send project_key when bound to a real project — a blank/absent
        // value inherits the tenant default and (on a re-grant) leaves an
        // existing binding untouched (BE uses filled(), not has()).
        if (params.projectKey) {
            query.project_key = params.projectKey;
        }
        const { data } = await api.get<StartInstallResponse>(
            `/api/admin/connectors/${encodeURIComponent(params.key)}/install`,
            { params: query },
        );
        return data.data;
    },

    async update(params: UpdateInstallationParams): Promise<ConnectorInstallationDto> {
        const body: Record<string, unknown> = {};
        if (params.label !== undefined) {
            body.label = params.label;
        }
        if (params.project_key !== undefined) {
            // '' clears the binding; a key binds it.
            body.project_key = params.project_key ?? '';
        }
        if (params.folders !== undefined) {
            body.folders = params.folders;
        }
        if (params.date_window_days !== undefined) {
            body.date_window_days = params.date_window_days;
        }
        if (params.settings !== undefined) {
            body.settings = params.settings;
        }
        const { data } = await api.patch<UpdateInstallationResponse>(
            `/api/admin/connectors/${params.installationId}`,
            body,
        );
        return data.data;
    },

    /**
     * v8.24 — live folder list for an IMAP installation (the picker's options).
     * 503 when the mailbox is unreachable; 404 for a cross-tenant / non-IMAP id.
     */
    async listFolders(installationId: number): Promise<string[]> {
        const { data } = await api.get<FolderListResponse>(
            `/api/admin/connectors/${installationId}/folders`,
        );
        return data.data.folders;
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
