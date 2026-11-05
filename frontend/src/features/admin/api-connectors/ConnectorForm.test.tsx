import { chooseSelectOption } from '@/test-utils/select';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ConnectorForm, type ConnectorFormProps } from './ConnectorForm';
import { DEFAULT_API_USER_AGENT } from './user-agents';
import type { ApiConnector } from './api-connectors.api';

function setup(headers?: Record<string, string>, isSubmitting = false) {
    const onSubmit = vi.fn();
    const connector = headers === undefined ? undefined : {
        id: 1, name: 'Orders', headers, is_active: true,
    } as ApiConnector;
    render(<ConnectorForm connector={connector} projects={[]} onSubmit={onSubmit} onClose={vi.fn()} isSubmitting={isSubmitting} />);
    return onSubmit;
}

function submit() {
    fireEvent.change(screen.getByTestId('api-connector-form-name'), { target: { value: 'Orders' } });
    fireEvent.submit(screen.getByTestId('api-connector-form'));
}

describe('ConnectorForm User-Agent', () => {
    it('defaults new connectors to Chrome and preserves other headers when selecting a browser', async () => {
        const onSubmit = setup();
        expect(screen.getByLabelText('User-Agent')).toHaveAttribute('data-value', 'chrome');
        await chooseSelectOption(screen.getByLabelText('User-Agent'), 'firefox');
        fireEvent.change(screen.getByTestId('api-connector-form-headers'), { target: { value: 'X-Account: orders' } });
        fireEvent.change(screen.getByTestId('api-connector-form-name'), { target: { value: 'Orders' } });
        await userEvent.click(screen.getByTestId('api-connector-form-submit'));
        expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({
            headers: { 'User-Agent': expect.stringContaining('Firefox/'), 'X-Account': 'orders' },
        }));
    });

    it('sends Chrome when a new connector is saved without changing the selection', () => {
        const onSubmit = setup();
        submit();
        expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({
            headers: { 'User-Agent': expect.stringContaining('Chrome/') },
        }));
    });

    it('preserves an existing custom value regardless of header casing and replaces it without duplicates', async () => {
        const onSubmit = setup({ 'uSeR-aGeNt': 'PartnerClient/2.0', 'X-Account': 'orders' });
        expect(screen.getByLabelText('User-Agent')).toHaveAttribute('data-value', 'custom');
        expect(screen.getByLabelText('Custom User-Agent')).toHaveValue('PartnerClient/2.0');
        expect(screen.getByTestId('api-connector-form-headers')).toHaveValue('X-Account: orders');
        await chooseSelectOption(screen.getByLabelText('User-Agent'), 'safari');
        submit();
        expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({
            headers: { 'User-Agent': expect.stringContaining('Version/18.1 Safari/'), 'X-Account': 'orders' },
        }));
    });

    it('accepts a custom value and requires a nonblank value', async () => {
        const onSubmit = setup();
        await chooseSelectOption(screen.getByLabelText('User-Agent'), 'custom');
        const input = screen.getByLabelText('Custom User-Agent');
        fireEvent.change(input, { target: { value: '   ' } });
        expect(input).toBeInvalid();
        fireEvent.change(input, { target: { value: ' PartnerClient/3.0 ' } });
        expect(input).toBeValid();
        submit();
        expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ headers: { 'User-Agent': 'PartnerClient/3.0' } }));
    });

    it('preserves the HTTP client default for existing connectors with no User-Agent', () => {
        const onSubmit = setup({ 'X-Account': 'orders' });
        expect(screen.getByLabelText('User-Agent')).toHaveAttribute('data-value', 'default');
        submit();
        expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ headers: { 'X-Account': 'orders' } }));
    });

    it('can remove an explicit User-Agent and restore the HTTP client default', async () => {
        const onSubmit = setup({ 'user-agent': 'PartnerClient/2.0', 'X-Account': 'orders' });
        await chooseSelectOption(screen.getByLabelText('User-Agent'), 'default');
        expect(screen.queryByLabelText('Custom User-Agent')).not.toBeInTheDocument();
        submit();
        expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ headers: { 'X-Account': 'orders' } }));
    });

    it('shows a saved preset when reopening the connector', async () => {
        const onSubmit = setup();
        await chooseSelectOption(screen.getByLabelText('User-Agent'), 'askmydocs');
        submit();
        const payload = onSubmit.mock.calls[0][0];
        expect(payload.headers['User-Agent']).toBe('AskMyDocs/1.0');
        // The preset is recognised from the saved header, with no separate setting.
        cleanup();
        render(<ConnectorForm connector={{ ...payload, id: 2 } as ApiConnector} projects={[]} onSubmit={vi.fn()} onClose={vi.fn()} />);
        expect(screen.getByLabelText('User-Agent')).toHaveAttribute('data-value', 'askmydocs');
    });

    it('reports conflicting headers instead of silently ignoring a second User-Agent', () => {
        const onSubmit = setup();
        fireEvent.change(screen.getByTestId('api-connector-form-headers'), { target: { value: 'USER-AGENT: OtherClient/1.0' } });
        submit();
        expect(onSubmit).not.toHaveBeenCalled();
        expect(screen.getByRole('alert')).toHaveTextContent('Choose the User-Agent above');
    });

    it('disables the selector and custom input while saving', () => {
        setup({ 'User-Agent': 'PartnerClient/2.0' }, true);
        expect(screen.getByLabelText('User-Agent')).toBeDisabled();
        expect(screen.getByLabelText('Custom User-Agent')).toBeDisabled();
        expect(screen.getByTestId('api-connector-form-submit')).toBeDisabled();
    });
});

