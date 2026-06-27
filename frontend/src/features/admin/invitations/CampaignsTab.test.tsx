import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { CampaignsTab } from './CampaignsTab';
import { api } from '../../../lib/api';

const mockGet = vi.fn();
const mockPost = vi.fn();

const CAMPAIGNS = [
    { id: 1, key: 'beta', name: 'Beta wave', type: 'multi_use', status: 'active', max_redemptions_total: null, starts_at: null, ends_at: null, grant: null },
];

beforeEach(() => {
    mockGet.mockReset();
    mockPost.mockReset();
    mockGet.mockImplementation((url: string) => {
        if (url.endsWith('/campaigns')) return Promise.resolve({ data: { data: CAMPAIGNS } });
        if (url.endsWith('/tenants')) return Promise.resolve({ data: { data: [{ id: 'default', name: 'Default' }] } });
        return Promise.resolve({ data: { data: [] } });
    });
    vi.spyOn(api, 'get').mockImplementation(mockGet);
    vi.spyOn(api, 'post').mockImplementation(mockPost);
});

afterEach(() => {
    vi.restoreAllMocks();
});

function withQueryClient(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

describe('CampaignsTab', () => {
    it('lists campaigns with an edit affordance', async () => {
        render(withQueryClient(<CampaignsTab />));
        await waitFor(() => expect(screen.getByTestId('admin-invitations-campaigns-row-1')).toBeVisible());
        expect(screen.getByTestId('admin-invitations-campaigns-row-1-edit')).toBeVisible();
    });

    it('New campaign opens the create drawer', async () => {
        render(withQueryClient(<CampaignsTab />));
        await waitFor(() => expect(screen.getByTestId('admin-invitations-campaigns-row-1')).toBeVisible());
        await userEvent.click(screen.getByTestId('admin-invitations-campaigns-new'));
        const drawer = screen.getByTestId('admin-invitations-campaign-drawer');
        expect(drawer).toHaveAttribute('aria-modal', 'true');
        expect(drawer).toHaveAttribute('role', 'dialog');
    });

    it('rejects an invalid key inline without POSTing (R16 failure path fires)', async () => {
        render(withQueryClient(<CampaignsTab />));
        await waitFor(() => expect(screen.getByTestId('admin-invitations-campaigns-row-1')).toBeVisible());
        await userEvent.click(screen.getByTestId('admin-invitations-campaigns-new'));

        fireEvent.change(screen.getByTestId('admin-invitations-campaign-key'), { target: { value: 'Bad Key!' } });
        fireEvent.change(screen.getByTestId('admin-invitations-campaign-name'), { target: { value: 'X' } });
        await userEvent.click(screen.getByTestId('admin-invitations-campaign-submit'));

        expect(screen.getByTestId('campaign-key-error')).toBeVisible();
        expect(mockPost).not.toHaveBeenCalled();
    });

    it('refuses a super-admin grant inline (no priv-esc) without POSTing', async () => {
        render(withQueryClient(<CampaignsTab />));
        await waitFor(() => expect(screen.getByTestId('admin-invitations-campaigns-row-1')).toBeVisible());
        await userEvent.click(screen.getByTestId('admin-invitations-campaigns-new'));

        fireEvent.change(screen.getByTestId('admin-invitations-campaign-key'), { target: { value: 'launch-wave' } });
        fireEvent.change(screen.getByTestId('admin-invitations-campaign-name'), { target: { value: 'Launch' } });
        fireEvent.change(screen.getByTestId('admin-invitations-campaign-grant-role'), { target: { value: 'super-admin' } });
        await userEvent.click(screen.getByTestId('admin-invitations-campaign-submit'));

        expect(screen.getByTestId('admin-invitations-campaign-grant-role-error')).toBeVisible();
        expect(mockPost).not.toHaveBeenCalled();
    });

    it('creates a campaign with the exact payload the package expects', async () => {
        mockPost.mockResolvedValue({ data: { data: { id: 9, key: 'launch-wave', name: 'Launch', type: 'single_use', status: 'draft' } } });
        render(withQueryClient(<CampaignsTab />));
        await waitFor(() => expect(screen.getByTestId('admin-invitations-campaigns-row-1')).toBeVisible());
        await userEvent.click(screen.getByTestId('admin-invitations-campaigns-new'));

        fireEvent.change(screen.getByTestId('admin-invitations-campaign-key'), { target: { value: 'launch-wave' } });
        fireEvent.change(screen.getByTestId('admin-invitations-campaign-name'), { target: { value: 'Launch' } });
        await userEvent.click(screen.getByTestId('admin-invitations-campaign-submit'));

        await waitFor(() => {
            expect(mockPost).toHaveBeenCalledWith('/api/admin/invitations/campaigns', {
                key: 'launch-wave',
                name: 'Launch',
                description: null,
                type: 'single_use',
                status: 'draft',
                max_redemptions_total: null,
                per_user_limit: null,
                starts_at: null,
                ends_at: null,
                grant: null,
            });
        });
    });
});
