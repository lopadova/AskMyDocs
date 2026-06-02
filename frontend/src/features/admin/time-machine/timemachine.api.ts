import { api } from '../../../lib/api';

/**
 * v8.7/W5 — Cloud Time Machine admin client (version timeline + diff + restore).
 */

export interface DocVersion {
    id: number;
    title: string | null;
    version_hash: string | null;
    status: string;
    is_canonical: boolean;
    canonical_type: string | null;
    is_live: boolean;
    indexed_at: string | null;
    created_at: string | null;
}

export interface VersionTimeline {
    data: DocVersion[];
    meta: { project_key: string; source_path: string; total: number };
}

export interface DiffRow {
    type: 'context' | 'add' | 'remove';
    text: string;
}

export interface VersionDiff {
    from: number;
    to: number;
    added: number;
    removed: number;
    rows: DiffRow[];
}

export async function getVersions(docId: number): Promise<VersionTimeline> {
    const { data } = await api.get<VersionTimeline>(`/api/admin/kb/documents/${docId}/versions`);
    return data;
}

export async function getDiff(docId: number, from: number, to: number): Promise<VersionDiff> {
    const { data } = await api.get<{ data: VersionDiff }>(
        `/api/admin/kb/documents/${docId}/versions/diff?from=${from}&to=${to}`,
    );
    return data.data;
}

export async function restoreVersion(versionId: number): Promise<{ id: number; status: string }> {
    const { data } = await api.post<{ data: { id: number; status: string } }>(
        `/api/admin/kb/documents/${versionId}/restore-version`,
    );
    return data.data;
}
