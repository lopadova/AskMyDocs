import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { api } from '../../lib/api';
import { McpConnectionsPanel } from './McpConnectionsPanel';

function renderPanel() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });

    return render(
        <QueryClientProvider client={client}>
            <McpConnectionsPanel scope="personal" />
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
});
