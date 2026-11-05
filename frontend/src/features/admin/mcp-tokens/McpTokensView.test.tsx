import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useTeamStore } from '../../../lib/team-store';
import { setLocalUiLocale } from '../../../lib/ui-locale';
import { McpTokensView } from './McpTokensView';
import { mcpTokensApi, type CreatedMcpToken, type McpTenantTokenRow } from './mcp-tokens.api';

const row: McpTenantTokenRow = { id: 1, label: 'Support client', token_last4: 'c123', scopes: ['mcp:read', 'mcp:tools:propose'],
    created_at: '2026-01-01T09:00:00Z', expires_at: null, last_used_at: null, revoked_at: null };
const created: CreatedMcpToken = { data: { ...row, id: 20, label: 'New application' }, plain_token: 'askmd_example_not_a_real_token' };
function mount() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
    return render(<QueryClientProvider client={client}><McpTokensView /></QueryClientProvider>);
}
function create() {
    fireEvent.change(screen.getByLabelText('Token name'), { target: { value: '  New application  ' } });
    fireEvent.click(screen.getByTestId('admin-mcp-tokens-mint'));
}
beforeEach(() => {
    vi.restoreAllMocks();
    setLocalUiLocale('en');
    useTeamStore.setState({ currentTeam: 'autry', teams: [{ tenant_id: 'autry', hash: 'autry', name: 'Autry', projects: [] }], userId: 1 });
    vi.spyOn(mcpTokensApi, 'list').mockResolvedValue([]);
    vi.spyOn(mcpTokensApi, 'create').mockResolvedValue(created);
    vi.spyOn(mcpTokensApi, 'revoke').mockResolvedValue();
});

