import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { api } from '../../../lib/api';
import { TenantControlView } from './TenantControlView';

const mockGet = vi.fn();
const mockPost = vi.fn();
const mockPatch = vi.fn();

const tenant = {
    slug: 'acme',
    name: 'Acme',
    hash: 'hash-acme',
    status: 'active',
    project_count: 2,
    member_count: 1,
    created_at: null,
    updated_at: null,
};

const pageMeta = { current_page: 1, last_page: 1, per_page: 25, total: 1 };

const detail = {
    tenant,
    users: {
        data: [
            {
                id: 7,
                name: 'Ada Admin',
                email: 'ada@acme.test',
                is_active: true,
                deleted_at: null,
                roles: ['admin'],
                permissions: ['kb.read.any', 'users.manage'],
                all_projects: true,
                memberships: [{ id: 1, project_key: 'acme-kb', role: 'admin', scope: [] }],
            },
        ],
        meta: pageMeta,
    },
};

function renderWithQuery(node: ReactNode): void {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(<QueryClientProvider client={client}>{node}</QueryClientProvider>);
}

beforeEach(() => {
    mockGet.mockReset();
    mockPost.mockReset();
    mockPatch.mockReset();
    vi.spyOn(api, 'get').mockImplementation(mockGet);
    vi.spyOn(api, 'post').mockImplementation(mockPost);
    vi.spyOn(api, 'patch').mockImplementation(mockPatch);

    mockGet.mockImplementation((url: string) => {
        if (url === '/api/super-admin/tenants') {
            return Promise.resolve({ data: { data: [tenant], meta: pageMeta } });
        }
        if (url === '/api/super-admin/tenants/acme') {
            return Promise.resolve({ data: { data: detail } });
        }
        return Promise.reject(new Error(`Unexpected GET ${url}`));
    });
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('TenantControlView', () => {
    it('renders the global tenant table and expands users with their effective access', async () => {
        renderWithQuery(<TenantControlView />);

        expect(await screen.findByTestId('tenant-control-row-acme')).toBeVisible();
        expect(await screen.findByTestId('tenant-control-user-7')).toHaveTextContent('Ada Admin');
        expect(screen.getByTestId('tenant-control-user-7')).toHaveTextContent('Access: all tenant projects');
        expect(screen.getByTestId('tenant-control-detail')).toHaveTextContent('2 projects');
    });

    it('recognises an existing account and provisions by association without a password', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url === '/api/super-admin/tenants') {
                return Promise.resolve({ data: { data: [tenant], meta: pageMeta } });
            }
            if (url === '/api/super-admin/tenants/acme') {
                return Promise.resolve({ data: { data: detail } });
            }
            if (url === '/api/super-admin/tenants/availability') {
                return Promise.resolve({
                    data: {
                        data: {
                            tenant: { slug: 'globex', available: true },
                            user: {
                                status: 'existing',
                                email: 'person@example.com',
                                id: 12,
                                name: 'Existing Person',
                                roles: ['viewer'],
                            },
                            can_provision: true,
                        },
                    },
                });
            }
            return Promise.reject(new Error(`Unexpected GET ${url}`));
        });
        mockPost.mockResolvedValue({
            data: {
                data: {
                    tenant: { ...tenant, slug: 'globex', name: 'Globex' },
                    project: { project_key: 'globex', name: 'Globex', membership_role: 'admin' },
                    user: { id: 12, name: 'Existing Person', email: 'person@example.com', is_active: true, roles: ['viewer', 'admin'] },
                    attached_existing: true,
                    registry_created: true,
                },
            },
        });

        renderWithQuery(<TenantControlView />);
        await screen.findByTestId('tenant-control-row-acme');
        await userEvent.click(screen.getByTestId('tenant-control-open-provision'));
        await userEvent.type(screen.getByTestId('tenant-control-provision-tenant-name'), 'Globex');
        await userEvent.type(screen.getByTestId('tenant-control-provision-user-email'), 'person@example.com');

        expect(await screen.findByTestId('tenant-control-availability-existing-user')).toHaveTextContent('Existing Person');
        expect(screen.queryByTestId('tenant-control-provision-password')).not.toBeInTheDocument();

        await userEvent.click(screen.getByTestId('tenant-control-provision-submit'));

        await waitFor(() => {
            expect(mockPost).toHaveBeenCalledWith(
                '/api/super-admin/tenants',
                expect.objectContaining({
                    tenant_slug: 'globex',
                    user_email: 'person@example.com',
                    attach_existing: true,
                    password: undefined,
                }),
            );
        });
        expect(await screen.findByTestId('tenant-control-provision-success')).toHaveTextContent('password was not changed');
    });

    it('blocks submission when the tenant slug is already registered', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url === '/api/super-admin/tenants') {
                return Promise.resolve({ data: { data: [tenant], meta: pageMeta } });
            }
            if (url === '/api/super-admin/tenants/acme') {
                return Promise.resolve({ data: { data: detail } });
            }
            if (url === '/api/super-admin/tenants/availability') {
                return Promise.resolve({
                    data: {
                        data: {
                            tenant: { slug: 'acme', available: false },
                            user: { status: 'new', email: 'new@acme.test', id: null, name: null, roles: [] },
                            can_provision: false,
                        },
                    },
                });
            }
            return Promise.reject(new Error(`Unexpected GET ${url}`));
        });

        renderWithQuery(<TenantControlView />);
        await screen.findByTestId('tenant-control-row-acme');
        await userEvent.click(screen.getByTestId('tenant-control-open-provision'));
        await userEvent.type(screen.getByTestId('tenant-control-provision-tenant-name'), 'Acme');
        await userEvent.type(screen.getByTestId('tenant-control-provision-user-email'), 'new@acme.test');

        expect(await screen.findByTestId('tenant-control-availability-tenant-taken')).toBeVisible();
        expect(screen.getByTestId('tenant-control-provision-submit')).toBeDisabled();
        expect(mockPost).not.toHaveBeenCalled();
    });
});
