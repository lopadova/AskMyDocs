import { api } from '../../../lib/api';

/**
 * v8.33 / ADR 0028 phase 2 — principals a connected source named on a
 * document that could not be matched to an internal user or role.
 *
 * Nothing here grants access. An entry is a QUESTION; answering it means
 * creating an ordinary ACL row, which is deliberately a separate act.
 */

export type TriageStatus = 'pending' | 'ignored';

export interface UnmappedPrincipal {
    id: number;
    document_id: number;
    document_title: string | null;
    source_path: string | null;
    project_key: string;
    /** As the SOURCE described it: user, group, domain, anyone. */
    principal_type: string;
    principal: string;
    effect: string;
    status: TriageStatus;
    first_seen_at: string | null;
    last_seen_at: string | null;
}

export interface SourceAclSummary {
    pending: number;
    ignored: number;
    documents_affected: number;
    /**
     * Documents whose readers the source dictates. A high number is not a
     * problem: it is the feature working.
     */
    documents_restricted: number;
}

export interface SourceAclResponse {
    summary: SourceAclSummary;
    status: TriageStatus;
    data: UnmappedPrincipal[];
}

/**
 * Labels for the principal types a source can report. The machine value
 * stays English and unlocalised (R24); this is display only, and falls back
 * to the raw value so a new type renders without a deploy.
 */
const PRINCIPAL_TYPE_LABELS: Record<string, string> = {
    user: 'Person',
    group: 'Group',
    domain: 'Domain',
    anyone: 'Anyone with the link',
};

export function principalTypeLabel(type: string): string {
    return PRINCIPAL_TYPE_LABELS[type] ?? type;
}

export async function getSourceAcl(
    params: { status?: TriageStatus; projectKey?: string } = {},
): Promise<SourceAclResponse> {
    const qs = new URLSearchParams();
    if (params.status) qs.set('status', params.status);
    if (params.projectKey) qs.set('project_key', params.projectKey);
    const suffix = qs.toString() ? `?${qs.toString()}` : '';
    const { data } = await api.get<SourceAclResponse>(`/api/admin/kb/source-acl${suffix}`);
    return data;
}

export async function setPrincipalStatus(
    id: number,
    status: TriageStatus,
): Promise<{ data: { id: number; status: TriageStatus } }> {
    const { data } = await api.patch(`/api/admin/kb/source-acl/${id}`, { status });
    return data;
}
