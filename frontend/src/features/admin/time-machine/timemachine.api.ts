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
    /** v8.36 / ADR 0030 — who created the version (`user:{id}`, `system:ingest`, `system:ocr`, …); null on rows that predate it */
    version_actor?: string | null;
    /** v8.36 / ADR 0030 — free-text reason (`restore of #12`, `ocr re-run (docling)`, …) */
    version_reason?: string | null;
    /** v8.36 / ADR 0030 — SHA-256 of the stored artifact; null when none is stored */
    content_hash?: string | null;
    /** v8.36 / ADR 0030 — true when the converted Markdown of this version is stored on disk */
    has_artifact?: boolean;
    /** v8.36 / ADR 0030 §6 — who last restored this version (kept apart from the creation provenance); null when never restored */
    restored_by?: string | null;
    /** v8.36 / ADR 0030 §6 — when it was last restored (ISO-8601) */
    restored_at?: string | null;
}

/** Where one side of a diff came from (v8.36 / ADR 0030 §5). */
export type VersionContentSource = 'artifact' | 'reconstruction';

/** ADR 0030 §5 — `content_hash` check of the stored bytes: verified, mismatch (served as reconstruction), or null when nothing to check against. */
export type VersionContentIntegrity = 'verified' | 'mismatch' | null;

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
    /** v8.36 — additive: the diff is faithful when both sides are artifacts, an index diff otherwise */
    from_source?: VersionContentSource;
    to_source?: VersionContentSource;
    from_integrity?: VersionContentIntegrity;
    to_integrity?: VersionContentIntegrity;
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
