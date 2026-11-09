import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RetrievalProfilesView } from './RetrievalProfilesView';
import { getRetrievalProfiles, upsertRetrievalProfile, type RetrievalProfile } from './retrieval-profiles.api';
import { useAuthStore } from '../../../lib/auth-store';

vi.mock('./retrieval-profiles.api', () => ({ getRetrievalProfiles: vi.fn(), upsertRetrievalProfile: vi.fn() }));
const profile = (project_key: string): RetrievalProfile => ({ project_key, configured: false, company_context: '', glossary: [], relevant_entities: [], expected_facts: [], preferred_source_types: [], updated_at: null });

beforeEach(() => {
    vi.clearAllMocks();
    useAuthStore.setState({ user: null });
    vi.mocked(getRetrievalProfiles).mockResolvedValue({ profiles: [profile('first-project'), profile('wearframe')] });
});

describe('RetrievalProfilesView', () => {
    it('opens the project selected by the chat link and explains the required context and optional fields', async () => {
        const client = new QueryClient();
        render(<QueryClientProvider client={client}><RetrievalProfilesView initialProjectKey="wearframe" /></QueryClientProvider>);
        expect(await screen.findByTestId('admin-retrieval-profile-project')).toHaveTextContent('wearframe');
        expect(screen.getByRole('heading', { name: 'Help the chat understand your project' })).toBeInTheDocument();
        expect(screen.getByText(/The research profile explains your business context/)).toBeInTheDocument();
        expect(screen.getByTestId('admin-retrieval-profile-context')).toBeRequired();
        expect(screen.getByText(/The other fields are optional/)).toBeInTheDocument();
    });

    it('saves the linked project and refreshes chat readiness', async () => {
        const user = userEvent.setup();
        const client = new QueryClient();
        const invalidate = vi.spyOn(client, 'invalidateQueries');
        vi.mocked(upsertRetrievalProfile).mockResolvedValue({ ...profile('wearframe'), configured: true });
        render(<QueryClientProvider client={client}><RetrievalProfilesView initialProjectKey="wearframe" /></QueryClientProvider>);
        await user.type(await screen.findByTestId('admin-retrieval-profile-context'), 'Business context for the WearFrame project.');
        await user.click(screen.getByRole('button', { name: 'Save retrieval profile' }));
        await waitFor(() => expect(upsertRetrievalProfile).toHaveBeenCalledWith(expect.objectContaining({ project_key: 'wearframe', company_context: 'Business context for the WearFrame project.' }), expect.anything()));
        expect(invalidate).toHaveBeenCalledWith({ queryKey: ['chat-retrieval-profile-status'] });
        expect(await screen.findByText('Research profile saved. Return to the chat and send your question again.')).toBeInTheDocument();
    });
});
