import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTeamStore } from '../../../lib/team-store';

import {
    deleteMcpServer,
    mcpAdminCapabilities,
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

export function useMcpCapabilities() {
    const tenant = useTeamStore((state) => state.currentTeam);
    return useQuery({ queryKey: [...MCP_KEY, tenant, 'capabilities'], queryFn: () => mcpAdminCapabilities(tenant), retry: false });
}

export function useMcpServers() {
    const tenant = useTeamStore((state) => state.currentTeam);
    return useQuery<McpServerEntry[]>({
        queryKey: [...MCP_KEY, tenant, 'servers'],
        queryFn: () => listMcpServers(tenant),
        staleTime: 30_000,
    });
}

export function useRegisterMcpServer() {
    const tenant = useTeamStore((state) => state.currentTeam);
    const queryClient = useQueryClient();
    return useMutation<McpServerEntry, Error, McpRegisterPayload>({
        mutationFn: (value) => registerMcpServer(value, tenant),
        gcTime: 0,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: [...MCP_KEY, tenant, 'servers'] });
        },
    });
}

export function useHandshakeMcpServer() {
    const tenant = useTeamStore((state) => state.currentTeam);
    const queryClient = useQueryClient();
    return useMutation<McpServerEntry, Error, number>({
        mutationFn: (value) => handshakeMcpServer(value, tenant),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: [...MCP_KEY, tenant, 'servers'] });
        },
    });
}

export function useUpdateMcpServerTools() {
    const tenant = useTeamStore((state) => state.currentTeam);
    const queryClient = useQueryClient();
    return useMutation<McpServerEntry, Error, { id: number; enabledTools: string[] }>({
        mutationFn: ({ id, enabledTools }) => updateMcpServerTools(id, enabledTools, tenant),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: [...MCP_KEY, tenant, 'servers'] });
        },
    });
}

export function useDisableMcpServer() {
    const tenant = useTeamStore((state) => state.currentTeam);
    const queryClient = useQueryClient();
    return useMutation<McpServerEntry, Error, number>({
        mutationFn: (value) => disableMcpServer(value, tenant),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: [...MCP_KEY, tenant, 'servers'] });
        },
    });
}

export function useDeleteMcpServer() {
    const tenant = useTeamStore((state) => state.currentTeam);
    const queryClient = useQueryClient();
    return useMutation<void, Error, number>({
        mutationFn: (value) => deleteMcpServer(value, tenant),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: [...MCP_KEY, tenant, 'servers'] });
        },
    });
}

export function useMcpAudit(filters: McpAuditFilters) {
    const tenant = useTeamStore((state) => state.currentTeam);
    return useQuery<McpAuditListResponse>({
        queryKey: [...MCP_KEY, tenant, 'audit', filters],
        queryFn: () => listMcpAudit(filters, tenant),
        staleTime: 15_000,
    });
}
