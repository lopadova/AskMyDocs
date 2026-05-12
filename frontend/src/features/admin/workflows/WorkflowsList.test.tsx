import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { WorkflowsList } from './WorkflowsList';
import { api } from '../../../lib/api';

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
});

afterEach(() => {
    vi.restoreAllMocks();
});

function wrapped(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

describe('WorkflowsList', () => {
    it('renders empty state initially when no workflows', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        render(wrapped(<WorkflowsList />));
        await screen.findByTestId('admin-workflows-empty');
        expect(screen.getByTestId('admin-workflows')).toHaveAttribute('data-state', 'empty');
    });

    it('renders one card per workflow', async () => {
        mockGet.mockResolvedValue({
            data: {
                data: [
                    { id: 1, user_id: 7, title: 'Risk register', type: 'tabular', practice: 'legal' },
                    { id: 2, user_id: 7, title: 'Email draft', type: 'assistant', practice: null },
                ],
            },
        });
        render(wrapped(<WorkflowsList />));
        await waitFor(() => expect(screen.getByTestId('admin-workflow-card-1')).toBeVisible());
        expect(screen.getByTestId('admin-workflow-card-2')).toBeVisible();
        expect(screen.getByTestId('admin-workflow-card-1-type')).toHaveTextContent('tabular');
    });

    it('switches scope tabs and refetches', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url.includes('scope=mine')) {
                return Promise.resolve({ data: { data: [{ id: 1, user_id: 7, title: 'Mine', type: 'assistant' }] } });
            }
            if (url.includes('scope=system')) {
                return Promise.resolve({ data: { data: [{ id: 99, user_id: null, title: 'Sys', type: 'assistant', is_system: true }] } });
            }
            return Promise.resolve({ data: { data: [] } });
        });
        render(wrapped(<WorkflowsList />));
        await screen.findByTestId('admin-workflow-card-1');
        await userEvent.click(screen.getByTestId('admin-workflows-scope-system'));
        await waitFor(() => expect(screen.getByTestId('admin-workflow-card-99')).toBeVisible());
        // The Mine card is no longer rendered (scope changed).
        expect(screen.queryByTestId('admin-workflow-card-1')).not.toBeInTheDocument();
    });

    it('opens the create dialog and submits an assistant workflow', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        mockPost.mockResolvedValueOnce({
            data: { data: { id: 11, user_id: 7, title: 'New WF', type: 'assistant' } },
        });

        render(wrapped(<WorkflowsList />));
        await screen.findByTestId('admin-workflows-empty');
        await userEvent.click(screen.getByTestId('admin-workflows-create'));
        await screen.findByTestId('admin-workflow-create-dialog');

        await userEvent.type(screen.getByTestId('admin-workflow-create-title'), 'New WF');
        await userEvent.type(screen.getByTestId('admin-workflow-create-prompt'), 'Be helpful.');
        await userEvent.click(screen.getByTestId('admin-workflow-create-submit'));

        await waitFor(() => expect(mockPost).toHaveBeenCalled());
        const [url, payload] = mockPost.mock.calls[0]!;
        expect(url).toBe('/api/admin/workflows');
        expect(payload.title).toBe('New WF');
        expect(payload.type).toBe('assistant');
        expect(payload.prompt_md).toBe('Be helpful.');
    });

    it('surfaces a create error without closing the dialog', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        mockPost.mockRejectedValueOnce(new Error('422 invalid'));

        render(wrapped(<WorkflowsList />));
        await screen.findByTestId('admin-workflows-empty');
        await userEvent.click(screen.getByTestId('admin-workflows-create'));
        await screen.findByTestId('admin-workflow-create-dialog');

        await userEvent.type(screen.getByTestId('admin-workflow-create-title'), 'X');
        await userEvent.type(screen.getByTestId('admin-workflow-create-prompt'), 'p');
        await userEvent.click(screen.getByTestId('admin-workflow-create-submit'));

        const err = await screen.findByTestId('admin-workflow-create-error');
        expect(err).toBeVisible();
        expect(err.textContent).toContain('422 invalid');
        expect(screen.getByTestId('admin-workflow-create-dialog')).toBeVisible();
    });

    it('opens the suggestions gallery and saves a proposal', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });

        // suggestions endpoint
        mockPost.mockImplementation((url: string, body?: any) => {
            if (url === '/api/admin/workflows/suggest') {
                return Promise.resolve({
                    data: {
                        data: [
                            { title: 'Suggested A', type: 'assistant', rationale: 'because' },
                            { title: 'Suggested B', type: 'tabular', rationale: 'because2' },
                        ],
                    },
                });
            }
            if (url === '/api/admin/workflows/from-proposal') {
                return Promise.resolve({ data: { data: { id: 50, title: body.title, type: body.type, user_id: 7 } } });
            }
            return Promise.reject(new Error(`unexpected POST ${url}`));
        });

        render(wrapped(<WorkflowsList />));
        await screen.findByTestId('admin-workflows-empty');
        await userEvent.click(screen.getByTestId('admin-workflows-suggest'));
        await screen.findByTestId('admin-workflow-suggestions-gallery');
        await screen.findByTestId('admin-workflow-suggestion-0');

        await userEvent.click(screen.getByTestId('admin-workflow-suggestion-0-save'));

        await waitFor(() => {
            const calls = mockPost.mock.calls.filter((c) => c[0] === '/api/admin/workflows/from-proposal');
            expect(calls.length).toBe(1);
            expect(calls[0]![1].title).toBe('Suggested A');
        });
    });

    it('shows the error state when the workflows fetch fails', async () => {
        mockGet.mockRejectedValue(new Error('500'));
        render(wrapped(<WorkflowsList />));
        const err = await screen.findByTestId('admin-workflows-error');
        expect(err).toBeVisible();
    });
});
