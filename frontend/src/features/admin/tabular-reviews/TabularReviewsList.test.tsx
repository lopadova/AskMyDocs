import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { TabularReviewsList } from './TabularReviewsList';
import { api } from '../../../lib/api';
import { useAuthStore } from '../../../lib/auth-store';

/**
 * v4.7/W3 — Vitest coverage for the Tabular Reviews list/show/create
 * flow. The HTTP boundary is mocked via the shared `api` axios
 * instance; we exercise the full state machine
 * (loading → empty → ready → create-dialog → show page).
 */

const mockGet = vi.fn();
const mockPost = vi.fn();
const mockDelete = vi.fn();

beforeEach(() => {
    mockGet.mockReset();
    mockPost.mockReset();
    mockDelete.mockReset();
    vi.spyOn(api, 'get').mockImplementation(mockGet);
    vi.spyOn(api, 'post').mockImplementation(mockPost);
    vi.spyOn(api, 'delete').mockImplementation(mockDelete);

    // Seed auth-store with admin so role-gated mutation buttons render.
    useAuthStore.getState().setMe({
        user: { id: 7, name: 'Tester', email: 't@demo.local' } as any,
        roles: ['admin'],
        permissions: [],
        projects: [],
    });
});

afterEach(() => {
    vi.restoreAllMocks();
    useAuthStore.getState().clear();
});

