import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { InviteView } from './InviteView';
import { api } from '../../../lib/api';

const mockGet = vi.fn();
const mockPost = vi.fn();
const mockPatch = vi.fn();

beforeEach(() => {
    vi.spyOn(api, 'get').mockImplementation(mockGet);
    vi.spyOn(api, 'post').mockImplementation(mockPost);
    vi.spyOn(api, 'patch').mockImplementation(mockPatch);
});

afterEach(() => {
    vi.restoreAllMocks();
    mockGet.mockReset();
    mockPost.mockReset();
    mockPatch.mockReset();
});

function withQueryClient(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

/**
 * URL-aware GET stub: campaigns empty, grant option sources populated. Used by
 * the tests that open the campaign dialog (which fetches roles + projects).
 */
function getWithGrantSources(url: string) {
    if (url.includes('/api/admin/roles')) {
        return Promise.resolve({ data: { data: [{ name: 'editor' }, { name: 'viewer' }] } });
    }
    if (url.includes('/api/admin/kb/projects')) {
        return Promise.resolve({ data: { projects: ['docs', 'wiki'] } });
    }
    return Promise.resolve({ data: { data: [] } });
}

describe('InviteView', () => {
    it('shows the loading state initially', () => {
        mockGet.mockImplementation(() => new Promise(() => {}));
        render(withQueryClient(<InviteView />));
        expect(screen.getByTestId('admin-invite-loading')).toBeVisible();
    });

    it('shows the empty state when there are no campaigns', async () => {
        mockGet.mockImplementation(getWithGrantSources);
        render(withQueryClient(<InviteView />));
        const empty = await screen.findByTestId('admin-invite-empty');
        expect(empty).toHaveAttribute('data-state', 'empty');
    });

    it('opens the create dialog with proper dialog semantics', async () => {
        mockGet.mockImplementation(getWithGrantSources);
        render(withQueryClient(<InviteView />));
        await screen.findByTestId('admin-invite-empty');

        await userEvent.click(screen.getByTestId('admin-invite-create'));

        const dialog = screen.getByTestId('admin-invite-form');
        expect(dialog).toHaveAttribute('data-mode', 'create');
        expect(dialog).toHaveAttribute('aria-modal', 'true');
        expect(dialog).toHaveAttribute('role', 'dialog');
    });

    it('renders the grant editor with DB-derived roles and projects', async () => {
        mockGet.mockImplementation(getWithGrantSources);
        render(withQueryClient(<InviteView />));
        await screen.findByTestId('admin-invite-empty');

        await userEvent.click(screen.getByTestId('admin-invite-create'));

        expect(screen.getByTestId('admin-invite-form-grant')).toBeVisible();

        // Role select is DB-derived and never offers super-admin.
        const roleSelect = screen.getByTestId('admin-invite-form-grant-role');
        await waitFor(() => expect(roleSelect).toHaveTextContent('editor'));
        expect(roleSelect).toHaveTextContent('viewer');
        expect(roleSelect).not.toHaveTextContent('super-admin');

        // Project checkboxes come from the tenant's project list.
        await waitFor(() => expect(screen.getByTestId('admin-invite-form-grant-project-docs')).toBeInTheDocument());
        expect(screen.getByTestId('admin-invite-form-grant-project-wiki')).toBeInTheDocument();
    });

    it('posts the grant when creating a campaign with a role + projects', async () => {
        mockGet.mockImplementation(getWithGrantSources);
        mockPost.mockResolvedValue({ data: { data: { id: 1 } } });
        render(withQueryClient(<InviteView />));
        await screen.findByTestId('admin-invite-empty');

        await userEvent.click(screen.getByTestId('admin-invite-create'));
        await userEvent.type(screen.getByTestId('admin-invite-form-key'), 'beta-grant');
        await userEvent.type(screen.getByTestId('admin-invite-form-name'), 'Beta Grant');
        await waitFor(() => expect(screen.getByTestId('admin-invite-form-grant-project-docs')).toBeInTheDocument());
        await userEvent.selectOptions(screen.getByTestId('admin-invite-form-grant-role'), 'editor');
        await userEvent.click(screen.getByTestId('admin-invite-form-grant-project-docs'));
        await userEvent.click(screen.getByTestId('admin-invite-form-submit'));

        await waitFor(() => expect(mockPost).toHaveBeenCalled());
        const [, body] = mockPost.mock.calls[0];
        expect(body.grant).toEqual({ role: 'editor', projects: ['docs'], project_role: 'member' });
    });

    it('renders campaign rows when the API returns data', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url.includes('/api/admin/invite/campaigns')) {
                return Promise.resolve({
                    data: {
                        data: [
                            { id: 7, key: 'beta', name: 'Beta', description: null, type: 'multi_use', status: 'active', max_redemptions_total: null, per_user_limit: 1, starts_at: null, ends_at: null, reward_policy: null, grant: null, created_by: 1 },
                        ],
                    },
                });
            }
            return getWithGrantSources(url);
        });
        render(withQueryClient(<InviteView />));

        const row = await screen.findByTestId('admin-invite-campaign-row-7');
        expect(row).toHaveAttribute('data-campaign-key', 'beta');
        expect(screen.getByTestId('admin-invite-campaign-row-7-status')).toHaveTextContent('active');
    });

    it('switches to the metrics tab and renders the metric cards', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url.includes('/api/admin/invite/metrics')) {
                return Promise.resolve({
                    data: {
                        data: {
                            codes_issued: 4, redemptions: 2, invites_sent: 0, invites_accepted: 0,
                            referrals_qualified: 1, distinct_referrers: 1, k_factor: 1, acceptance_rate: 0,
                            conversion_rate: 0.5, ttr_p50_seconds: null, ttr_p90_seconds: null,
                        },
                    },
                });
            }
            return getWithGrantSources(url);
        });
        render(withQueryClient(<InviteView />));
        await screen.findByTestId('admin-invite-empty');

        await userEvent.click(screen.getByTestId('admin-invite-tab-metrics'));

        await waitFor(() => expect(screen.getByTestId('admin-invite-metrics-grid')).toHaveAttribute('data-state', 'ready'));
        expect(screen.getByTestId('admin-invite-metric-conversion_rate')).toHaveTextContent('50.0%');
        expect(screen.getByTestId('admin-invite-metric-redemptions')).toHaveTextContent('2');
    });
});
