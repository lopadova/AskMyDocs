import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { SourceAclView } from './SourceAclView';
import { api } from '../../../lib/api';

const mockGet = vi.fn();
const mockPatch = vi.fn();

beforeEach(() => {
    mockGet.mockReset();
    mockPatch.mockReset();
    vi.spyOn(api, 'get').mockImplementation(mockGet);
    vi.spyOn(api, 'patch').mockImplementation(mockPatch);
});
afterEach(() => vi.restoreAllMocks());

function withQueryClient(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

const SUMMARY = { pending: 1, ignored: 0, documents_affected: 1, documents_restricted: 4 };

const ROW = {
    id: 7,
    document_id: 42,
    document_title: 'Compensation review',
    source_path: 'drive/board/comp.md',
    project_key: 'default',
    principal_type: 'user',
    principal: 'contractor@agency.example',
    effect: 'allow',
    status: 'pending' as const,
    first_seen_at: '2026-08-01T00:00:00Z',
    last_seen_at: '2026-08-28T00:00:00Z',
};

function page(rows: unknown[], summary = SUMMARY, status: 'pending' | 'ignored' = 'pending') {
    return { data: { summary, status, data: rows } };
}

describe('SourceAclView', () => {
    it('shows the loading state before the queue arrives', () => {
        mockGet.mockImplementation(() => new Promise(() => {}));

        render(withQueryClient(<SourceAclView />));

        expect(screen.getByTestId('admin-source-acl-view')).toHaveAttribute('data-state', 'loading');
    });

    it('reports how many documents their source governs, even with an empty queue', async () => {
        // The two numbers are independent: a source can restrict documents
        // while naming nobody this application fails to recognise. Showing
        // the empty queue as "nothing happening" would hide the restriction.
        mockGet.mockResolvedValue(page([], { ...SUMMARY, pending: 0 }));

        render(withQueryClient(<SourceAclView />));

        await screen.findByTestId('admin-source-acl-empty');

        expect(screen.getByTestId('admin-source-acl-view')).toHaveAttribute('data-state', 'empty');
        expect(screen.getByTestId('admin-source-acl-restricted')).toHaveTextContent('4');
    });

    it('surfaces a failure instead of rendering an empty queue', async () => {
        // R14 — an error must not be indistinguishable from "nothing to do",
        // which on this screen would read as "no permission problems".
        mockGet.mockRejectedValue(new Error('boom'));

        render(withQueryClient(<SourceAclView />));

        const error = await screen.findByTestId('admin-source-acl-error');

        expect(error).toHaveAttribute('role', 'alert');
        expect(screen.getByTestId('admin-source-acl-view')).toHaveAttribute('data-state', 'error');
        expect(screen.queryByTestId('admin-source-acl-empty')).toBeNull();
    });

    it('lists an unrecognised principal with what the source called it', async () => {
        mockGet.mockResolvedValue(page([ROW]));

        render(withQueryClient(<SourceAclView />));

        const row = await screen.findByTestId('admin-source-acl-row-7');

        expect(row).toHaveTextContent('contractor@agency.example');
        expect(row).toHaveTextContent('Person');
        expect(row).toHaveTextContent('Compensation review');
    });

    it('records a dismissal and refetches', async () => {
        mockGet.mockResolvedValue(page([ROW]));
        mockPatch.mockResolvedValue({ data: { data: { id: 7, status: 'ignored' } } });

        render(withQueryClient(<SourceAclView />));

        await userEvent.click(await screen.findByTestId('admin-source-acl-row-7-dismiss'));

        await waitFor(() => {
            expect(mockPatch).toHaveBeenCalledWith('/api/admin/kb/source-acl/7', { status: 'ignored' });
        });
        // The refetch is what keeps the list honest after a decision.
        await waitFor(() => expect(mockGet).toHaveBeenCalledTimes(2));
    });

    it('surfaces a failed decision rather than silently doing nothing', async () => {
        mockGet.mockResolvedValue(page([ROW]));
        mockPatch.mockRejectedValue(new Error('nope'));

        render(withQueryClient(<SourceAclView />));

        await userEvent.click(await screen.findByTestId('admin-source-acl-row-7-dismiss'));

        const error = await screen.findByTestId('admin-source-acl-action-error');
        expect(error).toHaveAttribute('role', 'alert');
    });

    it('asks the server for dismissed entries when the filter changes', async () => {
        mockGet.mockResolvedValue(page([]));

        render(withQueryClient(<SourceAclView />));
        await screen.findByTestId('admin-source-acl-empty');

        await userEvent.selectOptions(
            screen.getByTestId('admin-source-acl-status-filter'),
            'ignored',
        );

        await waitFor(() => {
            expect(mockGet).toHaveBeenLastCalledWith('/api/admin/kb/source-acl?status=ignored');
        });
    });

    it('gives the status filter an accessible name', () => {
        // R15 — the visible text is "Showing", which does not say what is
        // being filtered.
        mockGet.mockImplementation(() => new Promise(() => {}));

        render(withQueryClient(<SourceAclView />));

        expect(screen.getByLabelText('Filter by decision status')).toBeInTheDocument();
    });
});
