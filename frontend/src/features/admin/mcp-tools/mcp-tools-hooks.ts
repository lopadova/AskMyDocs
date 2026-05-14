import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import {
    deleteMcpServer,
    disableMcpServer,
    handshakeMcpServer,
    listMcpAudit,
    listMcpServers,
    registerMcpServer,
    type McpAuditFilters,
    type McpAuditListResponse,
    type McpRegisterPayload,
    type McpServerEntry,
    updateMcpServerTools,
} from './mcp-tools.api';

/*
 * v5.0/W2 — TanStack Query hooks for MCP admin endpoints. Single shared
 * partition `['admin', 'mcp']`; every mutation invalidates the list query
 * because the server is the source of truth for `status`, `enabled_tools`,
 * and `handshake_response`. No optimistic updates — MCP ops are rare and
 * each one returns the updated row.
 */

export const MCP_KEY = ['admin', 'mcp'] as const;

export function useMcpServers() {
    return useQuery<McpServerEntry[]>({
        queryKey: [...MCP_KEY, 'servers'],
        queryFn: listMcpServers,
        staleTime: 30_000,
    });
}

export function useRegisterMcpServer() {
    const queryClient = useQueryClient();
    return useMutation<McpServerEntry, Error, McpRegisterPayload>({
        mutationFn: registerMcpServer,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: [...MCP_KEY, 'servers'] });
        },
    });
}

export function useHandshakeMcpServer() {
    const queryClient = useQueryClient();
    return useMutation<McpServerEntry, Error, number>({
        mutationFn: handshakeMcpServer,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: [...MCP_KEY, 'servers'] });
        },
    });
}

export function useUpdateMcpServerTools() {
    const queryClient = useQueryClient();
    return useMutation<McpServerEntry, Error, { id: number; enabledTools: string[] }>({
        mutationFn: ({ id, enabledTools }) => updateMcpServerTools(id, enabledTools),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: [...MCP_KEY, 'servers'] });
        },
    });
}

export function useDisableMcpServer() {
    const queryClient = useQueryClient();
    return useMutation<McpServerEntry, Error, number>({
        mutationFn: disableMcpServer,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: [...MCP_KEY, 'servers'] });
        },
    });
}

export function useDeleteMcpServer() {
    const queryClient = useQueryClient();
    return useMutation<void, Error, number>({
        mutationFn: deleteMcpServer,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: [...MCP_KEY, 'servers'] });
        },
    });
}

export function useMcpAudit(filters: McpAuditFilters) {
    return useQuery<McpAuditListResponse>({
        queryKey: [...MCP_KEY, 'audit', filters],
        queryFn: () => listMcpAudit(filters),
        staleTime: 15_000,
    });
}