const HEADER = 'X-AskMyDocs-Cloudflare-Token';

function cloudflareConnector(headers: Record<string, string>): ApiConnector {
    return {
        id: 1, name: 'Orders', project_key: null, description: null,
        base_url: null, default_auth_profile_id: null, headers, is_active: true,
        created_at: null, updated_at: null,
    };
}

function setupCloudflare(props: Partial<ConnectorFormProps> = {}) {
    const onSubmit = vi.fn();
    render(<ConnectorForm projects={[]} onSubmit={onSubmit} onClose={vi.fn()} {...props} />);
    return { onSubmit };
}

describe('ConnectorForm Cloudflare header', () => {
    it('submits the fixed header alongside other headers and connector settings', () => {
        const { onSubmit } = setupCloudflare();
        fireEvent.change(screen.getByLabelText(/Name/), { target: { value: 'Orders' } });
        fireEvent.change(screen.getByLabelText(/Cloudflare rule token/), { target: { value: 'gateway-secret' } });
        fireEvent.change(screen.getByTestId('api-connector-form-headers'), { target: { value: 'X-Tenant: 42' } });
        fireEvent.click(screen.getByRole('button', { name: 'Create connector' }));
        expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({
            name: 'Orders', headers: { 'User-Agent': DEFAULT_API_USER_AGENT, 'X-Tenant': '42', [HEADER]: 'gateway-secret' },
        }));
    });

    it('loads an existing header regardless of casing, keeps it masked and allows removal', () => {
        const { onSubmit } = setupCloudflare({ connector: cloudflareConnector({ [HEADER.toLowerCase()]: 'existing-secret', 'X-Tenant': '42' }) });
        const token = screen.getByLabelText(/Cloudflare rule token/);
        expect(token).toHaveAttribute('type', 'password');
        expect(token).toHaveAttribute('autocomplete', 'new-password');
        expect(token).toHaveValue('existing-secret');
        expect(screen.getByTestId('api-connector-form-headers')).toHaveValue('X-Tenant: 42');
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(onSubmit).toHaveBeenLastCalledWith(expect.objectContaining({ headers: {
            [HEADER]: 'existing-secret', 'X-Tenant': '42',
        } }));
        fireEvent.change(token, { target: { value: '' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(onSubmit).toHaveBeenLastCalledWith(expect.objectContaining({ headers: { 'X-Tenant': '42' } }));
    });

    it('omits the optional header when blank and supports another header name', () => {
        const { onSubmit } = setupCloudflare();
        fireEvent.change(screen.getByLabelText(/Name/), { target: { value: 'Orders' } });
        fireEvent.change(screen.getByTestId('api-connector-form-headers'), { target: { value: 'X-Gateway-Key: custom-secret' } });
        fireEvent.click(screen.getByRole('button', { name: 'Create connector' }));
        expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ headers: { 'User-Agent': DEFAULT_API_USER_AGENT, 'X-Gateway-Key': 'custom-secret' } }));
    });

    it('keeps the dedicated value when the textarea contains a duplicate', () => {
        const { onSubmit } = setupCloudflare();
        fireEvent.change(screen.getByLabelText(/Name/), { target: { value: 'Orders' } });
        fireEvent.change(screen.getByLabelText(/Cloudflare rule token/), { target: { value: 'dedicated-secret' } });
        fireEvent.change(screen.getByTestId('api-connector-form-headers'), { target: { value: `${HEADER.toLowerCase()}: duplicate-secret` } });
        fireEvent.click(screen.getByRole('button', { name: 'Create connector' }));
        expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ headers: { 'User-Agent': DEFAULT_API_USER_AGENT, [HEADER]: 'dedicated-secret' } }));
    });

    it('preserves the header when explicitly pasted into Default headers', () => {
        const { onSubmit } = setupCloudflare();
        fireEvent.change(screen.getByLabelText(/Name/), { target: { value: 'Orders' } });
        fireEvent.change(screen.getByTestId('api-connector-form-headers'), { target: { value: `${HEADER}: pasted-secret` } });
        fireEvent.click(screen.getByRole('button', { name: 'Create connector' }));
        expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ headers: { 'User-Agent': DEFAULT_API_USER_AGENT, [HEADER]: 'pasted-secret' } }));
    });

    it('preserves the value and shows field errors after a rejected save', () => {
        setupCloudflare({ connector: cloudflareConnector({ [HEADER]: 'keep-secret' }),
            fieldErrors: { [`headers.${HEADER}`]: 'Invalid header value.' } });
        expect(screen.getByLabelText(/Cloudflare rule token/)).toHaveValue('keep-secret');
        expect(screen.getByLabelText(/Cloudflare rule token/)).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByRole('alert')).toHaveTextContent('Invalid header value.');
    });

    it('blocks duplicate saves while pending', () => {
        const { onSubmit } = setupCloudflare({ isSubmitting: true });
        expect(screen.getByRole('button', { name: 'Create connector' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Create connector' }));
        expect(onSubmit).not.toHaveBeenCalled();
    });
});
