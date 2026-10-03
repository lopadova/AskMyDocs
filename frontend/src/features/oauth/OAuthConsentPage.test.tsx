import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { OAuthConsentPage } from './OAuthConsentPage';
import { OAuthConnectionsPage } from './OAuthConnectionsPage';
import { decideConsent, disconnectConnection, getConnections, getConsent } from './oauth.api';

vi.mock('./oauth.api', () => ({ getConsent: vi.fn(), decideConsent: vi.fn(), getConnections: vi.fn(), disconnectConnection: vi.fn() }));
vi.mock('../auth/LoginPage', () => ({ LoginPage: () => <div>Sign in to continue</div> }));
const assign = vi.fn();
const consent = {
    client: { name: 'Partner app', redirect_uri: 'https://partner.example/callback' },
    scopes: [{ id: 'kb:read', description: 'Search and read your documents' }],
    teams: [{ tenant_id: 'acme', name: 'Acme' }, { tenant_id: 'other', name: 'Other' }],
    user: { name: 'User', email: 'user@example.test' }, token_ttl_days: 30,
};

beforeEach(() => {
    vi.resetAllMocks();
    vi.stubGlobal('location', { ...window.location, assign });
    vi.mocked(getConsent).mockResolvedValue(consent);
    vi.mocked(decideConsent).mockResolvedValue({ redirect_to: 'https://partner.example/callback?code=one&state=csrf' });
});

afterEach(() => vi.unstubAllGlobals());

describe('OAuth consent', () => {
    it('shows the app, callback origin, exact permissions and account before approving the selected company', async () => {
        const user = userEvent.setup();
        render(<OAuthConsentPage requestId="request-one" />);
        expect(await screen.findByRole('heading', { name: 'Connect Partner app' })).toBeInTheDocument();
        expect(screen.getByText('Search and read your documents')).toBeInTheDocument();
        expect(decideConsent).not.toHaveBeenCalled();
        await user.selectOptions(screen.getByLabelText('Company'), 'other');
        await user.click(screen.getByRole('button', { name: 'Connect application' }));
        expect(decideConsent).toHaveBeenCalledWith('request-one', 'approve', 'other');
    });

    it('returns an explicit denial when cancel is activated', async () => {
        render(<OAuthConsentPage requestId="request-one" />);
        await userEvent.click(await screen.findByRole('button', { name: 'Cancel' }));
        expect(decideConsent).toHaveBeenCalledWith('request-one', 'deny', 'acme');
    });

    it('keeps approval disabled for an account with no active company', async () => {
        vi.mocked(getConsent).mockResolvedValue({ ...consent, teams: [] });
        render(<OAuthConsentPage requestId="request-one" />);
        expect(await screen.findByRole('button', { name: 'Connect application' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeEnabled();
    });

    it('requires sign-in after a 401 without losing the connection route', async () => {
        vi.mocked(getConsent).mockRejectedValue({ isAxiosError: true, response: { status: 401 } });
        render(<OAuthConsentPage requestId="request-one" />);
        expect(await screen.findByText('Sign in to continue')).toBeInTheDocument();
    });

    it('shows expired or rejected requests without an approval action', async () => {
        vi.mocked(getConsent).mockRejectedValue({ isAxiosError: true, response: { status: 400, data: { error_description: 'Request expired' } } });
        render(<OAuthConsentPage requestId="expired" />);
        expect(await screen.findByRole('alert')).toHaveTextContent('Request expired');
        expect(screen.queryByRole('button', { name: 'Connect application' })).not.toBeInTheDocument();
    });
});

describe('OAuth connections', () => {
    it('revokes the selected application and removes it only after the server succeeds', async () => {
        vi.mocked(getConnections).mockResolvedValue([{ id: 12, name: 'Partner app', tenant_id: 'acme', scopes: ['kb:read'],
            created_at: '2026-10-03T10:00:00Z', expires_at: '2026-11-02T10:00:00Z', last_used_at: null }]);
        vi.mocked(disconnectConnection).mockResolvedValue(undefined);
        render(<OAuthConnectionsPage />);
        await userEvent.click(await screen.findByRole('button', { name: 'Revoke access' }));
        expect(disconnectConnection).toHaveBeenCalledWith(12);
        expect(await screen.findByText('No external applications connected.')).toBeInTheDocument();
    });
});