describe('MCP token management', () => {
    it('explains the empty state and distinguishes an unavailable list from no tokens', async () => {
        vi.mocked(mcpTokensApi.list).mockRejectedValueOnce(new Error('offline'));
        mount();
        expect(screen.getByRole('status')).toHaveTextContent('Loading tokens');
        expect(await screen.findByRole('alert')).toHaveTextContent('Could not load tokens');
        fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
        expect(await screen.findByRole('heading', { name: 'No application tokens yet' })).toBeInTheDocument();
        expect(screen.getByTestId('admin-mcp-tokens-mint')).toBeDisabled();
    });

    it('creates with a local expiration date, preserves default scopes, and displays the secret once', async () => {
        mount();
        await screen.findByText('No application tokens yet');
        fireEvent.click(screen.getByText('Set an optional expiration date'));
        fireEvent.change(screen.getByLabelText(/Expiration date/), { target: { value: '2050-12-31T14:30' } });
        create();
        expect(await screen.findByDisplayValue(created.plain_token)).toBeInTheDocument();
        expect(mcpTokensApi.create).toHaveBeenCalledWith('autry', { label: 'New application', expires_at: new Date('2050-12-31T14:30').toISOString() });
        expect(screen.getByTestId('admin-mcp-tokens-mint')).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'I have saved the token' }));
        expect(screen.queryByDisplayValue(created.plain_token)).toBeNull();
    });

    it('does not claim copying succeeded when clipboard access fails', async () => {
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: vi.fn().mockRejectedValue(new Error('denied')) } });
        mount();
        create();
        await screen.findByDisplayValue(created.plain_token);
        fireEvent.click(screen.getByTestId('mcp-token-copy'));
        expect(await screen.findByRole('alert')).toHaveTextContent('copy it manually');
        expect(screen.queryByText('Token copied to the clipboard.')).toBeNull();
    });

    it('rejects a past expiration without issuing a create request', async () => {
        mount();
        fireEvent.click(screen.getByText('Set an optional expiration date'));
        fireEvent.change(screen.getByLabelText(/Expiration date/), { target: { value: '2020-01-01T12:00' } });
        create();
        expect(screen.getByRole('alert')).toHaveTextContent('Choose an expiration date in the future.');
        expect(mcpTokensApi.create).not.toHaveBeenCalled();
    });

    it('preserves the form when creation fails', async () => {
        vi.mocked(mcpTokensApi.create).mockRejectedValue(new Error('Creation unavailable'));
        mount(); create();
        expect(await screen.findByRole('alert')).toHaveTextContent('Creation unavailable');
        expect(screen.getByLabelText('Token name')).toHaveValue('  New application  ');
        expect(screen.queryByTestId('admin-mcp-tokens-plain')).toBeNull();
        expect(screen.getByTestId('admin-mcp-tokens-mint')).toBeEnabled();
    });

    it('copies the visible token and reports success only after the clipboard resolves', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
        mount(); create();
        await screen.findByDisplayValue(created.plain_token);
        fireEvent.click(screen.getByTestId('mcp-token-copy'));
        await screen.findByText('Token copied to the clipboard.');
        expect(writeText).toHaveBeenCalledWith(created.plain_token);
    });

    it('distinguishes active, expired and revoked tokens without revealing their full values', async () => {
        vi.mocked(mcpTokensApi.list).mockResolvedValue([row, { ...row, id: 2, label: 'Expired client', expires_at: '2020-01-01T00:00:00Z' }, { ...row, id: 3, label: 'Retired client', revoked_at: '2026-01-02T00:00:00Z' }]);
        mount();
        expect(await screen.findByText('Active', { exact: true })).toBeInTheDocument();
        expect(screen.getByText('Expired', { exact: true })).toBeInTheDocument();
        expect(screen.getByText('Revoked', { exact: true })).toBeInTheDocument();
        expect(within(screen.getByTestId('admin-mcp-tokens-row-1')).getByText('Never used')).toBeInTheDocument();
        expect(screen.queryByTestId('admin-mcp-tokens-revoke-3')).toBeNull();
        expect(screen.queryByTestId('admin-mcp-tokens-plain')).toBeNull();
    });

    it('revokes only after confirmation and updates the list', async () => {
        vi.mocked(mcpTokensApi.list).mockResolvedValue([row]);
        vi.mocked(mcpTokensApi.revoke).mockImplementation(async () => { vi.mocked(mcpTokensApi.list).mockResolvedValue([{ ...row, revoked_at: '2026-10-27T10:00:00Z' }]); });
        mount();
        fireEvent.click(await screen.findByTestId('admin-mcp-tokens-revoke-1'));
        expect(mcpTokensApi.revoke).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.queryByRole('dialog')).toBeNull();
        fireEvent.click(screen.getByTestId('admin-mcp-tokens-revoke-1'));
        fireEvent.click(screen.getByRole('button', { name: 'Revoke token' }));
        expect(await screen.findByTestId('admin-mcp-tokens-revoked-1')).toBeInTheDocument();
        expect(mcpTokensApi.revoke).toHaveBeenCalledWith('autry', 1);
    });

    it('clears the visible secret and roster when switching organization', async () => {
        vi.mocked(mcpTokensApi.list).mockResolvedValue([row]);
        mount(); create();
        await screen.findByDisplayValue(created.plain_token);
        vi.mocked(mcpTokensApi.list).mockResolvedValue([]);
        act(() => useTeamStore.setState({ currentTeam: 'other-team' }));
        await screen.findByText('No application tokens yet');
        expect(screen.queryByDisplayValue(created.plain_token)).toBeNull();
        expect(screen.queryByText('Support client')).toBeNull();
        expect(mcpTokensApi.list).toHaveBeenCalledWith('other-team');
    });

    it('keeps a late create response bound to its original organization', async () => {
        let complete!: (value: CreatedMcpToken) => void;
        vi.mocked(mcpTokensApi.create).mockReturnValue(new Promise((resolve) => { complete = resolve; }));
        mount(); create();
        await waitFor(() => expect(mcpTokensApi.create).toHaveBeenCalled());
        act(() => useTeamStore.setState({ currentTeam: 'other-team' }));
        await act(async () => complete(created));
        await screen.findByText('No application tokens yet');
        expect(screen.queryByDisplayValue(created.plain_token)).toBeNull();
    });
});
