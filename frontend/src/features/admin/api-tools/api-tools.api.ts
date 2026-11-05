import { api } from '../../../lib/api';
import type { ApiTool, ToolDefinition, ToolDetail, ToolIndex, ToolPayload } from './types';
const base = '/api/admin/api-tools';
export const apiToolsApi = {
    async list(projectKey?: string): Promise<ToolIndex> { return (await api.get<ToolIndex>(base, { params: { project_key: projectKey } })).data; },
    async show(id: number): Promise<ToolDetail> { return (await api.get<ToolDetail>(`${base}/${id}`)).data; },
    async save(id: number | null, payload: ToolPayload): Promise<ApiTool> {
        const response = id === null ? await api.post<{ tool: ApiTool }>(base, payload) : await api.put<{ tool: ApiTool }>(`${base}/${id}`, payload);
        return response.data.tool;
    },
    async generate(prompt: string, project_key: string): Promise<{ definition: ToolDefinition; name: string; description: string }> {
        return (await api.post(`${base}/generate`, { prompt, project_key })).data;
    },
    async action(id: number, action: 'validate' | 'publish' | 'disable' | 'enable' | 'test', body?: unknown): Promise<Record<string, unknown>> {
        return (await api.post(`${base}/${id}/${action}`, body)).data;
    },
    async export(id: number): Promise<unknown> { return (await api.get(`${base}/${id}/export`)).data; },
    async import(packageData: unknown, project_key: string): Promise<ApiTool> { return (await api.post(`${base}/import`, { package: packageData, project_key })).data.tool; },
    async cancel(id: string | number): Promise<unknown> { return (await api.post(`${base}/runs/${id}/cancel`)).data; },
};
