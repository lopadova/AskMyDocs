import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AxiosError } from 'axios';
import { CompanyOnboardingView } from './CompanyOnboardingView';
import { useAuthStore } from '../../lib/auth-store';

vi.mock('../auth/auth.api', () => ({
    completeCompanyOnboarding: vi.fn(async () => ({
        data: {
            tenant: { slug: 'acme', name: 'Acme', hash: 'abc123abc123' },
            project: { project_key: 'acme-kb', name: 'Acme', membership_role: 'owner' },
            onboarding_required: false,
        },
    })),
    me: vi.fn(async () => ({
        user: { id: 7, name: 'Mara', email: 'mara@example.com' },
        roles: ['super-admin'],
        permissions: [],
        projects: [{ project_key: 'acme-kb', role: 'owner', scope: [] }],
        teams: [{
            tenant_id: 'acme',
            hash: 'abc123abc123',
            name: 'Acme',
            projects: [{ project_key: 'acme-kb', role: 'owner', scope: [] }],
        }],
        onboarding: { required: false, can_create_company: false },
    })),
}));

beforeEach(() => {
    useAuthStore.getState().clear();
    vi.clearAllMocks();
});

describe('CompanyOnboardingView', () => {
    it('renders accessible company, slug and project fields', () => {
        render(<CompanyOnboardingView />);

        expect(screen.getByLabelText(/nome azienda/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/identificativo azienda/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/progetto iniziale/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /crea azienda e continua/i })).toBeInTheDocument();
    });

    it('blocks an empty company name before calling the API', async () => {
        const user = userEvent.setup();
        const { completeCompanyOnboarding } = await import('../auth/auth.api');
        render(<CompanyOnboardingView />);

        await user.click(screen.getByTestId('company-onboarding-submit'));

        expect(await screen.findByTestId('company_name-error')).toHaveTextContent(
            /enter your company name/i,
        );
        expect(completeCompanyOnboarding).not.toHaveBeenCalled();
    });

    it('creates the company, refreshes me and completes navigation', async () => {
        const user = userEvent.setup();
        const onSuccess = vi.fn();
        render(<CompanyOnboardingView onSuccess={onSuccess} />);

        await user.type(screen.getByTestId('company-onboarding-name'), 'Acme');
        await user.type(screen.getByTestId('company-onboarding-slug'), 'acme');
        await user.type(screen.getByTestId('company-onboarding-project'), 'acme-kb');
        await user.click(screen.getByTestId('company-onboarding-submit'));

        const { completeCompanyOnboarding, me } = await import('../auth/auth.api');
        expect(completeCompanyOnboarding).toHaveBeenCalledWith({
            company_name: 'Acme',
            tenant_slug: 'acme',
            project_key: 'acme-kb',
        });
        await vi.waitFor(() => {
            expect(me).toHaveBeenCalled();
            expect(onSuccess).toHaveBeenCalled();
        });
        expect(useAuthStore.getState().onboarding.required).toBe(false);
        expect(useAuthStore.getState().roles).toContain('super-admin');
    });

    it('omits optional slug and project from the request', async () => {
        const user = userEvent.setup();
        render(<CompanyOnboardingView />);

        await user.type(screen.getByTestId('company-onboarding-name'), 'Acme');
        await user.click(screen.getByTestId('company-onboarding-submit'));

        const { completeCompanyOnboarding } = await import('../auth/auth.api');
        await vi.waitFor(() => {
            expect(completeCompanyOnboarding).toHaveBeenCalledWith({
                company_name: 'Acme',
            });
        });
    });

    it('surfaces a server validation error beside the tenant slug', async () => {
        const user = userEvent.setup();
        const { completeCompanyOnboarding } = await import('../auth/auth.api');
        vi.mocked(completeCompanyOnboarding).mockRejectedValueOnce(
            new AxiosError('Unprocessable', 'ERR_BAD_REQUEST', undefined, undefined, {
                status: 422,
                data: { errors: { slug: ['This slug is reserved.'] } },
            } as never),
        );
        render(<CompanyOnboardingView />);

        await user.type(screen.getByTestId('company-onboarding-name'), 'Legacy');
        await user.type(screen.getByTestId('company-onboarding-slug'), 'default');
        await user.click(screen.getByTestId('company-onboarding-submit'));

        expect(await screen.findByTestId('slug-error')).toHaveTextContent(/reserved/i);
    });

    it('surfaces a conflict response as a form error', async () => {
        const user = userEvent.setup();
        const { completeCompanyOnboarding } = await import('../auth/auth.api');
        vi.mocked(completeCompanyOnboarding).mockRejectedValueOnce(
            new AxiosError('Conflict', 'ERR_BAD_REQUEST', undefined, undefined, {
                status: 409,
                data: { message: 'Company onboarding is not available for this account.' },
            } as never),
        );
        render(<CompanyOnboardingView />);

        await user.type(screen.getByTestId('company-onboarding-name'), 'Acme');
        await user.click(screen.getByTestId('company-onboarding-submit'));

        expect(await screen.findByTestId('company-onboarding-error')).toHaveTextContent(
            /not available/i,
        );
    });
});
