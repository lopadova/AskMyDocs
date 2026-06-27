import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { CodesTab } from './CodesTab';
import { api } from '../../../lib/api';

const mockGet = vi.fn();
const mockPost = vi.fn();

const CODES = [
    { id: 1, campaign_id: null, code: 'ABC123', code_kind: 'random', state: 'active', max_uses: 1, current_uses: 0, expires_at: null },
    { id: 2, campaign_id: null, code: 'XYZ789', code_kind: 'random', state: 'revoked', max_uses: 1, current_uses: 1, expires_at: null },
];

beforeEach(() => {
    mockGet.mockReset();
    mockPost.mockReset();
    mockGet.mockImplementation((url: string) => {
        if (url.startsWith('/api/admin/invitations/codes')) return Promise.resolve({ data: { data: CODES } });
        return Promise.resolve({ data: { data: [] } }); // campaigns
    });
    vi.spyOn(api, 'get').mockImplementation(mockGet);
    vi.spyOn(api, 'post').mockImplementation(mockPost);
});

afterEach(() => {
    vi.restoreAllMocks();
});

function withQueryClient(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

describe('CodesTab', () => {
    it('renders the inventory; revoke only offered on active codes', async () => {
        render(withQueryClient(<CodesTab />));
        await waitFor(() => expect(screen.getByTestId('admin-invitations-codes-row-1')).toBeVisible());
        // Active code → revoke available.
        expect(screen.getByTestId('admin-invitations-codes-row-1-revoke')).toBeVisible();
        // Already-revoked code → no revoke action.
        expect(screen.queryByTestId('admin-invitations-codes-row-2-revoke')).not.toBeInTheDocument();
    });

    it('revoke requires a confirm step and only then POSTs (R16, R21 — no accidental destruction)', async () => {
        mockPost.mockResolvedValue({ data: { data: { ...CODES[0], state: 'revoked' } } });
        render(withQueryClient(<CodesTab />));
        await waitFor(() => expect(screen.getByTestId('admin-invitations-codes-row-1')).toBeVisible());

        await userEvent.click(screen.getByTestId('admin-invitations-codes-row-1-revoke'));
        // Confirm + Cancel appear; nothing posted yet.
        expect(screen.getByTestId('admin-invitations-codes-row-1-revoke-confirm')).toBeVisible();
        expect(mockPost).not.toHaveBeenCalled();

        await userEvent.click(screen.getByTestId('admin-invitations-codes-row-1-revoke-confirm'));
        await waitFor(() => {
            expect(mockPost).toHaveBeenCalledWith('/api/admin/invitations/codes/1/revoke', {});
        });
    });

    it('generator rejects an out-of-range count inline without POSTing (R16 failure path actually fires)', async () => {
        render(withQueryClient(<CodesTab />));
        await waitFor(() => expect(screen.getByTestId('admin-invitations-codes-row-1')).toBeVisible());

        await userEvent.click(screen.getByTestId('admin-invitations-codes-generate-open'));
        const count = screen.getByTestId('admin-invitations-codes-generate-count');
        // Deterministic set for a controlled number input (clear+type is flaky
        // on <input type=number>); assert it actually took the value (R16).
        fireEvent.change(count, { target: { value: '0' } });
        expect(count).toHaveValue(0);
        await userEvent.click(screen.getByTestId('admin-invitations-codes-generate-submit'));

        expect(screen.getByTestId('gen-count-error')).toBeVisible();
        expect(mockPost).not.toHaveBeenCalled();
    });

    it('generating a valid batch POSTs the payload and shows the result with export actions', async () => {
        mockPost.mockResolvedValue({
            data: {
                data: [
                    { id: 101, campaign_id: null, code: 'NEW001', code_kind: 'random', state: 'active', max_uses: 1, current_uses: 0, expires_at: null },
                ],
            },
        });
        render(withQueryClient(<CodesTab />));
        await waitFor(() => expect(screen.getByTestId('admin-invitations-codes-row-1')).toBeVisible());

        await userEvent.click(screen.getByTestId('admin-invitations-codes-generate-open'));
        const count = screen.getByTestId('admin-invitations-codes-generate-count');
        fireEvent.change(count, { target: { value: '3' } });
        await userEvent.click(screen.getByTestId('admin-invitations-codes-generate-submit'));

        await waitFor(() => {
            expect(mockPost).toHaveBeenCalledWith('/api/admin/invitations/codes', {
                campaign_id: null,
                count: 3,
                max_uses: null,
                length: null,
                expires_at: null,
            });
        });
        expect(await screen.findByTestId('admin-invitations-codes-generate-result')).toBeVisible();
        expect(screen.getByTestId('admin-invitations-codes-generate-code-101')).toHaveTextContent('NEW001');
        expect(screen.getByTestId('admin-invitations-codes-generate-csv')).toBeVisible();
        expect(screen.getByTestId('admin-invitations-codes-generate-copy-all')).toBeVisible();
    });
});