function wrapped(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

const REVIEWS = [
    {
        id: 1,
        project_key: 'hr',
        title: 'NDA review',
        columns_config: [{ name: 'Status', format: 'text' as const }],
        updated_at: '2026-05-12T10:00:00Z',
    },
    {
        id: 2,
        project_key: 'eng',
        title: 'Risk register',
        columns_config: [
            { name: 'Owner', format: 'person' as const },
            { name: 'Severity', format: 'text' as const },
        ],
        updated_at: '2026-05-12T11:00:00Z',
    },
];

describe('TabularReviewsList', () => {
    it('renders the loading state initially', () => {
        mockGet.mockImplementation(() => new Promise(() => {}));
        render(wrapped(<TabularReviewsList />));
        expect(screen.getByTestId('admin-tabular-reviews')).toHaveAttribute('data-state', 'loading');
    });

    it('renders empty state when no reviews exist', async () => {
        mockGet.mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } });
        render(wrapped(<TabularReviewsList />));
        const empty = await screen.findByTestId('admin-tabular-reviews-empty');
        expect(empty).toBeVisible();
        expect(screen.getByTestId('admin-tabular-reviews')).toHaveAttribute('data-state', 'empty');
    });

    it('lists one row per review', async () => {
        mockGet.mockResolvedValue({ data: { data: REVIEWS, meta: { current_page: 1, last_page: 1, per_page: 25, total: 2 } } });
        render(wrapped(<TabularReviewsList />));
        await waitFor(() => expect(screen.getByTestId('admin-tabular-review-row-1')).toBeVisible());
        expect(screen.getByTestId('admin-tabular-review-row-2')).toBeVisible();
        expect(screen.getByTestId('admin-tabular-review-row-1-open')).toHaveTextContent('NDA review');
    });

    it('renders error state when the list fetch fails', async () => {
        mockGet.mockRejectedValue(new Error('500 server'));
        render(wrapped(<TabularReviewsList />));
        const err = await screen.findByTestId('admin-tabular-reviews-error');
        expect(err).toBeVisible();
        expect(err.textContent).toContain('500 server');
    });

    it('opens the create dialog and submits a payload', async () => {
        // GET fan-out: first call = list (empty), subsequent calls =
        // the show-page detail fetch that fires after create succeeds.
        mockGet.mockImplementation((url: string) => {
            if (url === '/api/admin/tabular-reviews') {
                return Promise.resolve({
                    data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } },
                });
            }
            if (url.startsWith('/api/admin/tabular-reviews/')) {
                return Promise.resolve({
                    data: {
                        data: { id: 99, project_key: 'eng', title: 'My new review', columns_config: [] },
                        cells: [],
                        cells_meta: { total: 0, returned: 0, offset: 0, limit: 2000, truncated: false },
                    },
                });
            }
            return Promise.reject(new Error(`unexpected GET ${url}`));
        });
        mockPost.mockResolvedValueOnce({
            data: {
                data: {
                    id: 99,
                    project_key: 'eng',
                    title: 'My new review',
                    columns_config: [{ name: 'Severity', format: 'text', prompt: 'What severity?' }],
                },
            },
        });

        render(wrapped(<TabularReviewsList />));
        await screen.findByTestId('admin-tabular-reviews-empty');
        await userEvent.click(screen.getByTestId('admin-tabular-reviews-create'));

        await screen.findByTestId('admin-tabular-review-create-dialog');
        await userEvent.type(screen.getByTestId('admin-tabular-review-create-title'), 'My new review');
        await userEvent.type(screen.getByTestId('admin-tabular-review-create-project'), 'eng');
        await userEvent.type(screen.getByTestId('admin-tabular-review-create-column-0-name'), 'Severity');
        await userEvent.type(screen.getByTestId('admin-tabular-review-create-column-0-prompt'), 'What severity?');
        await userEvent.click(screen.getByTestId('admin-tabular-review-create-submit'));

        await waitFor(() => expect(mockPost).toHaveBeenCalled());
        const [url, payload] = mockPost.mock.calls[0]!;
        expect(url).toBe('/api/admin/tabular-reviews');
        expect(payload.title).toBe('My new review');
        expect(payload.project_key).toBe('eng');
        expect(payload.columns_config[0].name).toBe('Severity');
    });

    it('renders the full FORMAT_TYPES domain (17 options) in the create dialog column dropdown', async () => {
        // R18 — Copilot iter 4 caught a Mike-style subset (`free_text`,
        // `percent`, `duration`, `boolean`, `choice`, `flag`, `entity`,
        // `list`) that doesn't exist on the BE enum. The dropdown must
        // mirror `App\Support\TabularReview\FormatType` exactly: 17
        // cases, no synonyms.
        mockGet.mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } });

        render(wrapped(<TabularReviewsList />));
        await screen.findByTestId('admin-tabular-reviews-empty');
        await userEvent.click(screen.getByTestId('admin-tabular-reviews-create'));
        await screen.findByTestId('admin-tabular-review-create-dialog');

        const select = screen.getByTestId('admin-tabular-review-create-column-0-format') as HTMLSelectElement;
        const optionValues = Array.from(select.querySelectorAll('option')).map((o) => o.value);

        // Exact 17-element domain (alphabetical order optional; what
        // matters is the SET).
        expect(optionValues).toEqual([
            'text',
            'bulleted_list',
            'number',
            'percentage',
            'monetary_amount',
            'currency',
            'yes_no',
            'date',
            'tag',
            'enum',
            'enum_status',
            'rating',
            'url',
            'person',
            'tags_multi',
            'relation',
            'json_path',
        ]);
        // None of the obsolete Mike-style literals are present.
        expect(optionValues).not.toContain('free_text');
        expect(optionValues).not.toContain('percent');
        expect(optionValues).not.toContain('duration');
        expect(optionValues).not.toContain('boolean');
        expect(optionValues).not.toContain('choice');
        expect(optionValues).not.toContain('flag');
        expect(optionValues).not.toContain('entity');
        expect(optionValues).not.toContain('list');
    });

    it('surfaces a create error in the dialog without closing it', async () => {
        mockGet.mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } });
        mockPost.mockRejectedValueOnce(new Error('422 validation failed'));

        render(wrapped(<TabularReviewsList />));
        await screen.findByTestId('admin-tabular-reviews-empty');
        await userEvent.click(screen.getByTestId('admin-tabular-reviews-create'));
        await screen.findByTestId('admin-tabular-review-create-dialog');

        await userEvent.type(screen.getByTestId('admin-tabular-review-create-title'), 'X');
        await userEvent.type(screen.getByTestId('admin-tabular-review-create-project'), 'hr');
        await userEvent.click(screen.getByTestId('admin-tabular-review-create-submit'));

        const err = await screen.findByTestId('admin-tabular-review-create-error');
        expect(err).toBeVisible();
        expect(err.textContent).toContain('422 validation failed');
        // Dialog is still open so the user can retry.
        expect(screen.getByTestId('admin-tabular-review-create-dialog')).toBeVisible();
    });
});
