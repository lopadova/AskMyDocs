import { api } from '../../lib/api';

/*
 * Admin dashboard HTTP layer. Mirrors chat.api.ts: thin typed axios
 * wrappers with zero business logic. Consumers live in
 * dashboard/use-admin-metrics.ts + the route-guard reading the auth
 * store. See app/Http/Controllers/Api/Admin/DashboardMetricsController
 * for the source of truth — keep this file in lockstep (R9).
 */

export interface AdminKpiOverview {
    total_docs: number;
    total_chunks: number;
    total_chats: number;
    avg_latency_ms: number;
    failed_jobs: number;
    pending_jobs: number;
    cache_hit_rate: number;
    canonical_coverage_pct: number;
    storage_used_mb: number;
}

export interface AdminOverviewResponse {
    project: string | null;
    days: number;
    overview: AdminKpiOverview;
}

export interface AdminChatVolumeRow {
    date: string;
    count: number;
}

export interface AdminTokenBurnRow {
    provider: string;
    prompt_tokens: number;
    completion_tokens: number;
    total_tokens: number;
}

export interface AdminRatingDistribution {
    positive: number;
    negative: number;
    unrated: number;
    total: number;
}

export interface AdminTopProjectRow {
    project_key: string;
    count: number;
}

export interface AdminActivityRow {
    source: 'chat' | 'audit';
    id: number;
    actor: string;
    action: string;
    target: string;
    project: string;
    created_at: string;
}

export interface AdminSeriesResponse {
    project: string | null;
    days: number;
    chat_volume: AdminChatVolumeRow[];
    token_burn: AdminTokenBurnRow[];
    rating_distribution: AdminRatingDistribution;
    top_projects: AdminTopProjectRow[];
    activity_feed: AdminActivityRow[];
}

export type HealthStatus = 'ok' | 'degraded' | 'down';

export interface AdminHealth {
    db_ok: HealthStatus;
    pgvector_ok: HealthStatus;
    queue_ok: HealthStatus;
    kb_disk_ok: HealthStatus;
    embedding_provider_ok: HealthStatus;
    chat_provider_ok: HealthStatus;
    checked_at: string;
}

export interface AdminMetricsQuery {
    project?: string | null;
    days?: number;
}

function buildParams(q: AdminMetricsQuery): Record<string, string> {
    const params: Record<string, string> = {};
    if (q.project) {
        params.project = q.project;
    }
    if (typeof q.days === 'number') {
        params.days = String(q.days);
    }
    return params;
}

export const adminApi = {
    async overview(q: AdminMetricsQuery = {}): Promise<AdminOverviewResponse> {
        const { data } = await api.get<AdminOverviewResponse>('/api/admin/metrics/overview', {
            params: buildParams(q),
        });
        return data;
    },

    async series(q: AdminMetricsQuery = {}): Promise<AdminSeriesResponse> {
        const { data } = await api.get<AdminSeriesResponse>('/api/admin/metrics/series', {
            params: buildParams(q),
        });
        return data;
    },

    async health(): Promise<AdminHealth> {
        const { data } = await api.get<AdminHealth>('/api/admin/metrics/health');
        return data;
    },
};

// ---------------------------------------------------------------------------
// Phase F2 — Users / Roles / Permissions / Memberships
// ---------------------------------------------------------------------------

export interface AdminUser {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    is_active: boolean;
    deleted_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    roles: string[];
    permissions: string[];
}

export interface AdminPaginatedMeta {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
}

export interface AdminPaginated<T> {
    data: T[];
    meta: AdminPaginatedMeta;
    links?: Record<string, string | null>;
}

export interface AdminUsersQuery {
    q?: string;
    role?: string;
    active?: boolean | null;
    with_trashed?: boolean;
    only_trashed?: boolean;
    page?: number;
    per_page?: number;
}

export interface AdminUserInput {
    name: string;
    email: string;
    password?: string | null;
    is_active?: boolean;
    roles?: string[];
}

