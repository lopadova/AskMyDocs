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
            // List endpoint now appends `?per_page=100` per Copilot
            // iter 6 (pagination is BE-paginated but FE wrapper bumps
            // the page size for v4.7 GA). Match by prefix on the list
            // path, and by id for the show fetch.
            if (url.startsWith('/api/admin/tabular-reviews?') || url === '/api/admin/tabular-reviews') {
                return Promise.resolve({
                    data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } },
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

        // Exact 17-element domain. The assertion is intentionally
        // order-insensitive (compare as a SET) — the UI surfaces these
        // options in `FORMAT_TYPES` declaration order today, but the
        // test's behavioural claim is "every BE-enum value is offered,
        // no synonyms slip in". Asserting order would couple the test
        // to a presentation detail and break on legitimate UX
        // reordering (R16 — let the test name drive the assertion).
        // Copilot iter 7 flagged the doc-vs-assertion drift.
        const expectedDomain = [
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
        ];
        expect(optionValues.length).toBe(expectedDomain.length);
        expect([...optionValues].sort()).toEqual([...expectedDomain].sort());
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

    it('graph agent reveals the governance metric picker (v8.19/W5)', async () => {
        mockGet.mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } });
        render(wrapped(<TabularReviewsList />));
        await screen.findByTestId('admin-tabular-reviews-empty');
        await userEvent.click(screen.getByTestId('admin-tabular-reviews-create'));
        await screen.findByTestId('admin-tabular-review-create-dialog');

        // No metric picker until the column's agent is `graph`.
        expect(screen.queryByTestId('admin-tabular-review-create-column-0-metric')).toBeNull();
        await userEvent.selectOptions(screen.getByTestId('admin-tabular-review-create-column-0-agent'), 'graph');
        const metric = (await screen.findByTestId('admin-tabular-review-create-column-0-metric')) as HTMLSelectElement;
        const metricValues = Array.from(metric.querySelectorAll('option')).map((o) => o.value);
        expect(metricValues).toContain('evidence_tier');
        expect(metricValues).toContain('supersession_status');

        // R16 — lock in the "no avoidable 422" gating: with title + project
        // filled but the graph column carrying NO metric, submit stays disabled;
        // picking a governance metric flips it enabled.
        await userEvent.type(screen.getByTestId('admin-tabular-review-create-title'), 'Governance');
        await userEvent.type(screen.getByTestId('admin-tabular-review-create-project'), 'eng');
        const submit = screen.getByTestId('admin-tabular-review-create-submit');
        expect(submit).toBeDisabled();
        await userEvent.selectOptions(metric, 'evidence_tier');
        expect(submit).toBeEnabled();
    });

    it('opens the evidence side-panel with reasoning + citations when a cell is clicked (v8.19/W5)', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url.startsWith('/api/admin/tabular-reviews?') || url === '/api/admin/tabular-reviews') {
                return Promise.resolve({ data: { data: REVIEWS, meta: { current_page: 1, last_page: 1, per_page: 100, total: 2 } } });
            }
            if (url.startsWith('/api/admin/tabular-reviews/')) {
                return Promise.resolve({
                    data: {
                        data: REVIEWS[0],
                        cells: [{
                            id: 5, review_id: 1, document_id: 42, column_index: 0,
                            content: { summary: 'On track', reasoning: 'Status field says on track.', citations: [{ chunk_id: 'c-9', quote: 'project is on track' }] },
                            status: 'ready', flag: 'green',
                        }],
                        cells_meta: { total: 1, returned: 1, offset: 0, limit: 2000, truncated: false },
                    },
                });
            }
            return Promise.reject(new Error(`unexpected GET ${url}`));
        });

        render(wrapped(<TabularReviewsList />));
        await waitFor(() => expect(screen.getByTestId('admin-tabular-review-row-1')).toBeVisible());
        await userEvent.click(screen.getByTestId('admin-tabular-review-row-1-open'));
        await screen.findByTestId('admin-tabular-review-show');
        await userEvent.click(await screen.findByTestId('admin-tabular-review-show-cell-42-0-open'));

        const panel = await screen.findByTestId('admin-tabular-review-evidence-panel');
        expect(panel).toBeVisible();
        expect(screen.getByTestId('admin-tabular-review-evidence-reasoning').textContent).toContain('on track');
        expect(screen.getByTestId('admin-tabular-review-evidence-citation-0').textContent).toContain('c-9');
    });

    it('lists built-in tabular templates and prefills the create dialog (v8.19/W5)', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url.startsWith('/api/admin/workflows')) {
                return Promise.resolve({ data: { data: [
                    { id: 16, title: 'Canonical KB Governance Audit', type: 'tabular', is_system: true, practice: 'engineering', columns_config: [{ name: 'Canonical?', format: 'yes_no', agent: 'graph', metric: 'is_canonical' }] },
                    { id: 6, title: 'Meeting Notes Summary', type: 'assistant', is_system: true, columns_config: [] },
                ] } });
            }
            if (url.startsWith('/api/admin/tabular-reviews')) {
                return Promise.resolve({ data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } } });
            }
            return Promise.reject(new Error(`unexpected GET ${url}`));
        });

        render(wrapped(<TabularReviewsList />));
        await screen.findByTestId('admin-tabular-reviews-empty');
        await userEvent.click(screen.getByTestId('admin-tabular-reviews-from-template'));
        await screen.findByTestId('admin-tabular-review-template-gallery');

        // Only the tabular system template renders (the assistant one is filtered out).
        const useBtn = await screen.findByTestId('admin-tabular-review-template-16-use');
        expect(screen.queryByTestId('admin-tabular-review-template-6-use')).toBeNull();
        await userEvent.click(useBtn);

        // The create dialog opens pre-filled with the template's title + graph column.
        await screen.findByTestId('admin-tabular-review-create-dialog');
        expect((screen.getByTestId('admin-tabular-review-create-title') as HTMLInputElement).value).toBe('Canonical KB Governance Audit');
        expect((screen.getByTestId('admin-tabular-review-create-column-0-agent') as HTMLSelectElement).value).toBe('graph');
        expect(screen.getByTestId('admin-tabular-review-create-column-0-metric')).toBeVisible();
    });
});
