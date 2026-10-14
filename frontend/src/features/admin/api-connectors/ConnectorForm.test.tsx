import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { ConnectorForm, type ConnectorFormProps } from './ConnectorForm';
import type { ApiConnector } from './api-connectors.api';

const HEADER = 'X-AskMyDocs-Cloudflare-Token';

function connector(headers: Record<string, string>): ApiConnector {
    return {
        id: 1, name: 'Orders', project_key: null, description: null,
        base_url: null, default_auth_profile_id: null, headers, is_active: true,
        created_at: null, updated_at: null,
    };
}

function setup(props: Partial<ConnectorFormProps> = {}) {
    const onSubmit = vi.fn();
    render(<ConnectorForm projects={[]} onSubmit={onSubmit} onClose={vi.fn()} {...props} />);
    return { onSubmit };
}

describe('ConnectorForm Cloudflare header', () => {
    it('submits the fixed header alongside other headers and connector settings', () => {
        const { onSubmit } = setup();
        fireEvent.change(screen.getByLabelText(/Name/), { target: { value: 'Orders' } });
        fireEvent.change(screen.getByLabelText(/Cloudflare rule token/), { target: { value: 'gateway-secret' } });
        fireEvent.change(screen.getByTestId('api-connector-form-headers'), { target: { value: 'X-Tenant: 42' } });
        fireEvent.click(screen.getByRole('button', { name: 'Create connector' }));
        expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({
            name: 'Orders', headers: { 'X-Tenant': '42', [HEADER]: 'gateway-secret' },
        }));
    });

    it('loads an existing header regardless of casing, keeps it masked and allows removal', () => {
        const { onSubmit } = setup({ connector: connector({ [HEADER.toLowerCase()]: 'existing-secret', 'X-Tenant': '42' }) });
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
        const { onSubmit } = setup();
        fireEvent.change(screen.getByLabelText(/Name/), { target: { value: 'Orders' } });
        fireEvent.change(screen.getByTestId('api-connector-form-headers'), { target: { value: 'X-Gateway-Key: custom-secret' } });
        fireEvent.click(screen.getByRole('button', { name: 'Create connector' }));
        expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ headers: { 'X-Gateway-Key': 'custom-secret' } }));
    });

    it('keeps the dedicated value when the textarea contains a duplicate', () => {
        const { onSubmit } = setup();
        fireEvent.change(screen.getByLabelText(/Name/), { target: { value: 'Orders' } });
        fireEvent.change(screen.getByLabelText(/Cloudflare rule token/), { target: { value: 'dedicated-secret' } });
        fireEvent.change(screen.getByTestId('api-connector-form-headers'), { target: { value: `${HEADER.toLowerCase()}: duplicate-secret` } });
        fireEvent.click(screen.getByRole('button', { name: 'Create connector' }));
        expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ headers: { [HEADER]: 'dedicated-secret' } }));
    });

    it('preserves the header when explicitly pasted into Default headers', () => {
        const { onSubmit } = setup();
        fireEvent.change(screen.getByLabelText(/Name/), { target: { value: 'Orders' } });
        fireEvent.change(screen.getByTestId('api-connector-form-headers'), { target: { value: `${HEADER}: pasted-secret` } });
        fireEvent.click(screen.getByRole('button', { name: 'Create connector' }));
        expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ headers: { [HEADER]: 'pasted-secret' } }));
    });

    it('preserves the value and shows field errors after a rejected save', () => {
        setup({ connector: connector({ [HEADER]: 'keep-secret' }),
            fieldErrors: { [`headers.${HEADER}`]: 'Invalid header value.' } });
        expect(screen.getByLabelText(/Cloudflare rule token/)).toHaveValue('keep-secret');
        expect(screen.getByLabelText(/Cloudflare rule token/)).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByRole('alert')).toHaveTextContent('Invalid header value.');
    });

    it('blocks duplicate saves while pending', () => {
        const { onSubmit } = setup({ isSubmitting: true });
        expect(screen.getByRole('button', { name: 'Create connector' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Create connector' }));
        expect(onSubmit).not.toHaveBeenCalled();
    });
});
