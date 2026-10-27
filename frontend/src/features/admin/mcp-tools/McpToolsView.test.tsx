import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from '../../../lib/api';
import { useTeamStore } from '../../../lib/team-store';
import { setLocalUiLocale } from '../../../lib/ui-locale';
import { mcpOAuthNavigation } from '../../mcp-connections/mcp-connections.api';
import { McpToolsView } from './McpToolsView';

const flags = { connector_enabled: true, oauth_enabled: true, runtime_mode: 'active' };
function mount() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
    return render(<QueryClientProvider client={client}><McpToolsView /></QueryClientProvider>);
}
beforeEach(() => {
    vi.restoreAllMocks();
    setLocalUiLocale('en');
    window.history.replaceState({}, '', '/app/autry/admin/mcp-tools');
    useTeamStore.setState({ currentTeam: 'autry', teams: [{ tenant_id: 'autry', hash: 'autry', name: 'Autry', projects: [] }], userId: 1 });
    vi.spyOn(api, 'get').mockImplementation(async (url) => ({ data: url === '/api/admin/mcp-servers/capabilities' ? flags
        : url === '/api/admin/connectors/mcp' ? [] : { data: [] } }) as never);
    vi.spyOn(api, 'post').mockResolvedValue({ data: {} } as never);
    vi.spyOn(mcpOAuthNavigation, 'redirect').mockImplementation(() => undefined);
});
async function openConnection() {
    const button = screen.getByTestId('admin-mcp-tools-register');
    await waitFor(() => expect(button).toBeEnabled());
    fireEvent.click(button);
    await screen.findByRole('dialog', { name: 'New MCP connection' });
}
async function fillConnection() {
    const user = userEvent.setup();
    await user.type(screen.getByLabelText('Name'), 'Company files');
    await user.type(screen.getByLabelText('MCP endpoint'), 'https://mcp.example.test/mcp');
}

describe('MCP tools OAuth registration', () => {
    it('opens remote connection with OAuth selected and uses the canonical shared endpoint', async () => {
        vi.mocked(api.post).mockResolvedValue({ data: { connection: { public_id: '01FILES' }, next_action: { type: 'oauth_redirect', authorization_url: 'https://auth.example.test/authorize?state=example' } } } as never);
        mount(); await openConnection();
        expect(screen.getByRole('radio', { name: /^OAuth/ })).toBeChecked();
        expect(screen.queryByTestId('admin-mcp-register-auth-json')).toBeNull();
        await fillConnection();
        fireEvent.click(screen.getByRole('button', { name: 'Continue with OAuth' }));
        await waitFor(() => expect(mcpOAuthNavigation.redirect).toHaveBeenCalledWith('https://auth.example.test/authorize?state=example'));
        expect(api.post).toHaveBeenCalledWith('/api/admin/connectors/mcp', expect.objectContaining({
            name: 'Company files', endpoint: 'https://mcp.example.test/mcp', auth_method: 'oauth', transport: 'auto',
            ui_destination: '/app/autry/admin/mcp-tools', bearer: undefined,
        }), { headers: { 'X-Tenant-Id': 'autry' } });
    });

    it('offers the existing manual registry as an explicit secondary action', async () => {
        mount();
        fireEvent.click(screen.getByTestId('admin-mcp-tools-manual'));
        expect(await screen.findByRole('dialog', { name: 'Manual MCP registration' })).toBeInTheDocument();
        expect(screen.getByTestId('admin-mcp-register-transport')).toHaveAttribute('data-value', 'stdio');
        expect(screen.getByText('The command runs on the AskMyDocs server, not on your browser or computer.')).toBeInTheDocument();
    });

    it('reports a disabled connector without offering a broken OAuth action', async () => {
        vi.mocked(api.get).mockImplementation(async (url) => ({ data: url === '/api/admin/mcp-servers/capabilities' ? { ...flags, connector_enabled: false, runtime_mode: 'off' } : { data: [] } }) as never);
        mount();
        expect(await screen.findByText(/Remote connections are disabled/)).toBeInTheDocument();
        expect(screen.getByTestId('admin-mcp-tools-register')).toBeDisabled();
        expect(api.get).not.toHaveBeenCalledWith('/api/admin/connectors/mcp', expect.anything());
    });

    it('keeps Bearer connections usable when OAuth is disabled', async () => {
        vi.mocked(api.get).mockImplementation(async (url) => ({ data: url === '/api/admin/mcp-servers/capabilities' ? { ...flags, oauth_enabled: false } : url === '/api/admin/connectors/mcp' ? [] : { data: [] } }) as never);
        mount(); await openConnection();
        expect(screen.getByRole('radio', { name: /^OAuth/ })).toBeDisabled();
        expect(screen.getByRole('radio', { name: /^Bearer token/ })).toBeChecked();
        expect(screen.getByTestId('mcp-connection-bearer')).toBeRequired();
    });

    it('does not redirect a different organization when an earlier OAuth start finishes late', async () => {
        let complete!: (value: unknown) => void;
        vi.mocked(api.post).mockReturnValue(new Promise((resolve) => { complete = resolve; }) as never);
        mount(); await openConnection(); await fillConnection();
        fireEvent.click(screen.getByRole('button', { name: 'Continue with OAuth' }));
        await waitFor(() => expect(api.post).toHaveBeenCalled());
        act(() => useTeamStore.setState({ currentTeam: 'other-team' }));
        await act(async () => complete({ data: { next_action: { type: 'oauth_redirect', authorization_url: 'https://auth.example.test/old-organization' } } }));
        expect(mcpOAuthNavigation.redirect).not.toHaveBeenCalled();
        expect(screen.queryByDisplayValue('Company files')).toBeNull();
    });

    it('rejects non-HTTP OAuth navigation URLs', () => {
        vi.mocked(mcpOAuthNavigation.redirect).mockRestore();
        expect(() => mcpOAuthNavigation.redirect('javascript:alert(1)')).toThrow('Invalid OAuth authorization URL');
    });
});
