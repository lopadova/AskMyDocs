import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { api } from '../../lib/api';
import { McpConnectionsPanel, type McpConnectionsPanelProps } from './McpConnectionsPanel';
import type { McpConnectionDto } from './mcp-connections.api';

function renderPanel(props: Partial<McpConnectionsPanelProps> = {}) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });

    return render(
        <QueryClientProvider client={client}>
            <McpConnectionsPanel scope="personal" {...props} />
        </QueryClientProvider>,
    );
}

beforeEach(() => {
    vi.spyOn(api, 'get').mockResolvedValue({ data: [] } as never);
    vi.spyOn(api, 'post').mockResolvedValue({ data: {} } as never);
    window.history.replaceState({}, '', '/app/connected-apps');
});

afterEach(() => vi.restoreAllMocks());

describe('McpConnectionsPanel OAuth onboarding', () => {
    it('offers explicit authentication methods and only reveals the bearer secret when selected', async () => {
        const user = userEvent.setup();
        renderPanel();
        await waitFor(() => expect(screen.getByTestId('mcp-connections-personal')).toHaveAttribute('data-state', 'ready'));

        await user.click(screen.getByRole('button', { name: 'Add MCP connection' }));

        expect(screen.getByRole('radio', { name: /^OAuth/ })).toBeChecked();
        expect(screen.getByRole('button', { name: 'Continue with OAuth' })).toBeInTheDocument();
        expect(screen.queryByLabelText('Bearer token', { selector: 'input[type="password"]' })).toBeNull();

        await user.click(screen.getByRole('radio', { name: /^Bearer token/ }));
        expect(screen.getByLabelText('Bearer token', { selector: 'input[type="password"]' })).toBeRequired();
        expect(screen.getByRole('button', { name: 'Connect and discover' })).toBeInTheDocument();

        await user.click(screen.getByRole('radio', { name: /^No authentication/ }));
        expect(screen.queryByLabelText('Bearer token', { selector: 'input[type="password"]' })).toBeNull();
    });

    it('submits a manual bearer token only with bearer authentication', async () => {
        const user = userEvent.setup();
        renderPanel();
        await waitFor(() => expect(screen.getByTestId('mcp-connections-personal')).toHaveAttribute('data-state', 'ready'));
        await user.click(screen.getByRole('button', { name: 'Add MCP connection' }));
        await user.click(screen.getByRole('radio', { name: /^Bearer token/ }));
        await user.type(screen.getByLabelText('Name'), 'Orders MCP');
        await user.type(screen.getByLabelText('MCP endpoint'), 'https://mcp.example.test/mcp');
        await user.type(screen.getByLabelText('Bearer token', { selector: 'input[type="password"]' }), 'secret-token');
        await user.click(screen.getByRole('button', { name: 'Connect and discover' }));

        await waitFor(() => expect(api.post).toHaveBeenCalledWith('/api/me/connected-apps/mcp', expect.objectContaining({
            auth_method: 'bearer',
            bearer: 'secret-token',
            ui_destination: '/app/connected-apps',
        })));
    });

    it('shows a safe cancellation result and removes OAuth callback parameters from the URL', async () => {
        window.history.replaceState({}, '', '/app/connected-apps?mcp=oauth_denied&mcp_connection=01TEST&keep=yes');
        renderPanel();

        expect(await screen.findByText('OAuth sign-in was cancelled. No credentials were saved.')).toBeInTheDocument();
        await waitFor(() => expect(window.location.search).toBe('?keep=yes'));
        expect(screen.getByText('OAuth sign-in was cancelled. No credentials were saved.')).toBeInTheDocument();
    });

    it('loads the project selector from the real project options', async () => {
        const user = userEvent.setup();
        renderPanel({ projects: [{ project_key: 'support', name: 'Customer Support' }] });
        await waitFor(() => expect(screen.getByTestId('mcp-connections-personal')).toHaveAttribute('data-state', 'ready'));

        await user.click(screen.getByRole('button', { name: 'Add MCP connection' }));
        const project = screen.getByRole('combobox', { name: /Project \(optional\)/ });
        expect(project).toHaveRole('combobox');
        expect(screen.getByRole('option', { name: 'Customer Support · support' })).toBeInTheDocument();
        await user.selectOptions(project, 'support');
        expect(project).toHaveValue('support');

        await user.type(screen.getByLabelText('Name'), 'Support MCP');
        await user.type(screen.getByLabelText('MCP endpoint', { exact: true }), 'https://mcp.example.test/rpc');
        await user.click(screen.getByRole('button', { name: 'Continue with OAuth' }));
        await waitFor(() => expect(api.post).toHaveBeenCalledWith('/api/me/connected-apps/mcp', expect.objectContaining({
            project_key: 'support',
        })));
    });

    it('keeps tool and resource catalogs collapsed until the connection is expanded', async () => {
        const user = userEvent.setup();
        vi.mocked(api.get).mockResolvedValueOnce({ data: [connectionFixture] } as never);
        renderPanel();

        const card = await screen.findByTestId('mcp-connection-01MCP');
        expect(card).toHaveTextContent('2 tools · 1 enabled');
        expect(card).toHaveTextContent('1 resource · 1 enabled');
        expect(screen.queryByText('Search documents')).toBeNull();

        await user.click(screen.getByRole('button', { name: 'Expand Docs MCP' }));
        expect(screen.getByText('Search documents')).toBeInTheDocument();
        expect(screen.getByText('Employee handbook')).toBeInTheDocument();
    });
});

const connectionFixture: McpConnectionDto = {
    id: 1,
    public_id: '01MCP',
    mode: 'personal',
    label: 'Docs MCP',
    project_key: 'support',
    status: 'active',
    granted_scopes_json: null,
    error_json: null,
    connector_installation_id: null,
    server: {
        id: 1,
        name: 'docs',
        endpoint: 'https://mcp.example.test/rpc',
        transport: 'streamable_http',
        auth_mode: 'oauth',
        negotiated_era: 'modern',
        negotiated_version: '2026-07-28',
        status: 'active',
    },
    tools: [
        { id: 1, remote_name: 'docs.search', local_name: 'docs_search', title: 'Search documents', description: null, risk: 'read', enabled: true, confirmation_required: false, removed_at: null },
        { id: 2, remote_name: 'docs.update', local_name: 'docs_update', title: 'Update documents', description: null, risk: 'write', enabled: false, confirmation_required: true, removed_at: null },
    ],
    resources: [
        { id: 3, uri: 'docs://handbook', name: 'handbook', title: 'Employee handbook', description: null, mime_type: 'text/markdown', size: 120, enabled: true, last_ingested_at: null, removed_at: null, ingest_error_json: null },
    ],
};
