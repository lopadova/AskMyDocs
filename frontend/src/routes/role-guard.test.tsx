import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { RequireRole, AdminForbidden } from './role-guard';
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
