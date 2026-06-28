import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { InviteTab } from './InviteTab';
import { api } from '../../../lib/api';

const mockPost = vi.fn();

beforeEach(() => {
    mockPost.mockReset();
    vi.spyOn(api, 'post').mockImplementation(mockPost);
});

afterEach(() => {
    vi.restoreAllMocks();
});

function withQueryClient(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

describe('InviteTab', () => {
    it('starts on the empty (session) state and opens the send drawer', async () => {
        render(withQueryClient(<InviteTab />));
        expect(screen.getByTestId('admin-invitations-invites-empty')).toBeVisible();
        await userEvent.click(screen.getByTestId('admin-invitations-invite-open'));
        expect(screen.getByTestId('admin-invitations-invite-drawer')).toHaveAttribute('aria-modal', 'true');
    });

    it('rejects an invalid recipient inline without POSTing (R16)', async () => {
        render(withQueryClient(<InviteTab />));
        await userEvent.click(screen.getByTestId('admin-invitations-invite-open'));
        fireEvent.change(screen.getByTestId('admin-invitations-invite-recipient'), { target: { value: 'nope' } });
        await userEvent.click(screen.getByTestId('admin-invitations-invite-submit'));
        expect(screen.getByTestId('invite-recipient-error')).toBeVisible();
        expect(mockPost).not.toHaveBeenCalled();
    });

    it('sends a valid invitation and records it in the session list', async () => {
        mockPost.mockResolvedValue({
            data: { data: { id: 1, recipient: 'user@example.com', status: 'pending', channel: 'email', expires_at: null } },
        });
        render(withQueryClient(<InviteTab />));
        await userEvent.click(screen.getByTestId('admin-invitations-invite-open'));
        fireEvent.change(screen.getByTestId('admin-invitations-invite-recipient'), { target: { value: 'user@example.com' } });
        await userEvent.click(screen.getByTestId('admin-invitations-invite-submit'));

        await waitFor(() => {
            expect(mockPost).toHaveBeenCalledWith('/api/admin/invitations/invitations', {
                recipient: 'user@example.com',
                channel: 'email',
                role: null,
                context_ref: null,
            });
        });
        const row = await screen.findByTestId('admin-invitations-invites-row-1');
        expect(row).toHaveTextContent('user@example.com');
    });

    it('idempotent re-send of the same invitation keeps a single row (R25 dedupe)', async () => {
        mockPost.mockResolvedValue({
            data: { data: { id: 1, recipient: 'user@example.com', status: 'pending', channel: 'email', expires_at: null } },
        });
        render(withQueryClient(<InviteTab />));

        for (let i = 0; i < 2; i++) {
            await userEvent.click(screen.getByTestId('admin-invitations-invite-open'));
            fireEvent.change(screen.getByTestId('admin-invitations-invite-recipient'), { target: { value: 'user@example.com' } });
            await userEvent.click(screen.getByTestId('admin-invitations-invite-submit'));
            await waitFor(() => expect(screen.getByTestId('admin-invitations-invites-row-1')).toBeVisible());
        }
        expect(screen.getAllByTestId('admin-invitations-invites-row-1')).toHaveLength(1);
    });
});
