import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { TeamsList } from './TeamsList';
import { api } from '../../../lib/api';
import { useAuthStore } from '../../../lib/auth-store';

const mockGet = vi.fn();
const mockPost = vi.fn();
const mockPatch = vi.fn();
const mockDelete = vi.fn();

/** Minimal valid `/api/auth/me` payload for the post-mutation switcher sync. */
const ME_FIXTURE = {
    user: { id: 1, name: 'Admin', email: 'admin@demo.local', email_verified_at: null },
    roles: ['admin'],
    permissions: [],
    projects: [],
    teams: [],
    features: {},
};

const TEAMS_FIXTURE = [
    { slug: 'default', name: 'Default', hash: '37a8eec1ce19', status: 'system', is_default: true, can_manage: false, logo_url: null, project_count: 1, member_count: 1 },
    { slug: 'acme', name: 'Acme Corp', hash: '822b33ad87c1', status: 'active', is_default: false, can_manage: true, logo_url: null, project_count: 2, member_count: 3 },
];

/** Route api.get by URL: the me() switcher-sync vs the teams list. */
function routeGet(teams: unknown) {
    return (url: string) => {
        if (url === '/api/auth/me') return Promise.resolve({ data: ME_FIXTURE });
        return Promise.resolve({ data: { data: teams } });
    };
}

beforeEach(() => {
    mockGet.mockReset();
    mockPost.mockReset();
    mockPatch.mockReset();
    mockDelete.mockReset();
    vi.spyOn(api, 'get').mockImplementation(mockGet);
    vi.spyOn(api, 'post').mockImplementation(mockPost);
    vi.spyOn(api, 'patch').mockImplementation(mockPatch);
    vi.spyOn(api, 'delete').mockImplementation(mockDelete);
});

afterEach(() => {
    vi.restoreAllMocks();
    useAuthStore.getState().clear();
});

