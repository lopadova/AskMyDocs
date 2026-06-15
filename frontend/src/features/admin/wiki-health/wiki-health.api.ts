import { api } from '../../../lib/api';

/**
 * v8.11/P10 — Auto-Wiki health: the deterministic lint report for a project's
 * wiki graph (dangling / orphan / stale cross-refs / missing index) + safe
 * auto-fix. Backed by the P5 `WikiLinter` endpoints.
 */

export interface StaleCrossRef {
    edge: string;
    target: string;
    reason: string;
}

export interface WikiLintFindings {
    dangling: string[];
    orphan: string[];
    stale_cross_ref: StaleCrossRef[];
    missing_index: boolean;
}

export interface WikiLintReport {
    project_key: string;
    findings: WikiLintFindings;
    counts: {
        dangling: number;
        orphan: number;
        stale_cross_ref: number;
        missing_index: number;
    };
    healthy: boolean;
}

export interface WikiFixResult {
    pruned_dangling: number;
    pruned: string[];
}

export async function lintWiki(projectKey: string): Promise<WikiLintReport> {
    const { data } = await api.get<{ data: WikiLintReport }>('/api/admin/kb/wiki-lint', {
        params: { project_key: projectKey },
    });
    return data.data;
}

export async function fixWiki(projectKey: string): Promise<WikiFixResult> {
    const { data } = await api.post<{ data: WikiFixResult }>('/api/admin/kb/wiki-lint/fix', {
        project_key: projectKey,
    });
    return data.data;
}
