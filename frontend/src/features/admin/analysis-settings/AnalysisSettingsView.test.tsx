import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { AnalysisSettingsView } from './AnalysisSettingsView';
import { api } from '../../../lib/api';

const mockGet = vi.fn();
const mockPut = vi.fn();

beforeEach(() => {
    mockGet.mockReset();
    mockPut.mockReset();
    vi.spyOn(api, 'get').mockImplementation(mockGet);
    vi.spyOn(api, 'put').mockImplementation(mockPut);
});
afterEach(() => vi.restoreAllMocks());

function withQueryClient(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

const SETTINGS = {
    data: {
        defaults: { enabled: true, canonical: true, non_canonical: false, delete_enabled: true },
        wildcard: {
            project_key: '*',
            override: null,
            effective: { enabled: true, canonical: true, non_canonical: false, delete_enabled: true },
        },
        projects: [
            {
                project_key: 'eng',
                override: { enabled: false, canonical: null, non_canonical: null, delete_enabled: null },
                effective: { enabled: false, canonical: false, non_canonical: false, delete_enabled: false },
            },
        ],
    },
};

describe('AnalysisSettingsView', () => {
    it('renders the loading state initially', () => {
        mockGet.mockImplementation(() => new Promise(() => {}));
        render(withQueryClient(<AnalysisSettingsView />));
        expect(screen.getByTestId('admin-analysis-settings-loading')).toHaveAttribute('data-state', 'loading');
    });

    it('renders the wildcard row + per-project rows with effective values', async () => {
        mockGet.mockResolvedValue(SETTINGS);
        render(withQueryClient(<AnalysisSettingsView />));

        await waitFor(() => expect(screen.getByTestId('admin-analysis-setting-*')).toBeVisible());
        expect(screen.getByTestId('admin-analysis-setting-eng')).toBeVisible();
        // eng has enabled override = off; the select reflects 'off'.
        expect(screen.getByTestId('admin-analysis-setting-eng-enabled')).toHaveValue('off');
        // effective canonical for eng resolves to off (because enabled=off).
        expect(screen.getByTestId('admin-analysis-setting-eng-enabled-effective')).toHaveTextContent('off');
    });

    it('changing a flag select PUTs the full override payload', async () => {
        mockGet.mockResolvedValue(SETTINGS);
        mockPut.mockResolvedValue({ data: { ok: true, setting: SETTINGS.data.projects[0] } });
        render(withQueryClient(<AnalysisSettingsView />));

        await waitFor(() => expect(screen.getByTestId('admin-analysis-setting-eng-enabled')).toBeVisible());
        await userEvent.selectOptions(screen.getByTestId('admin-analysis-setting-eng-enabled'), 'on');

        await waitFor(() => {
            expect(mockPut).toHaveBeenCalledWith('/api/admin/kb/analysis-settings', {
                project_key: 'eng',
                enabled: true,
                canonical: null,
                non_canonical: null,
                delete_enabled: null,
            });
        });
    });

    it('surfaces a mutation error in the DOM (not silent)', async () => {
        mockGet.mockResolvedValue(SETTINGS);
        mockPut.mockRejectedValue(new Error('save boom 500'));
        render(withQueryClient(<AnalysisSettingsView />));

        await waitFor(() => expect(screen.getByTestId('admin-analysis-setting-eng-canonical')).toBeVisible());
        await userEvent.selectOptions(screen.getByTestId('admin-analysis-setting-eng-canonical'), 'on');

        const err = await screen.findByTestId('admin-analysis-settings-save-error');
        expect(err).toHaveTextContent('save boom 500');
    });

    it('renders an error state (not the list) when the query fails', async () => {
        mockGet.mockRejectedValue(new Error('load boom 500'));
        render(withQueryClient(<AnalysisSettingsView />));
        const err = await screen.findByTestId('admin-analysis-settings-error');
        expect(err).toHaveAttribute('data-state', 'error');
        expect(screen.queryByTestId('admin-analysis-settings-list')).not.toBeInTheDocument();
    });
});
