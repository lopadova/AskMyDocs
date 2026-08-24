import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { WidgetKeysView } from './WidgetKeysView';
import { useKbProjects } from '../kb/kb-tree.api';

// Mock the api module
vi.mock('../../../lib/api', () => ({
    api: {
        get: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
    },
}));

vi.mock('../kb/kb-tree.api', () => ({
    useKbProjects: vi.fn(),
}));

import { api } from '../../../lib/api';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const mockedApi = api as any;
const mockedUseKbProjects = vi.mocked(useKbProjects);

function projectQuery(
    overrides: Record<string, unknown> = {},
): ReturnType<typeof useKbProjects> {
    return {
        data: { projects: ['main'] },
        isLoading: false,
        isError: false,
        isFetching: false,
        error: null,
        refetch: vi.fn(),
        ...overrides,
    } as unknown as ReturnType<typeof useKbProjects>;
}

function renderWithQuery(ui: React.ReactElement) {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

describe('WidgetKeysView', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockedUseKbProjects.mockReturnValue(projectQuery());
    });

    it('renders the view with testid (R11)', () => {
        mockedApi.get.mockResolvedValueOnce({ data: { data: [] } });
        renderWithQuery(<WidgetKeysView />);
        expect(screen.getByTestId('admin-widget-keys-view')).toBeDefined();
    });

    it('shows empty state when no keys', async () => {
        mockedApi.get.mockResolvedValueOnce({ data: { data: [] } });
        renderWithQuery(<WidgetKeysView />);
        await waitFor(() => {
            expect(screen.getByTestId('admin-widget-keys-empty')).toBeDefined();
        });
    });

    it('displays keys in a table', async () => {
        mockedApi.get.mockResolvedValueOnce({
            data: {
                data: [
                    {
                        id: 1,
                        label: 'Production',
                        public_key: 'pk_abc123',
                        project_key: 'main',
                        allowed_origins: ['https://example.com'],
                        rate_limit: 60,
                        skill: 'askmydocs-assistant@1',
                        is_active: true,
                        last_used_at: null,
                        sessions_count: 5,
                        created_at: '2026-05-30T00:00:00Z',
                        updated_at: '2026-05-30T00:00:00Z',
                    },
                ],
            },
        });

        renderWithQuery(<WidgetKeysView />);

        await waitFor(() => {
            expect(screen.getByTestId('admin-widget-keys-table')).toBeDefined();
        });
        expect(screen.getByText('Production')).toBeDefined();
        expect(screen.getByText('pk_abc123')).toBeDefined();
    });

    it('keeps origins, rate, host tools and user auth under the matching columns', async () => {
        mockedApi.get.mockResolvedValueOnce({
            data: {
                data: [
                    {
                        id: 31,
                        label: 'Authenticated',
                        public_key: 'pk_auth',
                        project_key: 'main',
                        allowed_origins: ['https://portal.example'],
                        rate_limit: 75,
                        skill: 'askmydocs-assistant@1',
                        host_tools_enabled: false,
                        user_auth_enabled: true,
                        identity_credential_version: 1,
                        is_active: true,
                        last_used_at: null,
                        sessions_count: 2,
                        created_at: '2026-05-30T00:00:00Z',
                        updated_at: '2026-05-30T00:00:00Z',
                    },
                ],
            },
        });

        renderWithQuery(<WidgetKeysView />);
        const row = await screen.findByTestId('admin-widget-keys-row-31');
        const cells = within(row).getAllByRole('cell');

        expect(cells[4]).toHaveTextContent('https://portal.example');
        expect(cells[5]).toHaveTextContent('75/min');
        expect(cells[6]).toHaveTextContent('Off');
        expect(cells[7]).toHaveTextContent('On');
        expect(
            within(row).getByRole('checkbox', {
                name: 'Authenticated users for Authenticated',
            }),
        ).toBeDefined();
    });

    it('states that an empty allowlist permits no browser origins', async () => {
        mockedApi.get.mockResolvedValueOnce({
            data: {
                data: [
                    {
                        id: 32,
                        label: 'Locked',
                        public_key: 'pk_locked',
                        project_key: 'main',
                        allowed_origins: [],
                        rate_limit: 60,
                        host_tools_enabled: false,
                        user_auth_enabled: false,
                        identity_credential_version: 0,
                        is_active: true,
                        last_used_at: null,
                        sessions_count: 0,
                        created_at: '2026-05-30T00:00:00Z',
                        updated_at: '2026-05-30T00:00:00Z',
                    },
                ],
            },
        });

        renderWithQuery(<WidgetKeysView />);

        expect(
            await screen.findByTestId('admin-widget-keys-origins-empty-32'),
        ).toHaveTextContent('none configured');
    });

    it('opens create form on button click', async () => {
        mockedApi.get.mockResolvedValueOnce({ data: { data: [] } });
        renderWithQuery(<WidgetKeysView />);

        const btn = screen.getByTestId('admin-widget-keys-create-btn');
        fireEvent.click(btn);

        await waitFor(() => {
            expect(screen.getByTestId('admin-widget-keys-create-form')).toBeDefined();
        });
    });

    it('requires choosing a project from the tenant catalogue', async () => {
        mockedApi.get.mockResolvedValue({ data: { data: [] } });
        mockedUseKbProjects.mockReturnValue(
            projectQuery({ data: { projects: ['engineering', 'hr-portal'] } }),
        );
        mockedApi.post.mockResolvedValueOnce({
            data: {
                data: {
                    id: 99,
                    label: 'Docs',
                    public_key: 'pk_docs',
                    project_key: 'hr-portal',
                    allowed_origins: [],
                    rate_limit: 60,
                    skill: 'askmydocs-assistant@1',
                    host_tools_enabled: false,
                    user_auth_enabled: false,
                    identity_credential_version: 0,
                    is_active: true,
                    last_used_at: null,
                    sessions_count: 0,
                    created_at: '2026-08-07T00:00:00Z',
                    updated_at: '2026-08-07T00:00:00Z',
                },
                plain_secret: 'sk_docs',
                public_key: 'pk_docs',
            },
        });

        renderWithQuery(<WidgetKeysView />);
        fireEvent.click(screen.getByTestId('admin-widget-keys-create-btn'));
        fireEvent.change(await screen.findByTestId('admin-widget-keys-label'), {
            target: { value: 'Docs' },
        });

        const picker = screen.getByTestId('admin-widget-keys-project');
        expect(within(picker).getAllByRole('option').map((option) => option.textContent))
            .toEqual(['Select a project…', 'engineering', 'hr-portal']);
        expect(screen.getByTestId('admin-widget-keys-create-submit')).toBeDisabled();

        fireEvent.change(picker, { target: { value: 'hr-portal' } });
        fireEvent.click(screen.getByTestId('admin-widget-keys-create-submit'));

        await waitFor(() => {
            expect(mockedApi.post).toHaveBeenCalledWith(
                '/api/admin/widget-keys',
                expect.objectContaining({ project_key: 'hr-portal' }),
            );
        });
    });

    it('disables the project picker while the catalogue is loading', async () => {
        mockedApi.get.mockResolvedValueOnce({ data: { data: [] } });
        mockedUseKbProjects.mockReturnValue(
            projectQuery({ data: undefined, isLoading: true }),
        );

        renderWithQuery(<WidgetKeysView />);
        fireEvent.click(screen.getByTestId('admin-widget-keys-create-btn'));

        expect(await screen.findByTestId('admin-widget-keys-project-loading')).toBeVisible();
        expect(screen.getByTestId('admin-widget-keys-project')).toBeDisabled();
        expect(screen.getByTestId('admin-widget-keys-create-submit')).toBeDisabled();
    });

    it('surfaces a project catalogue error and retries it', async () => {
        const refetch = vi.fn();
        mockedApi.get.mockResolvedValueOnce({ data: { data: [] } });
        mockedUseKbProjects.mockReturnValue(
            projectQuery({
                data: undefined,
                isError: true,
                error: new Error('Catalogue unavailable'),
                refetch,
            }),
        );

        renderWithQuery(<WidgetKeysView />);
        fireEvent.click(screen.getByTestId('admin-widget-keys-create-btn'));

        expect(await screen.findByTestId('admin-widget-keys-project-error'))
            .toHaveTextContent('Catalogue unavailable');
        fireEvent.click(screen.getByTestId('admin-widget-keys-project-retry'));
        expect(refetch).toHaveBeenCalledOnce();
    });

    it('links to project management when the tenant catalogue is empty', async () => {
        mockedApi.get.mockResolvedValueOnce({ data: { data: [] } });
        mockedUseKbProjects.mockReturnValue(
            projectQuery({ data: { projects: [] } }),
        );

        renderWithQuery(<WidgetKeysView />);
        fireEvent.click(screen.getByTestId('admin-widget-keys-create-btn'));

        expect(await screen.findByTestId('admin-widget-keys-project-empty')).toBeVisible();
        expect(screen.getByRole('link', { name: 'Manage projects' }))
            .toHaveAttribute('href', './projects');
        expect(screen.getByTestId('admin-widget-keys-project')).toBeDisabled();
    });

    it('shows loading state (R14)', () => {
        mockedApi.get.mockReturnValue(new Promise(() => {})); // never resolves
        renderWithQuery(<WidgetKeysView />);
        expect(screen.getByTestId('admin-widget-keys-loading')).toBeDefined();
    });

    it('surfaces an error when revoke fails (#32, R14)', async () => {
        mockedApi.get.mockResolvedValue({
            data: {
                data: [
                    {
                        id: 1,
                        label: 'Production',
                        public_key: 'pk_abc123',
                        project_key: 'main',
                        allowed_origins: [],
                        rate_limit: 60,
                        skill: 'askmydocs-assistant@1',
                        is_active: true,
                        last_used_at: null,
                        sessions_count: 0,
                        created_at: '2026-05-30T00:00:00Z',
                        updated_at: '2026-05-30T00:00:00Z',
                    },
                ],
            },
        });
        mockedApi.post.mockRejectedValue({ response: { data: { message: 'Revoke failed boom' } } });
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);

        renderWithQuery(<WidgetKeysView />);
        await waitFor(() => screen.getByTestId('admin-widget-keys-revoke-1'));
        fireEvent.click(screen.getByTestId('admin-widget-keys-revoke-1'));

        // R14: il fallimento del revoke DEVE comparire in DOM (prima era muto).
        await waitFor(() => {
            expect(screen.getByTestId('admin-widget-keys-action-error')).toBeDefined();
        });

        confirmSpy.mockRestore();
    });

    it('shows revoke button for active keys', async () => {
        mockedApi.get.mockResolvedValueOnce({
            data: {
                data: [
                    {
                        id: 1,
                        label: 'Active Key',
                        public_key: 'pk_active',
                        project_key: 'main',
                        allowed_origins: [],
                        rate_limit: 60,
                        skill: 'askmydocs-assistant@1',
                        is_active: true,
                        last_used_at: null,
                        sessions_count: 0,
                        created_at: '2026-05-30T00:00:00Z',
                        updated_at: '2026-05-30T00:00:00Z',
                    },
                ],
            },
        });

        renderWithQuery(<WidgetKeysView />);

        await waitFor(() => {
            expect(screen.getByTestId('admin-widget-keys-revoke-1')).toBeDefined();
            expect(screen.getByTestId('admin-widget-keys-rotate-1')).toBeDefined();
        });
    });

    it('shows revoked status badge for inactive keys', async () => {
        mockedApi.get.mockResolvedValueOnce({
            data: {
                data: [
                    {
                        id: 2,
                        label: 'Revoked Key',
                        public_key: 'pk_revoked',
                        project_key: 'main',
                        allowed_origins: [],
                        rate_limit: 60,
                        skill: 'askmydocs-assistant@1',
                        is_active: false,
                        last_used_at: null,
                        sessions_count: 0,
                        created_at: '2026-05-30T00:00:00Z',
                        updated_at: '2026-05-30T00:00:00Z',
                    },
                ],
            },
        });

        renderWithQuery(<WidgetKeysView />);

        await waitFor(() => {
            expect(screen.getByTestId('admin-widget-keys-status-2')).toBeDefined();
            expect(screen.getByTestId('admin-widget-keys-status-2').textContent).toBe('Revoked');
        });
    });

    it('exposes rate-limit and skill fields in the create form', async () => {
        mockedApi.get.mockResolvedValueOnce({ data: { data: [] } });
        renderWithQuery(<WidgetKeysView />);

        fireEvent.click(screen.getByTestId('admin-widget-keys-create-btn'));

        expect(await screen.findByTestId('admin-widget-keys-rate-limit')).toBeDefined();
        expect(screen.getByTestId('admin-widget-keys-skill')).toBeDefined();
    });

    it('surfaces a create error in the DOM instead of swallowing it (R14)', async () => {
        mockedApi.get.mockResolvedValue({ data: { data: [] } });
        mockedApi.post.mockRejectedValueOnce({
            response: { data: { errors: { project_key: ['The project key is required.'] } } },
        });

        renderWithQuery(<WidgetKeysView />);
        fireEvent.click(screen.getByTestId('admin-widget-keys-create-btn'));

        fireEvent.change(await screen.findByTestId('admin-widget-keys-label'), {
            target: { value: 'Prod' },
        });
        fireEvent.change(screen.getByTestId('admin-widget-keys-project'), {
            target: { value: 'main' },
        });
        fireEvent.click(screen.getByTestId('admin-widget-keys-create-submit'));

        const err = await screen.findByTestId('admin-widget-keys-create-error');
        expect(err.textContent).toContain('The project key is required.');
    });

    it('reveals created credentials with an embed launcher after create', async () => {
        mockedApi.get.mockResolvedValue({ data: { data: [] } });
        mockedApi.post.mockResolvedValueOnce({
            data: {
                data: {
                    id: 9,
                    label: 'Prod',
                    public_key: 'pk_new_xyz',
                    project_key: 'main',
                    allowed_origins: [],
                    rate_limit: 60,
                    skill: 'askmydocs-assistant@1',
                    is_active: true,
                    last_used_at: null,
                    sessions_count: 0,
                    created_at: '2026-05-30T00:00:00Z',
                    updated_at: '2026-05-30T00:00:00Z',
                },
                plain_secret: 'sk_new_secret',
                public_key: 'pk_new_xyz',
            },
        });

        renderWithQuery(<WidgetKeysView />);
        fireEvent.click(screen.getByTestId('admin-widget-keys-create-btn'));
        fireEvent.change(await screen.findByTestId('admin-widget-keys-label'), {
            target: { value: 'Prod' },
        });
        fireEvent.change(screen.getByTestId('admin-widget-keys-project'), {
            target: { value: 'main' },
        });
        fireEvent.click(screen.getByTestId('admin-widget-keys-create-submit'));

        const creds = await screen.findByTestId('admin-widget-keys-created-creds');
        expect(creds.textContent).toContain('pk_new_xyz');
        expect(screen.getByTestId('admin-widget-keys-creds-embed')).toBeDefined();
    });

    it('opens the embed dialog from a table row and shows the snippet', async () => {
        mockedApi.get.mockResolvedValueOnce({
            data: {
                data: [
                    {
                        id: 1,
                        label: 'Production',
                        public_key: 'pk_embed_one',
                        project_key: 'main',
                        allowed_origins: ['https://example.com'],
                        rate_limit: 60,
                        skill: 'askmydocs-assistant@1',
                        is_active: true,
                        last_used_at: null,
                        sessions_count: 0,
                        created_at: '2026-05-30T00:00:00Z',
                        updated_at: '2026-05-30T00:00:00Z',
                    },
                ],
            },
        });

        renderWithQuery(<WidgetKeysView />);

        fireEvent.click(await screen.findByTestId('admin-widget-keys-embed-1'));

        expect(await screen.findByTestId('admin-widget-keys-embed-dialog')).toBeDefined();
        const snippet = await screen.findByTestId('admin-widget-embed-snippet');
        expect(snippet.textContent).toContain('pk_embed_one');
        expect(snippet.textContent).toContain('window.AskMyDocsWidget');
    });

    it('opens the authenticated-user integration tab for an enabled key', async () => {
        mockedApi.get.mockResolvedValueOnce({
            data: {
                data: [
                    {
                        id: 33,
                        label: 'Private portal',
                        public_key: 'pk_private',
                        project_key: 'main',
                        allowed_origins: ['https://portal.example'],
                        rate_limit: 60,
                        skill: 'askmydocs-assistant@1',
                        host_tools_enabled: false,
                        user_auth_enabled: true,
                        identity_credential_version: 1,
                        is_active: true,
                        last_used_at: null,
                        sessions_count: 0,
                        created_at: '2026-05-30T00:00:00Z',
                        updated_at: '2026-05-30T00:00:00Z',
                    },
                ],
            },
        });

        renderWithQuery(<WidgetKeysView />);
        fireEvent.click(await screen.findByTestId('admin-widget-keys-embed-33'));

        const authTab = await screen.findByTestId('admin-widget-embed-tab-authenticated');
        fireEvent.click(authTab);
        expect(await screen.findByTestId('admin-widget-auth-guide')).toBeDefined();
    });

    it('requires confirmation before rotating the identity credential', async () => {
        mockedApi.get.mockResolvedValue({
            data: {
                data: [
                    {
                        id: 43,
                        label: 'Protected portal',
                        public_key: 'pk_protected',
                        project_key: 'main',
                        allowed_origins: ['https://portal.example'],
                        rate_limit: 60,
                        skill: 'askmydocs-assistant@1',
                        host_tools_enabled: false,
                        user_auth_enabled: true,
                        identity_credential_version: 1,
                        is_active: true,
                        last_used_at: null,
                        sessions_count: 0,
                        created_at: '2026-05-30T00:00:00Z',
                        updated_at: '2026-05-30T00:00:00Z',
                    },
                ],
            },
        });
        mockedApi.post.mockResolvedValueOnce({
            data: { identity_plain_secret: 'ik_rotated_after_confirmation' },
        });
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

        renderWithQuery(<WidgetKeysView />);
        const rotate = await screen.findByTestId(
            'admin-widget-keys-43-rotate-identity',
        );

        fireEvent.click(rotate);
        expect(mockedApi.post).not.toHaveBeenCalled();

        confirmSpy.mockReturnValue(true);
        fireEvent.click(rotate);

        await waitFor(() => {
            expect(mockedApi.post).toHaveBeenCalledWith(
                '/api/admin/widget-keys/43/rotate-identity-secret',
                { identity_credential_version: 1 },
            );
        });
        expect(confirmSpy).toHaveBeenCalledWith(
            expect.stringContaining('current ik_ will stop minting new user tokens'),
        );
        confirmSpy.mockRestore();
    });

    it('associates a newly-issued identity credential with its widget key', async () => {
        mockedApi.get.mockResolvedValue({ data: { data: [] } });
        mockedApi.post.mockResolvedValueOnce({
            data: {
                data: {
                    id: 34,
                    label: 'Customer portal',
                    public_key: 'pk_customer',
                    project_key: 'main',
                    allowed_origins: ['https://customer.example'],
                    rate_limit: 60,
                    host_tools_enabled: false,
                    user_auth_enabled: true,
                    identity_credential_version: 1,
                    is_active: true,
                    last_used_at: null,
                    sessions_count: 0,
                    created_at: '2026-05-30T00:00:00Z',
                    updated_at: '2026-05-30T00:00:00Z',
                },
                plain_secret: 'sk_customer',
                public_key: 'pk_customer',
                identity_plain_secret: 'ik_customer',
            },
        });

        renderWithQuery(<WidgetKeysView />);
        fireEvent.click(screen.getByTestId('admin-widget-keys-create-btn'));
        fireEvent.change(await screen.findByTestId('admin-widget-keys-label'), {
            target: { value: 'Customer portal' },
        });
        fireEvent.change(screen.getByTestId('admin-widget-keys-project'), {
            target: { value: 'main' },
        });
        fireEvent.click(screen.getByTestId('admin-widget-keys-user-auth-toggle'));
        fireEvent.click(screen.getByTestId('admin-widget-keys-create-submit'));

        const alert = await screen.findByTestId('admin-widget-identity-secret');
        expect(alert).toHaveTextContent('Customer portal');
        expect(alert).toHaveTextContent('pk_customer');
        expect(alert).toHaveTextContent('ik_customer');

        fireEvent.click(screen.getByTestId('admin-widget-identity-secret-setup'));
        expect(await screen.findByTestId('admin-widget-auth-guide')).toBeVisible();
        expect(screen.getByTestId('admin-widget-embed-tab-authenticated')).toHaveAttribute(
            'data-state',
            'active',
        );
    });

    it('keeps a one-time identity credential when another key disables user auth', async () => {
        const keys = [
            {
                id: 41,
                label: 'Portal A',
                public_key: 'pk_portal_a',
                project_key: 'main',
                allowed_origins: ['https://a.example'],
                rate_limit: 60,
                skill: 'askmydocs-assistant@1',
                host_tools_enabled: false,
                user_auth_enabled: true,
                identity_credential_version: 1,
                is_active: true,
                last_used_at: null,
                sessions_count: 0,
                created_at: '2026-05-30T00:00:00Z',
                updated_at: '2026-05-30T00:00:00Z',
            },
            {
                id: 42,
                label: 'Portal B',
                public_key: 'pk_portal_b',
                project_key: 'main',
                allowed_origins: ['https://b.example'],
                rate_limit: 60,
                skill: 'askmydocs-assistant@1',
                host_tools_enabled: false,
                user_auth_enabled: true,
                identity_credential_version: 1,
                is_active: true,
                last_used_at: null,
                sessions_count: 0,
                created_at: '2026-05-30T00:00:00Z',
                updated_at: '2026-05-30T00:00:00Z',
            },
        ];
        mockedApi.get.mockResolvedValue({ data: { data: keys } });
        mockedApi.post.mockResolvedValueOnce({
            data: { identity_plain_secret: 'ik_portal_a_once' },
        });
        mockedApi.patch.mockResolvedValueOnce({
            data: { identity_plain_secret: null },
        });
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);

        renderWithQuery(<WidgetKeysView />);
        fireEvent.click(
            await screen.findByTestId('admin-widget-keys-41-rotate-identity'),
        );

        expect(await screen.findByTestId('admin-widget-identity-secret')).toHaveTextContent(
            'ik_portal_a_once',
        );

        fireEvent.click(screen.getByTestId('admin-widget-keys-42-user-auth-toggle'));
        await waitFor(() => {
            expect(mockedApi.patch).toHaveBeenCalledWith('/api/admin/widget-keys/42', {
                user_auth_enabled: false,
                identity_credential_version: 1,
            });
        });

        expect(screen.getByTestId('admin-widget-identity-secret')).toHaveTextContent(
            'ik_portal_a_once',
        );
        confirmSpy.mockRestore();
    });

    it('includes theme.mode=inline in the create payload when inline is selected', async () => {
        mockedApi.get.mockResolvedValue({ data: { data: [] } });
        mockedApi.post.mockResolvedValueOnce({
            data: {
                data: {
                    id: 10,
                    label: 'Inline',
                    public_key: 'pk_inline',
                    project_key: 'main',
                    allowed_origins: [],
                    rate_limit: 60,
                    skill: 'askmydocs-assistant@1',
                    is_active: true,
                    last_used_at: null,
                    sessions_count: 0,
                    theme: { mode: 'inline' },
                    created_at: '2026-05-30T00:00:00Z',
                    updated_at: '2026-05-30T00:00:00Z',
                },
                plain_secret: 'sk_x',
                public_key: 'pk_inline',
            },
        });

        renderWithQuery(<WidgetKeysView />);
        fireEvent.click(screen.getByTestId('admin-widget-keys-create-btn'));
        fireEvent.change(await screen.findByTestId('admin-widget-keys-label'), {
            target: { value: 'Inline' },
        });
        fireEvent.change(screen.getByTestId('admin-widget-keys-project'), {
            target: { value: 'main' },
        });
        fireEvent.change(screen.getByTestId('admin-widget-keys-mode'), {
            target: { value: 'inline' },
        });
        fireEvent.click(screen.getByTestId('admin-widget-keys-create-submit'));

        await waitFor(() => {
            expect(mockedApi.post).toHaveBeenCalledWith(
                '/api/admin/widget-keys',
                expect.objectContaining({ theme: { mode: 'inline' } }),
            );
        });
    });

    it('includes theme.mode=fullscreen in the create payload', async () => {
        mockedApi.get.mockResolvedValue({ data: { data: [] } });
        mockedApi.post.mockResolvedValueOnce({
            data: {
                data: {
                    id: 11,
                    label: 'Fullscreen',
                    public_key: 'pk_fullscreen',
                    project_key: 'main',
                    allowed_origins: [],
                    rate_limit: 60,
                    skill: 'askmydocs-assistant@1',
                    is_active: true,
                    sessions_count: 0,
                    theme: { mode: 'fullscreen' },
                    created_at: '2026-05-30T00:00:00Z',
                    updated_at: '2026-05-30T00:00:00Z',
                },
                plain_secret: 'sk_x',
                public_key: 'pk_fullscreen',
            },
        });

        renderWithQuery(<WidgetKeysView />);
        fireEvent.click(screen.getByTestId('admin-widget-keys-create-btn'));
        fireEvent.change(await screen.findByTestId('admin-widget-keys-label'), {
            target: { value: 'Fullscreen' },
        });
        fireEvent.change(screen.getByTestId('admin-widget-keys-project'), {
            target: { value: 'main' },
        });
        fireEvent.change(screen.getByTestId('admin-widget-keys-mode'), {
            target: { value: 'fullscreen' },
        });
        fireEvent.click(screen.getByTestId('admin-widget-keys-create-submit'));

        await waitFor(() => {
            expect(mockedApi.post).toHaveBeenCalledWith(
                '/api/admin/widget-keys',
                expect.objectContaining({ theme: { mode: 'fullscreen' } }),
            );
        });
    });

    it('omits the theme block from the create payload for a plain helper key', async () => {
        mockedApi.get.mockResolvedValue({ data: { data: [] } });
        mockedApi.post.mockResolvedValueOnce({
            data: {
                data: {
                    id: 11,
                    label: 'Helper',
                    public_key: 'pk_h',
                    project_key: 'main',
                    allowed_origins: [],
                    rate_limit: 60,
                    skill: 'askmydocs-assistant@1',
                    is_active: true,
                    last_used_at: null,
                    sessions_count: 0,
                    created_at: '2026-05-30T00:00:00Z',
                    updated_at: '2026-05-30T00:00:00Z',
                },
                plain_secret: 'sk_h',
                public_key: 'pk_h',
            },
        });

        renderWithQuery(<WidgetKeysView />);
        fireEvent.click(screen.getByTestId('admin-widget-keys-create-btn'));
        fireEvent.change(await screen.findByTestId('admin-widget-keys-label'), {
            target: { value: 'Helper' },
        });
        fireEvent.change(screen.getByTestId('admin-widget-keys-project'), {
            target: { value: 'main' },
        });
        fireEvent.click(screen.getByTestId('admin-widget-keys-create-submit'));

        await waitFor(() => {
            expect(mockedApi.post).toHaveBeenCalled();
        });
        expect(mockedApi.post.mock.calls[0][1]).not.toHaveProperty('theme');
    });

    it('shows the widget mode badge from the key theme', async () => {
        mockedApi.get.mockResolvedValueOnce({
            data: {
                data: [
                    {
                        id: 3,
                        label: 'Inline Key',
                        public_key: 'pk_in',
                        project_key: 'main',
                        allowed_origins: [],
                        rate_limit: 60,
                        skill: 'askmydocs-assistant@1',
                        is_active: true,
                        last_used_at: null,
                        sessions_count: 0,
                        theme: { mode: 'inline' },
                        created_at: '2026-05-30T00:00:00Z',
                        updated_at: '2026-05-30T00:00:00Z',
                    },
                ],
            },
        });

        renderWithQuery(<WidgetKeysView />);
        await waitFor(() => {
            expect(screen.getByTestId('admin-widget-keys-mode-3').textContent).toBe('Inline');
        });
    });

    it('sends host_tools_enabled=true in the create payload when the toggle is checked (R16)', async () => {
        mockedApi.get.mockResolvedValue({ data: { data: [] } });
        mockedApi.post.mockResolvedValueOnce({
            data: {
                data: {
                    id: 20,
                    label: 'HostTools',
                    public_key: 'pk_ht',
                    project_key: 'main',
                    allowed_origins: [],
                    rate_limit: 60,
                    skill: 'askmydocs-assistant@1',
                    host_tools_enabled: true,
                    is_active: true,
                    last_used_at: null,
                    sessions_count: 0,
                    created_at: '2026-05-30T00:00:00Z',
                    updated_at: '2026-05-30T00:00:00Z',
                },
                plain_secret: 'sk_ht',
                public_key: 'pk_ht',
            },
        });

        renderWithQuery(<WidgetKeysView />);
        fireEvent.click(screen.getByTestId('admin-widget-keys-create-btn'));
        fireEvent.change(await screen.findByTestId('admin-widget-keys-label'), {
            target: { value: 'HostTools' },
        });
        fireEvent.change(screen.getByTestId('admin-widget-keys-project'), {
            target: { value: 'main' },
        });

        const toggle = screen.getByTestId(
            'admin-widget-keys-host-tools-toggle',
        ) as HTMLInputElement;
        // Drive the actual state transition: starts off, click flips it on.
        expect(toggle.checked).toBe(false);
        fireEvent.click(toggle);
        expect(toggle.checked).toBe(true);

        fireEvent.click(screen.getByTestId('admin-widget-keys-create-submit'));

        await waitFor(() => {
            expect(mockedApi.post).toHaveBeenCalledWith(
                '/api/admin/widget-keys',
                expect.objectContaining({ host_tools_enabled: true }),
            );
        });
    });

    it('defaults host_tools_enabled=false in the create payload when left unchecked', async () => {
        mockedApi.get.mockResolvedValue({ data: { data: [] } });
        mockedApi.post.mockResolvedValueOnce({
            data: {
                data: {
                    id: 21,
                    label: 'NoHostTools',
                    public_key: 'pk_nht',
                    project_key: 'main',
                    allowed_origins: [],
                    rate_limit: 60,
                    skill: 'askmydocs-assistant@1',
                    host_tools_enabled: false,
                    is_active: true,
                    last_used_at: null,
                    sessions_count: 0,
                    created_at: '2026-05-30T00:00:00Z',
                    updated_at: '2026-05-30T00:00:00Z',
                },
                plain_secret: 'sk_nht',
                public_key: 'pk_nht',
            },
        });

        renderWithQuery(<WidgetKeysView />);
        fireEvent.click(screen.getByTestId('admin-widget-keys-create-btn'));
        fireEvent.change(await screen.findByTestId('admin-widget-keys-label'), {
            target: { value: 'NoHostTools' },
        });
        fireEvent.change(screen.getByTestId('admin-widget-keys-project'), {
            target: { value: 'main' },
        });
        fireEvent.click(screen.getByTestId('admin-widget-keys-create-submit'));

        await waitFor(() => {
            expect(mockedApi.post).toHaveBeenCalledWith(
                '/api/admin/widget-keys',
                expect.objectContaining({ host_tools_enabled: false }),
            );
        });
    });

    it('reflects the API host_tools value in the row toggle and PATCHes the new value on edit (R16)', async () => {
        // Initial load: key has host tools OFF; refetch after PATCH returns ON.
        mockedApi.get
            .mockResolvedValueOnce({
                data: {
                    data: [
                        {
                            id: 7,
                            label: 'Edit Key',
                            public_key: 'pk_edit',
                            project_key: 'main',
                            allowed_origins: [],
                            rate_limit: 60,
                            skill: 'askmydocs-assistant@1',
                            host_tools_enabled: false,
                            is_active: true,
                            last_used_at: null,
                            sessions_count: 0,
                            created_at: '2026-05-30T00:00:00Z',
                            updated_at: '2026-05-30T00:00:00Z',
                        },
                    ],
                },
            })
            .mockResolvedValue({
                data: {
                    data: [
                        {
                            id: 7,
                            label: 'Edit Key',
                            public_key: 'pk_edit',
                            project_key: 'main',
                            allowed_origins: [],
                            rate_limit: 60,
                            skill: 'askmydocs-assistant@1',
                            host_tools_enabled: true,
                            is_active: true,
                            last_used_at: null,
                            sessions_count: 0,
                            created_at: '2026-05-30T00:00:00Z',
                            updated_at: '2026-05-30T00:00:00Z',
                        },
                    ],
                },
            });
        mockedApi.patch.mockResolvedValueOnce({ data: { data: {} } });

        renderWithQuery(<WidgetKeysView />);

        const toggle = (await screen.findByTestId(
            'admin-widget-keys-7-host-tools-toggle',
        )) as HTMLInputElement;
        // Value populated from the API: OFF.
        expect(toggle.checked).toBe(false);

        fireEvent.click(toggle);

        await waitFor(() => {
            expect(mockedApi.patch).toHaveBeenCalledWith('/api/admin/widget-keys/7', {
                host_tools_enabled: true,
            });
        });

        // After the invalidate + refetch returns ON, the toggle reflects it.
        await waitFor(() => {
            expect(
                (
                    screen.getByTestId(
                        'admin-widget-keys-7-host-tools-toggle',
                    ) as HTMLInputElement
                ).checked,
            ).toBe(true);
        });
    });

    it('surfaces a host-tools PATCH error in the DOM instead of swallowing it (R14)', async () => {
        mockedApi.get.mockResolvedValue({
            data: {
                data: [
                    {
                        id: 8,
                        label: 'Err Key',
                        public_key: 'pk_err',
                        project_key: 'main',
                        allowed_origins: [],
                        rate_limit: 60,
                        skill: 'askmydocs-assistant@1',
                        host_tools_enabled: false,
                        is_active: true,
                        last_used_at: null,
                        sessions_count: 0,
                        created_at: '2026-05-30T00:00:00Z',
                        updated_at: '2026-05-30T00:00:00Z',
                    },
                ],
            },
        });
        mockedApi.patch.mockRejectedValueOnce({
            response: { data: { message: 'Host tools update failed.' } },
        });

        renderWithQuery(<WidgetKeysView />);

        fireEvent.click(
            await screen.findByTestId('admin-widget-keys-8-host-tools-toggle'),
        );

        const err = await screen.findByTestId('admin-widget-keys-host-tools-error');
        expect(err.textContent).toContain('Host tools update failed.');
    });

    it('surfaces a user-auth PATCH error in the DOM instead of implying success', async () => {
        mockedApi.get.mockResolvedValue({
            data: {
                data: [
                    {
                        id: 12,
                        label: 'Private portal',
                        public_key: 'pk_private_error',
                        project_key: 'main',
                        allowed_origins: ['https://portal.example'],
                        rate_limit: 60,
                        skill: 'askmydocs-assistant@1',
                        host_tools_enabled: false,
                        user_auth_enabled: false,
                        identity_credential_version: 0,
                        is_active: true,
                        last_used_at: null,
                        sessions_count: 0,
                        created_at: '2026-05-30T00:00:00Z',
                        updated_at: '2026-05-30T00:00:00Z',
                    },
                ],
            },
        });
        mockedApi.patch.mockRejectedValueOnce({
            response: { data: { message: 'User authentication update failed.' } },
        });

        renderWithQuery(<WidgetKeysView />);
        fireEvent.click(
            await screen.findByTestId('admin-widget-keys-12-user-auth-toggle'),
        );

        const err = await screen.findByTestId('admin-widget-keys-user-auth-error');
        expect(err.textContent).toContain('User authentication update failed.');
    });

    it('surfaces an identity-secret rotation error in the DOM', async () => {
        mockedApi.get.mockResolvedValue({
            data: {
                data: [
                    {
                        id: 13,
                        label: 'Private portal',
                        public_key: 'pk_rotation_error',
                        project_key: 'main',
                        allowed_origins: ['https://portal.example'],
                        rate_limit: 60,
                        skill: 'askmydocs-assistant@1',
                        host_tools_enabled: false,
                        user_auth_enabled: true,
                        identity_credential_version: 1,
                        is_active: true,
                        last_used_at: null,
                        sessions_count: 0,
                        created_at: '2026-05-30T00:00:00Z',
                        updated_at: '2026-05-30T00:00:00Z',
                    },
                ],
            },
        });
        mockedApi.post.mockRejectedValueOnce({
            response: { data: { message: 'Identity credential rotation failed.' } },
        });
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);

        renderWithQuery(<WidgetKeysView />);
        fireEvent.click(
            await screen.findByTestId('admin-widget-keys-13-rotate-identity'),
        );

        const err = await screen.findByTestId('admin-widget-keys-user-auth-error');
        expect(err.textContent).toContain('Identity credential rotation failed.');
        confirmSpy.mockRestore();
    });

    it('opens the origins editor from a table row, prefilled with the current origins', async () => {
        mockedApi.get.mockResolvedValueOnce({
            data: {
                data: [
                    {
                        id: 5,
                        label: 'Production',
                        public_key: 'pk_origins_one',
                        project_key: 'main',
                        allowed_origins: ['https://acme.com', 'https://www.acme.com'],
                        rate_limit: 60,
                        skill: 'askmydocs-assistant@1',
                        is_active: true,
                        last_used_at: null,
                        sessions_count: 0,
                        created_at: '2026-05-30T00:00:00Z',
                        updated_at: '2026-05-30T00:00:00Z',
                    },
                ],
            },
        });

        renderWithQuery(<WidgetKeysView />);

        fireEvent.click(await screen.findByTestId('admin-widget-keys-origins-5'));

        expect(await screen.findByTestId('admin-widget-origins-dialog')).toBeDefined();
        const input = (await screen.findByTestId(
            'admin-widget-origins-input',
        )) as HTMLTextAreaElement;
        expect(input.value).toBe('https://acme.com\nhttps://www.acme.com');
    });
});
