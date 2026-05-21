import { api } from '../../../lib/api';

export interface ComplianceReportListItem {
    id: number;
    tenant_id: string;
    period_start: string;
    period_end: string;
    hash_sha256: string;
    hash_hmac: string;
    generated_at: string | null;
    generated_by: number | null;
}

export interface ComplianceVerifyResponse {
    valid: boolean;
    expected_hash: { sha256: string; hmac: string };
    actual_hash: { sha256: string; hmac: string };
}

export const complianceReportsApi = {
    async list(tenantId?: string): Promise<ComplianceReportListItem[]> {
        const { data } = await api.get<{ data: ComplianceReportListItem[] }>('/api/admin/compliance/reports', {
            params: tenantId ? { tenant_id: tenantId } : undefined,
        });

        return data.data;
    },
    async generate(input: { tenant_id: string; period_start: string; period_end: string }): Promise<void> {
        await api.post('/api/admin/compliance/reports', input);
    },
    async verify(id: number): Promise<ComplianceVerifyResponse> {
        const { data } = await api.post<ComplianceVerifyResponse>(`/api/admin/compliance/reports/${id}/verify`);

        return data;
    },
};

