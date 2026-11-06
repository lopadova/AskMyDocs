import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { ExpansionLearningView } from './ExpansionLearningView';
import { ExpansionLearningDisabledError, adminExpansionLearningApi, type ExpansionLearningStatus } from './expansion-learning.api';
import { EXPANSION_LEARNING_MESSAGES_IT } from './expansion-learning.messages.it';
import { api } from '../../../lib/api';
import { setLocalUiLocale, translateUi } from '../../../lib/ui-locale';
import { chooseSelectOption } from '@/test-utils/select';

const URL = '/api/admin/kb/expansion-learning';
const mockGet = vi.fn();
const mockPost = vi.fn();

function status(overrides: Partial<ExpansionLearningStatus> = {}): ExpansionLearningStatus {
    return {
        enabled: true,
        auto_apply: false,
        training_consent: true,
        training_base_models: ['gpt-4.1-mini-2025-04-14', 'gpt-4.1-nano-2025-04-14', 'gpt-4o-mini-2024-07-18'],
        events: { total: 120, last_30_days: 45, with_feedback: 7 },
        applied: {
            id: 3,
            project_key: 'hr',
            status: 'applied',
            weights: { lex: 1.2, vec: 0.9, hyde: 1 },
            few_shot_count: 2,
            few_shot: [],
            evidence: { events_analyzed: 40 },
            gate: { status: 'passed' },
            proposed_by: 'user:1',
            decided_by: 'user:1',
            decided_at: '2026-11-04T08:30:00+00:00',
            created_at: '2026-11-04T08:00:00+00:00',
        },
        proposals: [
            {
                id: 5,
                project_key: 'hr',
                status: 'proposed',
                weights: { lex: 1.4, vec: 0.6, hyde: 1.1 },
                few_shot_count: 1,
                few_shot: [{ query: 'ferie <b>bold</b>', lex: ['vacation days'], vec: [], hyde: null }],
                evidence: { events_analyzed: 50, refusal_rate: 0.1 },
                gate: { status: 'not_measurable' },
                proposed_by: 'user:1',
                decided_by: null,
                decided_at: null,
                created_at: '2026-11-05T10:00:00+00:00',
            },
            {
                id: 3,
                project_key: 'hr',
                status: 'applied',
                weights: { lex: 1.2, vec: 0.9, hyde: 1 },
                few_shot_count: 2,
                few_shot: [],
                evidence: null,
                gate: { status: 'passed' },
                proposed_by: 'user:1',
                decided_by: 'user:1',
                decided_at: '2026-11-04T08:30:00+00:00',
                created_at: '2026-11-04T08:00:00+00:00',
            },
        ],
        models: [
            {
                id: 9,
                project_key: null,
                backend: 'openai',
                base_model: 'gpt-4.1-mini-2025-04-14',
                dataset_rows: 80,
                status: 'ready',
                failure_reason: null,
                model_id: 'ft:gpt-4.1-mini:acme',
                created_by: 'user:1',
                promoted_by: null,
                promoted_at: null,
                created_at: '2026-11-03T08:00:00+00:00',
            },
            {
                id: 8,
                project_key: null,
                backend: 'openai',
                base_model: 'gpt-4.1-mini-2025-04-14',
                dataset_rows: 60,
                status: 'training',
                failure_reason: null,
                model_id: null,
                created_by: 'user:1',
                promoted_by: null,
                promoted_at: null,
                created_at: '2026-11-02T08:00:00+00:00',
            },
        ],
        ...overrides,
    };
}

function respondWith(body: ExpansionLearningStatus | Error) {
    mockGet.mockImplementation((url: string) => {
        if (url === '/api/admin/kb/projects') {
            return Promise.resolve({ data: { projects: ['engineering', 'hr'] } });
        }
        if (body instanceof Error) return Promise.reject(body);
        return Promise.resolve({ data: body });
    });
}

function httpError(status: number, data: Record<string, unknown>) {
    return Object.assign(new Error(`Request failed with status code ${status}`), { response: { status, data } });
}

