import { api } from '../../../lib/api';

/**
 * AI Act compliance — native overview.
 *
 * The standalone `padosoft/laravel-ai-act-compliance-admin` package is a
 * frontend prototype with no servable Laravel bundle, so the previous
 * iframe cross-mount looped the host SPA back into itself (the v6.0
 * `/admin/ai-act-compliance/{any?}` redirect placeholder bounced the iframe
 * to `/app/admin/ai-act-compliance`, which re-rendered the iframe — infinite
 * recursion). This module reads the REAL data the core
 * `padosoft/laravel-ai-act-compliance` package already serves under
 * `/api/admin/ai-act-compliance/*` so the panel renders live, native,
 * recursion-free.
 */

export type AiActDomainKey =
    | 'incidents'
    | 'dsar'
    | 'consent'
    | 'bias'
    | 'attestations'
    | 'human-reviews';

export interface AiActDomain {
    key: AiActDomainKey;
    /** REST path segment under /api/admin/ai-act-compliance/. */
    path: string;
    label: string;
    description: string;
}

export const AI_ACT_DOMAINS: AiActDomain[] = [
    { key: 'incidents', path: 'incidents', label: 'Incidents', description: 'Serious-incident register (Art. 73).' },
    { key: 'dsar', path: 'dsar', label: 'Data-subject requests', description: 'DSAR intake + execution (GDPR Art. 15–22).' },
    { key: 'consent', path: 'consent', label: 'Consent', description: 'Per-feature consent grants + revocations.' },
    { key: 'bias', path: 'bias', label: 'Bias monitoring', description: 'Captured fairness / bias measurements.' },
    { key: 'attestations', path: 'attestations', label: 'Attestations', description: 'Conformity attestations on record.' },
    { key: 'human-reviews', path: 'human-reviews', label: 'Human reviews', description: 'Human-in-the-loop review queue (Art. 14).' },
];

/** A compliance record is shape-agnostic — we only read a few common fields. */
export interface AiActRecord {
    id?: number | string;
    status?: string | null;
    state?: string | null;
    [key: string]: unknown;
}

export interface AiActDomainResult {
    key: AiActDomainKey;
    count: number;
    /** status → count tally, when the records expose a status/state field. */
    statuses: Record<string, number>;
}

function tallyStatuses(records: AiActRecord[]): Record<string, number> {
    const out: Record<string, number> = {};
    for (const r of records) {
        const s = (r.status ?? r.state) as string | null | undefined;
        if (typeof s === 'string' && s.length > 0) {
            out[s] = (out[s] ?? 0) + 1;
        }
    }
    return out;
}

export async function getAiActDomain(domain: AiActDomain): Promise<AiActDomainResult> {
    const { data } = await api.get<{ data?: AiActRecord[] }>(
        `/api/admin/ai-act-compliance/${domain.path}`,
    );
    const records = Array.isArray(data?.data) ? data.data : [];
    return { key: domain.key, count: records.length, statuses: tallyStatuses(records) };
}

export async function getAiActOverview(): Promise<AiActDomainResult[]> {
    return Promise.all(AI_ACT_DOMAINS.map((d) => getAiActDomain(d)));
}
