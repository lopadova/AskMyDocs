import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MembershipEditor } from './MembershipEditor';
import { api } from '../../../lib/api';
import type { AdminMembership } from '../admin.api';

/*
 * MembershipEditor drives the real users.api hooks
 * (useUserMemberships / useUpsertMembership / useDeleteMembership),
 * which call adminUsersApi.* → the axios-shaped `api` client. We stub
 * that client the way RoleDialog.test.tsx does, then assert behaviour
 * against the hooks' real plumbing (R16 — body matches the name).
 */

vi.mock('../../../lib/api', () => ({
    api: {
        get: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
    },
    ensureCsrfCookie: vi.fn(),
    resetCsrf: vi.fn(),
}));

const apiMock = api as unknown as {
    get: ReturnType<typeof vi.fn>;
    post: ReturnType<typeof vi.fn>;
    patch: ReturnType<typeof vi.fn>;
    delete: ReturnType<typeof vi.fn>;
};

const USER_ID = 7;

function makeMembership(overrides: Partial<AdminMembership> = {}): AdminMembership {
    return {
        id: 1,
        user_id: USER_ID,
        project_key: 'hr-portal',
        role: 'member',
        scope_allowlist: null,
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

/**
 * Shape the GET /api/admin/users/{id}/memberships response as the
 * paginated envelope the hook unwraps (`memberships.data.data`).
 */
function stubMembershipsList(rows: AdminMembership[]) {
    apiMock.get.mockResolvedValue({
        data: {
            data: rows,
            meta: { current_page: 1, last_page: 1, per_page: 100, total: rows.length },
        },
    });
}

function wrap(ui: React.ReactElement) {
    const qc = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

beforeEach(() => {
    apiMock.get.mockReset();
    apiMock.post.mockReset();
    apiMock.patch.mockReset();
    apiMock.delete.mockReset();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('MembershipEditor', () => {
    it('renders existing memberships fetched for the user', async () => {
        stubMembershipsList([
            makeMembership({ id: 1, project_key: 'hr-portal' }),
            makeMembership({ id: 2, project_key: 'engineering', role: 'admin' }),
        ]);

        wrap(<MembershipEditor userId={USER_ID} projectKeys={['hr-portal', 'engineering']} />);

        await waitFor(() => {
            expect(screen.getByTestId('membership-hr-portal')).toBeInTheDocument();
        });
        expect(screen.getByTestId('membership-engineering')).toBeInTheDocument();
        // The fetch targeted the user-scoped memberships endpoint.
        expect(apiMock.get).toHaveBeenCalledWith(
            `/api/admin/users/${USER_ID}/memberships`,
        );
    });

    it('renders the empty state when the user has no memberships', async () => {
        stubMembershipsList([]);

        wrap(<MembershipEditor userId={USER_ID} projectKeys={['hr-portal']} />);

        await waitFor(() => {
            expect(screen.getByTestId('memberships-empty')).toBeInTheDocument();
        });
        expect(screen.getByTestId('membership-editor')).toHaveAttribute('data-state', 'ready');
    });

    it('adds a membership with the selected project + role payload', async () => {
        stubMembershipsList([]);
        apiMock.post.mockResolvedValue({
            data: { data: makeMembership({ id: 9, project_key: 'engineering', role: 'admin' }) },
        });

        const user = userEvent.setup();
        wrap(
            <MembershipEditor userId={USER_ID} projectKeys={['hr-portal', 'engineering']} />,
        );

        await waitFor(() => {
            expect(screen.getByTestId('memberships-empty')).toBeInTheDocument();
        });

        // Open the add row, pick project + role, save.
        await user.click(screen.getByTestId('membership-add'));
        await user.selectOptions(screen.getByTestId('membership-add-project'), 'engineering');
        await user.selectOptions(screen.getByTestId('membership-add-role'), 'admin');
        await user.click(screen.getByTestId('membership-add-save'));

        await waitFor(() => {
            expect(apiMock.post).toHaveBeenCalledTimes(1);
        });
        expect(apiMock.post).toHaveBeenCalledWith(
            `/api/admin/users/${USER_ID}/memberships`,
            { project_key: 'engineering', role: 'admin', scope_allowlist: null },
        );
        // On success the add row collapses (handleAdd → setAdding(false)),
        // so the editor returns to the "Add membership" trigger state.
        // (The success toast is rendered by the route-level <ToastHost />,
        // not by this component, so it is asserted in the E2E suite.)
        await waitFor(() => {
            expect(screen.queryByTestId('membership-add-row')).toBeNull();
        });
        expect(screen.getByTestId('membership-add')).toBeInTheDocument();
    });

    it('removes a membership by id when the row delete is clicked', async () => {
        stubMembershipsList([makeMembership({ id: 3, project_key: 'hr-portal' })]);
        apiMock.delete.mockResolvedValue({ data: null });

        const user = userEvent.setup();
        wrap(<MembershipEditor userId={USER_ID} projectKeys={['hr-portal']} />);

        await waitFor(() => {
            expect(screen.getByTestId('membership-hr-portal')).toBeInTheDocument();
        });

        await user.click(screen.getByTestId('membership-hr-portal-delete'));

        await waitFor(() => {
            expect(apiMock.delete).toHaveBeenCalledTimes(1);
        });
        // deleteMembership targets the membership id (3), not the user id.
        expect(apiMock.delete).toHaveBeenCalledWith('/api/admin/memberships/3');
    });

    it('derives the project picker options from the injected projectKeys, not a hard-coded set', async () => {
        // R18: options must mirror the provided list exactly. Inject keys
        // that no hard-coded subset would contain.
        stubMembershipsList([]);

        const injected = ['alpha-project', 'beta-project', 'gamma-project'];
        const user = userEvent.setup();
        wrap(<MembershipEditor userId={USER_ID} projectKeys={injected} />);

        await waitFor(() => {
            expect(screen.getByTestId('memberships-empty')).toBeInTheDocument();
        });

        await user.click(screen.getByTestId('membership-add'));

        const select = screen.getByTestId('membership-add-project') as HTMLSelectElement;
        const optionValues = within(select)
            .getAllByRole('option')
            .map((o) => (o as HTMLOptionElement).value);
        expect(optionValues).toEqual(injected);
    });

    it('excludes already-assigned projects from the add picker', async () => {
        // availableKeys = projectKeys − existingKeys. With hr-portal already
        // a member, only engineering remains pickable.
        stubMembershipsList([makeMembership({ id: 1, project_key: 'hr-portal' })]);

        const user = userEvent.setup();
        wrap(
            <MembershipEditor userId={USER_ID} projectKeys={['hr-portal', 'engineering']} />,
        );

        await waitFor(() => {
            expect(screen.getByTestId('membership-hr-portal')).toBeInTheDocument();
        });

        await user.click(screen.getByTestId('membership-add'));

        const select = screen.getByTestId('membership-add-project') as HTMLSelectElement;
        const optionValues = within(select)
            .getAllByRole('option')
            .map((o) => (o as HTMLOptionElement).value);
        expect(optionValues).toEqual(['engineering']);
    });
});
