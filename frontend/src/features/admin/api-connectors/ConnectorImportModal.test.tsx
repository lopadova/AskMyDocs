import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ConnectorImportModal } from './ConnectorImportModal';
import { apiConnectorsApi, type ConnectorImportPreview } from './api-connectors.api';

afterEach(() => vi.restoreAllMocks());

const preview: ConnectorImportPreview = {
    config: { connector: { name: 'Orders' }, routes: [{ url: 'https://api.example.com/orders' }] },
    name: 'Orders', endpoint_count: 1, relation_count: 0,
    required_credentials: [{ profile_ref: 'auth-1', profile_label: 'Default authentication', type: 'basic', key: 'password', label: 'Password', secret: true }],
};

function setup() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    const onImported = vi.fn();
    const onClose = vi.fn();
    render(<QueryClientProvider client={client}><ConnectorImportModal onClose={onClose} onImported={onImported} /></QueryClientProvider>);
    return { onImported, onClose };
}

function upload(text = '{}') {
    const file = new File([text], 'connector.json', { type: 'application/json' });
    Object.defineProperty(file, 'text', { value: () => Promise.resolve(text) });
    fireEvent.change(screen.getByTestId('api-connector-import-file'), { target: { files: [file] } });
}

describe('ConnectorImportModal', () => {
    it('asks only for missing credentials and submits the complete validated configuration', async () => {
        vi.spyOn(apiConnectorsApi, 'previewImport').mockResolvedValue(preview);
        const importConfig = vi.spyOn(apiConnectorsApi, 'importConfig').mockResolvedValue({ id: 42 } as never);
        const { onImported } = setup();
        expect(screen.getByTestId('api-connector-import-submit')).toBeDisabled();
        upload();
        const password = await screen.findByLabelText(/Password/);
        expect(password).toHaveAttribute('type', 'password');
        expect(screen.queryByLabelText(/Username/)).not.toBeInTheDocument();
        expect(screen.queryByText('auth-1')).not.toBeInTheDocument();
        expect(screen.getByTestId('api-connector-import-summary')).toHaveTextContent('1 endpoints');
        await userEvent.type(password, 'new-secret');
        await userEvent.click(screen.getByTestId('api-connector-import-submit'));
        await waitFor(() => expect(onImported).toHaveBeenCalledOnce());
        expect(importConfig).toHaveBeenCalledWith(preview.config, { 'auth-1': { password: 'new-secret' } });
    });

    it('imports public endpoints without showing any credential inputs', async () => {
        vi.spyOn(apiConnectorsApi, 'previewImport').mockResolvedValue({ ...preview, required_credentials: [] });
        const importConfig = vi.spyOn(apiConnectorsApi, 'importConfig').mockResolvedValue({ id: 42 } as never);
        setup();
        upload();
        await screen.findByText(/No credentials are required/);
        expect(screen.queryByTestId('api-connector-import-credential-0')).not.toBeInTheDocument();
        await userEvent.click(screen.getByTestId('api-connector-import-submit'));
        await waitFor(() => expect(importConfig).toHaveBeenCalledWith(preview.config, {}));
    });

    it('shows invalid JSON before making an API request', async () => {
        const validate = vi.spyOn(apiConnectorsApi, 'previewImport');
        setup();
        upload('invalid JSON');
        expect(await screen.findByRole('alert')).toHaveTextContent('valid JSON');
        expect(validate).not.toHaveBeenCalled();
        expect(screen.getByTestId('api-connector-import-submit')).toBeDisabled();
    });

    it('keeps entered credentials after a failed import so the user can retry', async () => {
        vi.spyOn(apiConnectorsApi, 'previewImport').mockResolvedValue(preview);
        vi.spyOn(apiConnectorsApi, 'importConfig').mockRejectedValue({ response: { status: 422, data: {
            message: 'Credential required.', errors: { 'credentials.auth-1.password': ['Enter a password.'] },
        } } });
        const { onImported } = setup();
        upload();
        const input = await screen.findByLabelText(/Password/);
        await userEvent.type(input, 'keep-this');
        await userEvent.click(screen.getByTestId('api-connector-import-submit'));
        await screen.findByText('Enter a password.');
        expect(input).toHaveValue('keep-this');
        expect(input).toHaveAttribute('aria-invalid', 'true');
        expect(onImported).not.toHaveBeenCalled();
    });

    it('blocks repeated submissions and closing while the import is pending', async () => {
        vi.spyOn(apiConnectorsApi, 'previewImport').mockResolvedValue({ ...preview, required_credentials: [] });
        let complete!: (value: never) => void;
        const importConfig = vi.spyOn(apiConnectorsApi, 'importConfig').mockImplementation(() => new Promise((resolve) => { complete = resolve; }));
        const { onImported, onClose } = setup();
        upload();
        await screen.findByText(/No credentials are required/);
        await userEvent.click(screen.getByTestId('api-connector-import-submit'));
        expect(screen.getByTestId('api-connector-import-submit')).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        expect(onClose).not.toHaveBeenCalled();
        expect(importConfig).toHaveBeenCalledOnce();
        complete({ id: 42 } as never);
        await waitFor(() => expect(onImported).toHaveBeenCalledOnce());
    });

    it('clears secrets when a different configuration file is selected', async () => {
        vi.spyOn(apiConnectorsApi, 'previewImport').mockResolvedValue(preview);
        setup();
        upload();
        fireEvent.change(await screen.findByLabelText(/Password/), { target: { value: 'old-secret' } });
        upload('{"new":true}');
        expect(await screen.findByLabelText(/Password/)).toHaveValue('');
    });
});