export interface AdminRole {
    id: number;
    name: string;
    guard_name: string;
    permissions: string[];
    users_count: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface AdminRoleInput {
    name: string;
    permissions?: string[];
}

export interface AdminPermission {
    id: number;
    name: string;
    guard_name: string;
}

export interface AdminPermissionCatalogue {
    data: AdminPermission[];
    grouped: Record<string, AdminPermission[]>;
}

export interface AdminMembership {
    id: number;
    user_id: number;
    project_key: string;
    role: 'member' | 'admin' | 'owner' | string;
    scope_allowlist: null | {
        folder_globs?: string[];
        tags?: string[];
    };
    created_at: string | null;
    updated_at: string | null;
}

export interface AdminMembershipInput {
    project_key: string;
    role?: 'member' | 'admin' | 'owner';
    scope_allowlist?: AdminMembership['scope_allowlist'];
}

function buildUserParams(q: AdminUsersQuery): Record<string, string> {
    const p: Record<string, string> = {};
    if (q.q && q.q.trim() !== '') p.q = q.q;
    if (q.role && q.role.trim() !== '') p.role = q.role;
    if (typeof q.active === 'boolean') p.active = q.active ? '1' : '0';
    if (q.with_trashed) p.with_trashed = '1';
    if (q.only_trashed) p.only_trashed = '1';
    if (typeof q.page === 'number') p.page = String(q.page);
    if (typeof q.per_page === 'number') p.per_page = String(q.per_page);
    return p;
}

export const adminUsersApi = {
    async list(q: AdminUsersQuery = {}): Promise<AdminPaginated<AdminUser>> {
        const { data } = await api.get<AdminPaginated<AdminUser>>('/api/admin/users', {
            params: buildUserParams(q),
        });
        return data;
    },
    async show(id: number): Promise<AdminUser> {
        const { data } = await api.get<{ data: AdminUser }>(`/api/admin/users/${id}`);
        return data.data;
    },
    async create(input: AdminUserInput): Promise<AdminUser> {
        const { data } = await api.post<{ data: AdminUser }>('/api/admin/users', input);
        return data.data;
    },
    async update(id: number, input: Partial<AdminUserInput>): Promise<AdminUser> {
        const { data } = await api.patch<{ data: AdminUser }>(`/api/admin/users/${id}`, input);
        return data.data;
    },
    async destroy(id: number, force = false): Promise<void> {
        await api.delete(`/api/admin/users/${id}`, { params: force ? { force: 1 } : {} });
    },
    async restore(id: number): Promise<AdminUser> {
        const { data } = await api.post<{ data: AdminUser }>(`/api/admin/users/${id}/restore`);
        return data.data;
    },
    async toggleActive(id: number, nextActive?: boolean): Promise<AdminUser> {
        const payload = typeof nextActive === 'boolean' ? { is_active: nextActive } : {};
        const { data } = await api.patch<{ data: AdminUser }>(`/api/admin/users/${id}/active`, payload);
        return data.data;
    },
    async resendInvite(id: number): Promise<{ message: string }> {
        const { data } = await api.post<{ message: string }>(`/api/admin/users/${id}/resend-invite`);
        return data;
    },
    async listMemberships(userId: number): Promise<AdminPaginated<AdminMembership>> {
        const { data } = await api.get<AdminPaginated<AdminMembership>>(
            `/api/admin/users/${userId}/memberships`,
        );
        return data;
    },
    async upsertMembership(userId: number, input: AdminMembershipInput): Promise<AdminMembership> {
        const { data } = await api.post<{ data: AdminMembership }>(
            `/api/admin/users/${userId}/memberships`,
            input,
        );
        return data.data;
    },
    async updateMembership(
        membershipId: number,
        input: Partial<Omit<AdminMembershipInput, 'project_key'>>,
    ): Promise<AdminMembership> {
        const { data } = await api.patch<{ data: AdminMembership }>(
            `/api/admin/memberships/${membershipId}`,
            input,
        );
        return data.data;
    },
    async deleteMembership(membershipId: number): Promise<void> {
        await api.delete(`/api/admin/memberships/${membershipId}`);
    },
};

export const adminRolesApi = {
    async list(perPage = 50): Promise<AdminPaginated<AdminRole>> {
        const { data } = await api.get<AdminPaginated<AdminRole>>('/api/admin/roles', {
            params: { per_page: perPage },
        });
        return data;
    },
    async show(id: number): Promise<AdminRole> {
        const { data } = await api.get<{ data: AdminRole }>(`/api/admin/roles/${id}`);
        return data.data;
    },
    async create(input: AdminRoleInput): Promise<AdminRole> {
        const { data } = await api.post<{ data: AdminRole }>('/api/admin/roles', input);
        return data.data;
    },
    async update(id: number, input: Partial<AdminRoleInput>): Promise<AdminRole> {
        const { data } = await api.patch<{ data: AdminRole }>(`/api/admin/roles/${id}`, input);
        return data.data;
    },
    async destroy(id: number): Promise<void> {
        await api.delete(`/api/admin/roles/${id}`);
    },
};

export const adminPermissionsApi = {
    async catalogue(): Promise<AdminPermissionCatalogue> {
        const { data } = await api.get<AdminPermissionCatalogue>('/api/admin/permissions');
        return data;
    },
};

// ---------------------------------------------------------------------------
// Phase G1 — KB tree explorer
// ---------------------------------------------------------------------------

export type KbTreeMode = 'canonical' | 'raw' | 'all';

export interface KbTreeDocMeta {
    id: number;
    project_key: string;
    slug: string | null;
    canonical_type: string | null;
    canonical_status: string | null;
    is_canonical: boolean;
    indexed_at: string | null;
    deleted_at: string | null;
}

export interface KbTreeDocNode {
    type: 'doc';
    name: string;
    path: string;
    meta: KbTreeDocMeta;
}

export interface KbTreeFolderNode {
    type: 'folder';
    name: string;
    path: string;
    children: KbTreeNode[];
}

export type KbTreeNode = KbTreeDocNode | KbTreeFolderNode;

export interface KbTreeCounts {
    docs: number;
    canonical: number;
    trashed: number;
}

export interface KbTreeResponse {
    tree: KbTreeNode[];
    counts: KbTreeCounts;
    generated_at: string;
}

export interface KbTreeQuery {
    project?: string | null;
    mode?: KbTreeMode;
    with_trashed?: boolean;
}

function buildKbTreeParams(q: KbTreeQuery): Record<string, string> {
    const p: Record<string, string> = {};
    if (q.project && q.project.trim() !== '') p.project = q.project;
    if (q.mode) p.mode = q.mode;
    if (q.with_trashed) p.with_trashed = '1';
    return p;
}

export interface KbProjectsResponse {
    projects: string[];
}

export const adminKbApi = {
    async tree(q: KbTreeQuery = {}): Promise<KbTreeResponse> {
        const { data } = await api.get<KbTreeResponse>('/api/admin/kb/tree', {
            params: buildKbTreeParams(q),
        });
        return data;
    },
    async projects(): Promise<KbProjectsResponse> {
        const { data } = await api.get<KbProjectsResponse>('/api/admin/kb/projects');
        return data;
    },
};

// ---------------------------------------------------------------------------
// Phase G2 — KB document detail (read-only)
// ---------------------------------------------------------------------------

export interface KbAudit {
    id: number;
    project_key: string;
    doc_id: string | null;
    slug: string | null;
    event_type: string;
    actor: string;
    before_json: Record<string, unknown> | null;
    after_json: Record<string, unknown> | null;
    metadata_json: Record<string, unknown> | null;
    created_at: string | null;
}

export interface KbDocument {
    id: number;
    project_key: string;
    source_type: string | null;
    title: string | null;
    source_path: string;
    mime_type: string | null;
    language: string | null;
    access_scope: string | null;
    status: string | null;
    document_hash: string | null;
    version_hash: string | null;
    doc_id: string | null;
    slug: string | null;
    canonical_type: string | null;
    canonical_status: string | null;
    is_canonical: boolean;
    retrieval_priority: number | null;
    source_of_truth: boolean;
    frontmatter: Record<string, unknown> | null;
    source_updated_at: string | null;
    indexed_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    deleted_at: string | null;
    metadata_tags: string[];
    tags: Array<{ id: number; name: string }>;
    chunks_count: number;
    audits_count: number;
    recent_audits: KbAudit[];
}

export interface KbRawResponse {
    path: string;
    disk: string;
    mime: string;
    content: string;
    content_hash: string;
}

export interface KbHistoryResponse {
    data: KbAudit[];
    meta: AdminPaginatedMeta;
    links?: Record<string, string | null>;
}

export interface KbDestroyResponse {
    ok: boolean;
    mode: 'soft' | 'hard';
    document_id: number;
    file_deleted: boolean;
}

export const adminKbDocumentApi = {
    async show(id: number, withTrashed = true): Promise<KbDocument> {
        const { data } = await api.get<{ data: KbDocument }>(
            `/api/admin/kb/documents/${id}`,
            { params: withTrashed ? { with_trashed: 1 } : {} },
        );
        return data.data;
    },
    async raw(id: number): Promise<KbRawResponse> {
        const { data } = await api.get<KbRawResponse>(
            `/api/admin/kb/documents/${id}/raw`,
        );
        return data;
    },
    async history(id: number, page = 1): Promise<KbHistoryResponse> {
        const { data } = await api.get<KbHistoryResponse>(
            `/api/admin/kb/documents/${id}/history`,
            { params: { page } },
        );
        return data;
    },
    downloadUrl(id: number): string {
        return `/api/admin/kb/documents/${id}/download`;
    },
    printUrl(id: number): string {
        return `/api/admin/kb/documents/${id}/print`;
    },
    async restore(id: number): Promise<KbDocument> {
        const { data } = await api.post<{ data: KbDocument }>(
            `/api/admin/kb/documents/${id}/restore`,
        );
        return data.data;
    },
    async destroy(id: number, force = false): Promise<KbDestroyResponse> {
        const { data } = await api.delete<KbDestroyResponse>(
            `/api/admin/kb/documents/${id}`,
            { params: force ? { force: 1 } : {} },
        );
        return data;
    },
    // Phase G3 — updateRaw. Returns 202 Accepted; the freshly computed
    // version_hash + audit_id echo back so the SPA can invalidate its
    // caches deterministically.
    async updateRaw(id: number, content: string): Promise<KbUpdateRawResponse> {
        const { data } = await api.patch<KbUpdateRawResponse>(
            `/api/admin/kb/documents/${id}/raw`,
            { content },
        );
        return data;
    },
};

// Phase G3 — validation error envelope surfaced by CanonicalParser
// when the submitted body carries a `---` fence with invalid frontmatter.
export interface KbUpdateRawErrorShape {
    message: string;
    errors?: {
        frontmatter?: Record<string, string[]>;
    };
}

export interface KbUpdateRawResponse {
    version_hash: string;
    audit_id: number;
    queued: boolean;
}

// ---------------------------------------------------------------------------
// Phase G4 — KB graph subgraph + PDF export
// ---------------------------------------------------------------------------

export interface KbGraphNode {
    uid: string;
    type: string;
    label: string;
    source_doc_id: string | null;
    role: 'center' | 'neighbor';
    dangling?: boolean;
}

export interface KbGraphEdge {
    uid: string;
    from: string;
    to: string;
    type: string;
    weight: number;
    provenance: string;
}

export interface KbGraphMeta {
    project_key: string;
    center_node_uid: string | null;
    generated_at: string;
}

export interface KbGraphResponse {
    nodes: KbGraphNode[];
    edges: KbGraphEdge[];
    meta: KbGraphMeta;
}

// 501 surface from the export-pdf endpoint: we parse the JSON body to
// echo the operator-facing message in a toast ("enable ADMIN_PDF_ENGINE…").
export interface KbExportPdfDisabledResponse {
    message: string;
    engine: string;
}

export const adminKbGraphApi = {
    async graph(id: number): Promise<KbGraphResponse> {
        const { data } = await api.get<KbGraphResponse>(
            `/api/admin/kb/documents/${id}/graph`,
        );
        return data;
    },
    /**
     * POSTs to /export-pdf; on success returns the PDF Blob so the
     * caller can trigger a download. On failure the axios interceptor
     * preserves the response body so the hook can surface the 501
     * message.
     */
    async exportPdf(id: number): Promise<Blob> {
        const response = await api.post(
            `/api/admin/kb/documents/${id}/export-pdf`,
            {},
            { responseType: 'blob' },
        );
        return response.data as Blob;
    },
};