const disabledError = () =>
    httpError(404, { code: 'expansion_learning_disabled', message: 'The expansion learning loop is disabled on this deployment.' });

beforeEach(() => {
    mockGet.mockReset();
    mockPost.mockReset();
    vi.spyOn(api, 'get').mockImplementation(mockGet);
    vi.spyOn(api, 'post').mockImplementation(mockPost);
});

afterEach(() => {
    vi.restoreAllMocks();
    setLocalUiLocale('en');
});

function withQueryClient(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

describe('adminExpansionLearningApi', () => {
    it('sends project_key only when filtering and turns the flag-OFF 404 into a typed error', async () => {
        mockGet.mockResolvedValue({ data: status() });
        await adminExpansionLearningApi.status();
        expect(mockGet).toHaveBeenLastCalledWith(URL, undefined);
        await adminExpansionLearningApi.status('hr');
        expect(mockGet).toHaveBeenLastCalledWith(URL, { params: { project_key: 'hr' } });

        mockGet.mockRejectedValue(disabledError());
        mockPost.mockRejectedValue(disabledError());
        await expect(adminExpansionLearningApi.status()).rejects.toBeInstanceOf(ExpansionLearningDisabledError);
        await expect(adminExpansionLearningApi.applyTuning(1)).rejects.toBeInstanceOf(ExpansionLearningDisabledError);

        const other = httpError(404, { code: 'not_found', message: 'No such tuning or model run.' });
        mockPost.mockRejectedValue(other);
        await expect(adminExpansionLearningApi.applyTuning(1)).rejects.toBe(other);
    });

    it('only sends base_model for the openai backend', async () => {
        mockPost.mockResolvedValue({ data: {} });
        await adminExpansionLearningApi.train({ backend: 'export', base_model: 'gpt-4o-mini-2024-07-18', confirm: true });
        expect(mockPost).toHaveBeenLastCalledWith(`${URL}/training`, { backend: 'export', confirm: true });
        await adminExpansionLearningApi.train({ backend: 'openai', base_model: 'gpt-4o-mini-2024-07-18', project_key: 'hr', confirm: true });
        expect(mockPost).toHaveBeenLastCalledWith(`${URL}/training`, {
            backend: 'openai',
            confirm: true,
            project_key: 'hr',
            base_model: 'gpt-4o-mini-2024-07-18',
        });
    });
});

describe('ExpansionLearningView', () => {
    it('renders the loading state initially', () => {
        mockGet.mockImplementation(() => new Promise(() => {}));
        render(withQueryClient(<ExpansionLearningView />));
        const page = screen.getByTestId('admin-expansion-learning-page');
        expect(page).toHaveAttribute('data-state', 'loading');
        expect(page).toHaveAttribute('aria-busy', 'true');
    });

    it('renders KPIs, applied tuning, proposals and models when ready', async () => {
        respondWith(status());
        render(withQueryClient(<ExpansionLearningView />));
        await screen.findByTestId('admin-expansion-learning-proposal-5');
        const page = screen.getByTestId('admin-expansion-learning-page');
        expect(page).toHaveAttribute('data-state', 'ready');
        expect(page).toHaveAttribute('aria-busy', 'false');

        expect(screen.getByTestId('admin-expansion-learning-kpi-total-value')).toHaveTextContent('120');
        expect(screen.getByTestId('admin-expansion-learning-kpi-last-30-days-value')).toHaveTextContent('45');
        expect(screen.getByTestId('admin-expansion-learning-kpi-with-feedback-value')).toHaveTextContent('7');
        expect(screen.getByTestId('admin-expansion-learning-auto-apply')).toHaveAttribute('data-on', 'false');
        expect(screen.getByTestId('admin-expansion-learning-consent')).toHaveAttribute('data-on', 'true');

        expect(screen.getByTestId('admin-expansion-learning-applied-weights')).toHaveTextContent('lex 1.20 · vec 0.90 · hyde 1.00');
        expect(screen.getByTestId('admin-expansion-learning-applied-gate')).toHaveAttribute('data-gate', 'passed');
        expect(screen.getByTestId('admin-expansion-learning-proposal-5-gate')).toHaveAttribute('data-gate', 'not_measurable');

        // Only `proposed` rows are actionable.
        expect(screen.getByTestId('admin-expansion-learning-proposal-5-apply')).toBeVisible();
        expect(screen.queryByTestId('admin-expansion-learning-proposal-3-apply')).not.toBeInTheDocument();

        // Model actions follow the status.
        expect(screen.getByTestId('admin-expansion-learning-model-9-promote')).toBeVisible();
        expect(screen.queryByTestId('admin-expansion-learning-model-9-refresh')).not.toBeInTheDocument();
        expect(screen.getByTestId('admin-expansion-learning-model-8-refresh')).toBeVisible();
        expect(screen.queryByTestId('admin-expansion-learning-model-8-promote')).not.toBeInTheDocument();
    });

    it('expands proposal details and renders few-shot text as text, never HTML', async () => {
        respondWith(status());
        render(withQueryClient(<ExpansionLearningView />));
        const toggle = await screen.findByTestId('admin-expansion-learning-proposal-5-details');
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await userEvent.click(toggle);
        expect(toggle).toHaveAttribute('aria-expanded', 'true');
        const panel = screen.getByTestId('admin-expansion-learning-proposal-5-details-panel');
        expect(panel).toHaveTextContent('ferie <b>bold</b>');
        expect(panel.querySelector('b')).toBeNull();
        expect(within(panel).getByTestId('admin-expansion-learning-proposal-5-evidence')).toHaveTextContent('"events_analyzed": 50');
    });

    it('renders the empty state when nothing has been recorded', async () => {
        respondWith(status({ events: { total: 0, last_30_days: 0, with_feedback: 0 }, applied: null, proposals: [], models: [] }));
        render(withQueryClient(<ExpansionLearningView />));
        const empty = await screen.findByTestId('admin-expansion-learning-empty');
        expect(empty).toHaveAttribute('data-state', 'empty');
        expect(screen.getByTestId('admin-expansion-learning-page')).toHaveAttribute('data-state', 'empty');
        expect(screen.queryByTestId('admin-expansion-learning-proposals')).not.toBeInTheDocument();
    });

    it('renders an error state (NOT empty) when the status read fails', async () => {
        respondWith(httpError(500, { message: 'Server exploded.' }));
        render(withQueryClient(<ExpansionLearningView />));
        const err = await screen.findByTestId('admin-expansion-learning-error');
        expect(err).toHaveAttribute('data-state', 'error');
        expect(err).toHaveTextContent('Server exploded.');
        expect(screen.getByTestId('admin-expansion-learning-page')).toHaveAttribute('data-state', 'error');
        expect(screen.queryByTestId('admin-expansion-learning-empty')).not.toBeInTheDocument();
    });

    it('degrades to a single unavailable panel on 404 expansion_learning_disabled (R43)', async () => {
        respondWith(disabledError());
        render(withQueryClient(<ExpansionLearningView />));
        const panel = await screen.findByTestId('admin-expansion-learning-unavailable');
        expect(panel).toHaveAttribute('data-state', 'unavailable');
        expect(panel).toHaveTextContent('Expansion learning is disabled on this deployment.');
        expect(screen.getByTestId('admin-expansion-learning-page')).toHaveAttribute('data-state', 'unavailable');
        expect(screen.queryByTestId('admin-expansion-learning-error')).not.toBeInTheDocument();
        expect(screen.queryByTestId('admin-expansion-learning-analyze')).not.toBeInTheDocument();
        expect(screen.queryByTestId('admin-expansion-learning-train-form')).not.toBeInTheDocument();
    });

    it('Analyze is disabled without a project and queues the analysis for the chosen one', async () => {
        respondWith(status());
        mockPost.mockResolvedValue({ status: 202, data: { queued: true, project_key: 'hr' } });
        render(withQueryClient(<ExpansionLearningView />));
        await screen.findByTestId('admin-expansion-learning-proposal-5');
        const analyze = screen.getByTestId('admin-expansion-learning-analyze');
        expect(analyze).toBeDisabled();

        const filter = screen.getByTestId('admin-expansion-learning-project-filter');
        expect(screen.getByRole('combobox', { name: 'Project' })).toBe(filter);
        await chooseSelectOption(filter, 'hr');
        await waitFor(() => expect(mockGet).toHaveBeenCalledWith(URL, { params: { project_key: 'hr' } }));
        await waitFor(() => expect(screen.getByTestId('admin-expansion-learning-analyze')).toBeEnabled());
        await userEvent.click(screen.getByTestId('admin-expansion-learning-analyze'));

        await waitFor(() => expect(mockPost).toHaveBeenCalledWith(`${URL}/analyze`, { project_key: 'hr' }));
        expect(await screen.findByTestId('admin-expansion-learning-analyze-queued')).toHaveTextContent('Analysis queued.');
    });

    it('apply requires the confirm click before firing the API', async () => {
        respondWith(status());
        mockPost.mockResolvedValue({ data: { id: 5, status: 'applied' } });
        render(withQueryClient(<ExpansionLearningView />));
        await userEvent.click(await screen.findByTestId('admin-expansion-learning-proposal-5-apply'));
        expect(mockPost).not.toHaveBeenCalled();
        expect(screen.getByTestId('admin-expansion-learning-proposal-5-apply-confirm')).toHaveAccessibleName('Confirm applying tuning #5');

        await userEvent.click(screen.getByTestId('admin-expansion-learning-proposal-5-apply-confirm'));
        await waitFor(() => expect(mockPost).toHaveBeenCalledWith(`${URL}/tunings/5/apply`, {}));
        expect(await screen.findByTestId('admin-expansion-learning-applied-toast')).toHaveTextContent('Tuning applied.');
    });

    it('cancelling a confirm does not call the API', async () => {
        respondWith(status());
        render(withQueryClient(<ExpansionLearningView />));
        await userEvent.click(await screen.findByTestId('admin-expansion-learning-proposal-5-reject'));
        await userEvent.click(screen.getByTestId('admin-expansion-learning-proposal-5-reject-cancel'));
        expect(screen.getByTestId('admin-expansion-learning-proposal-5-reject')).toBeVisible();
        expect(mockPost).not.toHaveBeenCalled();
    });

    it('roll back of the applied tuning rejects it after confirmation', async () => {
        respondWith(status());
        mockPost.mockResolvedValue({ data: { id: 3, status: 'rejected' } });
        render(withQueryClient(<ExpansionLearningView />));
        await userEvent.click(await screen.findByTestId('admin-expansion-learning-applied-rollback'));
        expect(mockPost).not.toHaveBeenCalled();
        await userEvent.click(screen.getByTestId('admin-expansion-learning-applied-rollback-confirm'));
        await waitFor(() => expect(mockPost).toHaveBeenCalledWith(`${URL}/tunings/3/reject`, {}));
    });

    it('shows the server message of a 422 refusal (R14)', async () => {
        respondWith(status());
        mockPost.mockRejectedValue(httpError(422, { code: 'refused', message: 'Only a proposed tuning can be applied (this one is applied).' }));
        render(withQueryClient(<ExpansionLearningView />));
        await userEvent.click(await screen.findByTestId('admin-expansion-learning-proposal-5-apply'));
        await userEvent.click(screen.getByTestId('admin-expansion-learning-proposal-5-apply-confirm'));
        const error = await screen.findByTestId('admin-expansion-learning-action-error');
        expect(error).toHaveAttribute('role', 'alert');
        expect(error).toHaveTextContent('Only a proposed tuning can be applied (this one is applied).');
    });

    it('promote sends confirm:true only after the confirm click', async () => {
        respondWith(status());
        mockPost.mockResolvedValue({ data: { id: 9, status: 'promoted' } });
        render(withQueryClient(<ExpansionLearningView />));
        await userEvent.click(await screen.findByTestId('admin-expansion-learning-model-9-promote'));
        expect(mockPost).not.toHaveBeenCalled();
        await userEvent.click(screen.getByTestId('admin-expansion-learning-model-9-promote-confirm'));
        await waitFor(() => expect(mockPost).toHaveBeenCalledWith(`${URL}/models/9/promote`, { confirm: true }));
    });

    it('the training form requires the confirm checkbox before calling the API', async () => {
        respondWith(status());
        mockPost.mockResolvedValue({ data: { id: 10, status: 'ready' } });
        render(withQueryClient(<ExpansionLearningView />));
        await screen.findByTestId('admin-expansion-learning-train-form');

        await userEvent.click(screen.getByTestId('admin-expansion-learning-train-submit'));
        const confirmError = screen.getByTestId('admin-expansion-learning-train-confirm-error');
        expect(confirmError).toHaveTextContent('Confirm that you want to start a training run.');
        expect(screen.getByTestId('admin-expansion-learning-train-confirm')).toHaveAttribute('aria-invalid', 'true');
        expect(mockPost).not.toHaveBeenCalled();

        // Base model is only offered for OpenAI.
        expect(screen.queryByTestId('admin-expansion-learning-train-base-model')).not.toBeInTheDocument();
        await chooseSelectOption(screen.getByTestId('admin-expansion-learning-train-backend'), 'openai');
        await chooseSelectOption(screen.getByTestId('admin-expansion-learning-train-base-model'), 'gpt-4.1-nano-2025-04-14');

        await userEvent.click(screen.getByTestId('admin-expansion-learning-train-confirm'));
        expect(screen.queryByTestId('admin-expansion-learning-train-confirm-error')).not.toBeInTheDocument();
        await userEvent.click(screen.getByTestId('admin-expansion-learning-train-submit'));
        await waitFor(() =>
            expect(mockPost).toHaveBeenCalledWith(`${URL}/training`, {
                backend: 'openai',
                confirm: true,
                base_model: 'gpt-4.1-nano-2025-04-14',
            }),
        );
    });

    it('shows a 403 super_admin_required message next to the training form', async () => {
        respondWith(status());
        mockPost.mockRejectedValue(httpError(403, { code: 'super_admin_required', message: 'Only a super-admin can start a training run.' }));
        render(withQueryClient(<ExpansionLearningView />));
        await screen.findByTestId('admin-expansion-learning-train-form');
        await userEvent.click(screen.getByTestId('admin-expansion-learning-train-confirm'));
        await userEvent.click(screen.getByTestId('admin-expansion-learning-train-submit'));
        const error = await screen.findByTestId('admin-expansion-learning-train-error');
        expect(error).toHaveAttribute('role', 'alert');
        expect(error).toHaveTextContent('Only a super-admin can start a training run.');
    });
});

describe('ExpansionLearningView — Italian interface', () => {
    it('renders the page in Italian when the interface locale is Italian', async () => {
        setLocalUiLocale('it');
        respondWith(status());
        render(withQueryClient(<ExpansionLearningView />));
        await screen.findByTestId('admin-expansion-learning-proposal-5');
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Apprendimento delle espansioni');
        expect(screen.getByTestId('admin-expansion-learning-analyze')).toHaveTextContent('Analizza ora');
        expect(screen.getByTestId('admin-expansion-learning-proposal-5-apply')).toHaveAccessibleName('Applica la taratura #5');
        expect(screen.getByTestId('admin-expansion-learning-proposal-5-gate')).toHaveTextContent('Non misurabile');
        // Server data is never translated.
        expect(screen.getByTestId('admin-expansion-learning-model-9')).toHaveTextContent('ft:gpt-4.1-mini:acme');
    });

    it('translates the nav entry and its description', () => {
        expect(translateUi('Expansion learning', 'it')).toBe(EXPANSION_LEARNING_MESSAGES_IT['Expansion learning']);
        expect(translateUi('Learn from searches to improve query rewriting.', 'it')).not.toBe('Learn from searches to improve query rewriting.');
    });
});
