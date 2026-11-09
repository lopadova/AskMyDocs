import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { api } from '../../lib/api';
import { useAuthStore } from '../../lib/auth-store';
import { useTeamStore } from '../../lib/team-store';
import { RetrievalProfileBanner } from './RetrievalProfileBanner';

vi.mock('@tanstack/react-router', () => ({
    Link: ({ children, params, search, to: _to, ...rest }: { children: React.ReactNode; params: { teamHash: string }; search: { project: string }; to: string }) => (
        <a {...rest} href={`/app/${params.teamHash}/admin/kb/retrieval-profiles?project=${encodeURIComponent(search.project)}`}>{children}</a>
    ),
}));

beforeEach(() => {
    vi.restoreAllMocks();
    useAuthStore.setState({ roles: ['admin'], user: null });
    useTeamStore.setState({ currentTeam: 'wearframe', teams: [{ tenant_id: 'wearframe', hash: 'team-hash', name: 'WearFrame', projects: [] }] });
});

function mount(projectKeys = ['wearframe']) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const view = render(<QueryClientProvider client={client}><RetrievalProfileBanner projectKeys={projectKeys} /></QueryClientProvider>);
    return { ...view, client };
}

describe('RetrievalProfileBanner', () => {
    it('warns before a question is sent and links to the missing project in the active workspace', async () => {
        vi.spyOn(api, 'get').mockResolvedValue({ data: { required: true, profiles: [{ project_key: 'wearframe', configured: false }] } });
        mount();
        expect(await screen.findByRole('alert')).toHaveTextContent('wearframe');
        expect(screen.getByRole('link', { name: 'Complete research profile' })).toHaveAttribute('href', '/app/team-hash/admin/kb/retrieval-profiles?project=wearframe');
    });

    it('removes the banner when the profile is saved and status is invalidated', async () => {
        const get = vi.spyOn(api, 'get').mockResolvedValue({ data: { required: true, profiles: [{ project_key: 'wearframe', configured: false }] } });
        const { client } = mount();
        await screen.findByRole('alert');
        get.mockResolvedValue({ data: { required: true, profiles: [{ project_key: 'wearframe', configured: true }] } });
        await client.invalidateQueries({ queryKey: ['chat-retrieval-profile-status'] });
        await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
    });

    it('explains who can configure the profile without linking viewers to a forbidden page', async () => {
        useAuthStore.setState({ roles: ['viewer'] });
        vi.spyOn(api, 'get').mockResolvedValue({ data: { required: true, profiles: [{ project_key: 'wearframe', configured: false }] } });
        mount();
        expect(await screen.findByRole('alert')).toHaveTextContent('Ask a workspace administrator');
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('does not label an unavailable status endpoint as a missing profile', async () => {
        vi.spyOn(api, 'get').mockRejectedValue(new Error('Network unavailable'));
        const { client } = mount();
        await waitFor(() => expect(client.getQueryCache().getAll()[0]?.state.status).toBe('error'));
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('isolates readiness by workspace instead of reusing another workspace’s profile', async () => {
        vi.spyOn(api, 'get').mockResolvedValue({ data: { required: true, profiles: [{ project_key: 'wearframe', configured: false }] } });
        const { client } = mount();
        client.setQueryData(['chat-retrieval-profile-status', 'another-tenant', ['wearframe']], { required: true, profiles: [{ project_key: 'wearframe', configured: true }] });
        expect(await screen.findByRole('alert')).toHaveTextContent('wearframe');
    });
});
