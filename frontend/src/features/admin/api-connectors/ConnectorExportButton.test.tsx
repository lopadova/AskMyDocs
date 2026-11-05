import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ConnectorExportButton } from './ConnectorExportButton';
import { apiConnectorsApi, type ApiConnector } from './api-connectors.api';
import { ToastHost } from '../shared/Toast';

afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); });

const connector = { id: 8, name: 'Orders / EU' } as ApiConnector;

describe('ConnectorExportButton', () => {
    it('downloads a ZIP with a safe filename and supports keyboard activation', async () => {
        const bytes = new Blob(['PK zip'], { type: 'application/zip' });
        vi.spyOn(apiConnectorsApi, 'exportPackage').mockResolvedValue(bytes);
        const create = vi.fn().mockReturnValue('blob:package');
        vi.stubGlobal('URL', { createObjectURL: create, revokeObjectURL: vi.fn() });
        let filename: string | undefined;
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) { filename = this.download; });
        render(<ConnectorExportButton connector={connector} testId="export-api" />);
        await userEvent.tab();
        expect(screen.getByRole('button', { name: 'Export API' })).toHaveFocus();
        await userEvent.keyboard('{Enter}');
        await waitFor(() => expect(create).toHaveBeenCalledWith(bytes));
        expect(filename).toBe('Orders-EU.askmydocs-api.zip');
        expect(screen.getByTestId('export-api')).not.toBeDisabled();
    });

    it('prevents duplicate exports while pending and shows the server failure', async () => {
        let reject!: (value: unknown) => void;
        const request = vi.spyOn(apiConnectorsApi, 'exportPackage').mockImplementation(() => new Promise((_, fail) => { reject = fail; }));
        render(<><ConnectorExportButton connector={connector} testId="export-api" /><ToastHost /></>);
        fireEvent.click(screen.getByTestId('export-api'));
        fireEvent.click(screen.getByTestId('export-api'));
        expect(request).toHaveBeenCalledOnce();
        expect(screen.getByTestId('export-api')).toBeDisabled();
        reject({ response: { data: { message: 'Move credentials out of public configuration.' }, status: 422 } });
        expect(await screen.findByTestId('toast-api-connector-export-error')).toHaveTextContent('Move credentials');
        await waitFor(() => expect(screen.getByTestId('export-api')).not.toBeDisabled());
    });
});
