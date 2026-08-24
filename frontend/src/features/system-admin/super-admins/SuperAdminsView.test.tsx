import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { api } from '../../../lib/api';
import { SuperAdminsView } from './SuperAdminsView';

const pageMeta = { current_page: 1, last_page: 1, per_page: 25, total: 1 };

beforeEach(() => {
    vi.spyOn(api, 'get').mockImplementation((url: string) => {
        if (url === '/api/system-admin/super-admins') {
            return Promise.resolve({
                data: {
                    data: [{
                        id: 7,
                        name: 'Ada Admin',
                        email: 'ada@example.test',
                        is_active: true,
                        deleted_at: null,
                        is_system_admin: true,
                        tenant_count: 1,
                    }],
                    meta: pageMeta,
                },
            });
        }
        if (url === '/api/system-admin/super-admins/7/tenants') {
            return Promise.resolve({
                data: {
                    user: {
                        id: 7,
                        name: 'Ada Admin',
                        email: 'ada@example.test',
                        is_active: true,
                        deleted_at: null,
                        is_system_admin: true,
                    },
                    data: [{
                        slug: 'acme',
                        hash: 'abc123abc123',
                        name: 'Acme',
                        status: 'active',
                        project_count: 2,
                    }],
                    meta: pageMeta,
                },
            });
        }
        return Promise.reject(new Error(`Unexpected GET ${url}`));
    });
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('SuperAdminsView', () => {
    it('renders the read-only global roster and paginated tenant associations', async () => {
        const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        render(
            <QueryClientProvider client={client}>
                <SuperAdminsView />
            </QueryClientProvider>,
        );

        expect(await screen.findByTestId('system-super-admin-row-7')).toHaveTextContent('Ada Admin');
        expect(screen.getByTestId('system-super-admin-row-7')).toHaveTextContent('Yes');
        expect(await screen.findByTestId('system-super-admin-tenant-acme')).toHaveTextContent('2 projects');
        expect(screen.queryByRole('button', { name: /assign|revoke|suspend|impersonate/i })).not.toBeInTheDocument();
    });
});
