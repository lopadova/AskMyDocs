import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { RequirePermission, RequireRole, AdminForbidden } from './role-guard';
import { useAuthStore } from '../lib/auth-store';

describe('RequireRole', () => {
    beforeEach(() => {
        useAuthStore.setState({
            user: null,
            roles: [],
            permissions: [],
            projects: [],
            loading: false,
        });
    });

    it('renders children when a user has one of the allowed roles', () => {
        useAuthStore.setState({
            user: { id: 1, name: 'Admin', email: 'a@b.c' },
            roles: ['admin'],
            permissions: [],
            projects: [],
            loading: false,
        });

        render(
            <RequireRole roles={['admin', 'super-admin']}>
                <div data-testid="secret">ok</div>
            </RequireRole>,
        );

        expect(screen.getByTestId('secret')).toBeInTheDocument();
    });

    it('renders AdminForbidden when the user has none of the allowed roles', () => {
        useAuthStore.setState({
            user: { id: 2, name: 'Viewer', email: 'v@b.c' },
            roles: ['viewer'],
            permissions: [],
            projects: [],
            loading: false,
        });

        render(
            <RequireRole roles={['admin', 'super-admin']}>
                <div data-testid="secret">hidden</div>
            </RequireRole>,
        );

        expect(screen.queryByTestId('secret')).not.toBeInTheDocument();
        expect(screen.getByTestId('admin-forbidden')).toBeInTheDocument();
    });

    it('renders a loading shimmer while the auth store bootstraps', () => {
        useAuthStore.setState({
            user: null,
            roles: [],
            permissions: [],
            projects: [],
            loading: true,
        });

        render(
            <RequireRole roles={['admin']}>
                <div data-testid="secret">ok</div>
            </RequireRole>,
        );

        expect(screen.getByTestId('admin-loading')).toBeInTheDocument();
        expect(screen.queryByTestId('secret')).not.toBeInTheDocument();
    });

    it('honours a custom fallback when provided', () => {
        useAuthStore.setState({
            user: { id: 3, name: 'Nope', email: 'n@b.c' },
            roles: ['editor'],
            permissions: [],
            projects: [],
            loading: false,
        });

        render(
            <RequireRole
                roles={['admin']}
                fallback={<div data-testid="custom-fallback">nope</div>}
            >
                <div data-testid="secret">hidden</div>
            </RequireRole>,
        );

        expect(screen.getByTestId('custom-fallback')).toBeInTheDocument();
    });

    it('AdminForbidden carries the stable testid', () => {
        render(<AdminForbidden />);
        expect(screen.getByTestId('admin-forbidden')).toBeInTheDocument();
    });
});

describe('RequirePermission', () => {
    it('uses the platform permission rather than a super-admin role', () => {
        useAuthStore.setState({
            user: { id: 4, name: 'System', email: 'system@example.test' },
            roles: ['super-admin'],
            permissions: ['platform.admin'],
            projects: [],
            loading: false,
        });

        render(
            <RequirePermission permission="platform.admin">
                <div data-testid="system-control">ok</div>
            </RequirePermission>,
        );

        expect(screen.getByTestId('system-control')).toBeInTheDocument();
    });

    it('denies a tenant super-admin without platform.admin', () => {
        useAuthStore.setState({
            user: { id: 5, name: 'Tenant super', email: 'tenant@example.test' },
            roles: ['super-admin'],
            permissions: [],
            projects: [],
            loading: false,
        });

        render(
            <RequirePermission permission="platform.admin">
                <div data-testid="system-control">hidden</div>
            </RequirePermission>,
        );

        expect(screen.queryByTestId('system-control')).not.toBeInTheDocument();
        expect(screen.getByText('System administration access required')).toBeInTheDocument();
    });
});