function withQueryClient(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

describe('TeamsList', () => {
    it('renders the loading state initially', () => {
        mockGet.mockImplementation(() => new Promise(() => {}));
        render(withQueryClient(<TeamsList />));
        expect(screen.getByTestId('admin-teams-loading')).toHaveAttribute('data-state', 'loading');
    });

    it('renders the empty state when there are no teams', async () => {
        mockGet.mockImplementation(routeGet([]));
        render(withQueryClient(<TeamsList />));
        const empty = await screen.findByTestId('admin-teams-empty');
        expect(empty).toBeVisible();
        expect(empty).toHaveAttribute('data-state', 'empty');
    });

    it('renders one row per team with slug, status and counts', async () => {
        mockGet.mockImplementation(routeGet(TEAMS_FIXTURE));
        render(withQueryClient(<TeamsList />));
        await waitFor(() => expect(screen.getByTestId('admin-team-row-acme')).toBeVisible());

        expect(screen.getByTestId('admin-team-row-acme')).toHaveAttribute('data-team-slug', 'acme');
        expect(screen.getByTestId('admin-team-row-acme-projects')).toHaveTextContent('2');
        expect(screen.getByTestId('admin-team-row-acme-members')).toHaveTextContent('3');
        expect(screen.getByTestId('admin-teams-count')).toHaveTextContent('2 total');
    });

    it('exposes Rename only for manageable teams — default is read-only', async () => {
        mockGet.mockImplementation(routeGet(TEAMS_FIXTURE));
        render(withQueryClient(<TeamsList />));
        await waitFor(() => expect(screen.getByTestId('admin-team-row-acme')).toBeVisible());

        expect(screen.getByTestId('admin-team-row-acme-edit')).toBeVisible();
        expect(screen.getByTestId('admin-team-row-acme-logo')).toBeVisible();
        expect(screen.queryByTestId('admin-team-row-default-edit')).not.toBeInTheDocument();
    });

    it('uploads a tenant logo from the logo dialog', async () => {
        mockGet.mockImplementation(routeGet(TEAMS_FIXTURE));
        mockPost.mockResolvedValue({ data: { data: { logo_url: '/api/tenant-logos/acme' } } });
        render(withQueryClient(<TeamsList />));
        await screen.findByTestId('admin-team-row-acme');

        await userEvent.click(screen.getByTestId('admin-team-row-acme-logo'));
        expect(await screen.findByTestId('admin-team-logo-dialog')).toHaveAttribute('data-state', 'ready');
        const file = new File(['png'], 'logo.png', { type: 'image/png' });
        await userEvent.upload(screen.getByTestId('admin-team-logo-file'), file);
        fireEvent.submit(screen.getByTestId('admin-team-logo-dialog'));

        await waitFor(() => {
            expect(mockPost).toHaveBeenCalledWith(
                '/api/admin/teams/acme/logo',
                expect.any(FormData),
            );
        });
    });

    it('filters rows by free-text across name/slug', async () => {
        mockGet.mockImplementation(routeGet(TEAMS_FIXTURE));
        render(withQueryClient(<TeamsList />));
        await waitFor(() => expect(screen.getByTestId('admin-team-row-acme')).toBeVisible());

        await userEvent.type(screen.getByTestId('admin-teams-filter'), 'acme');
        expect(screen.queryByTestId('admin-team-row-default')).not.toBeInTheDocument();
        expect(screen.getByTestId('admin-team-row-acme')).toBeVisible();
    });

    it('creates a team (auto-slug) and re-syncs the switcher via /api/auth/me', async () => {
        mockGet.mockImplementation(routeGet([]));
        mockPost.mockResolvedValue({
            data: { data: { slug: 'new-team', name: 'New Team', hash: 'x', status: 'active', is_default: false, can_manage: true, project_count: 1, member_count: 1 } },
        });
        render(withQueryClient(<TeamsList />));
        await screen.findByTestId('admin-teams-empty');

        await userEvent.click(screen.getByTestId('admin-teams-create'));
        const dialog = await screen.findByTestId('admin-team-form');
        expect(dialog).toHaveAttribute('data-mode', 'create');

        await userEvent.type(screen.getByTestId('admin-team-form-name'), 'New Team');
        // The slug mirrors the slugified name while untouched.
        expect(screen.getByTestId('admin-team-form-slug')).toHaveValue('new-team');

        await userEvent.click(screen.getByTestId('admin-team-form-submit'));

        await waitFor(() => {
            expect(mockPost).toHaveBeenCalledWith(
                '/api/admin/teams',
                expect.objectContaining({ name: 'New Team', slug: 'new-team' }),
            );
        });
        // The switcher is re-synced from /api/auth/me after a successful create.
        await waitFor(() => expect(mockGet).toHaveBeenCalledWith('/api/auth/me'));
    });

    it('renames a manageable team (slug read-only, PATCH with the new name)', async () => {
        mockGet.mockImplementation(routeGet(TEAMS_FIXTURE));
        mockPatch.mockResolvedValue({
            data: { data: { ...TEAMS_FIXTURE[1], name: 'Acme Corporation' } },
        });
        render(withQueryClient(<TeamsList />));
        await waitFor(() => expect(screen.getByTestId('admin-team-row-acme')).toBeVisible());

        await userEvent.click(screen.getByTestId('admin-team-row-acme-edit'));
        const dialog = await screen.findByTestId('admin-team-form');
        expect(dialog).toHaveAttribute('data-mode', 'edit');
        expect(screen.getByTestId('admin-team-form-slug')).toHaveAttribute('readonly');
        expect(screen.getByTestId('admin-team-form-name')).toHaveValue('Acme Corp');

        await userEvent.clear(screen.getByTestId('admin-team-form-name'));
        await userEvent.type(screen.getByTestId('admin-team-form-name'), 'Acme Corporation');
        await userEvent.click(screen.getByTestId('admin-team-form-submit'));

        await waitFor(() => {
            expect(mockPatch).toHaveBeenCalledWith(
                '/api/admin/teams/acme',
                { name: 'Acme Corporation' },
            );
        });
    });

    it('surfaces a 422 field error next to the name input', async () => {
        mockGet.mockImplementation(routeGet([]));
        mockPost.mockRejectedValue({
            isAxiosError: true,
            response: {
                status: 422,
                data: { message: 'The team name is required.', errors: { name: ['The team name is required.'] } },
            },
        });
        render(withQueryClient(<TeamsList />));
        await screen.findByTestId('admin-teams-empty');

        await userEvent.click(screen.getByTestId('admin-teams-create'));
        await screen.findByTestId('admin-team-form');
        await userEvent.type(screen.getByTestId('admin-team-form-name'), 'x');
        await userEvent.click(screen.getByTestId('admin-team-form-submit'));

        const err = await screen.findByTestId('admin-team-form-name-error');
        expect(err).toHaveTextContent(/name is required/i);
    });
});
