import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { AutoWikiSettingsView } from './AutoWikiSettingsView';
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

function entry(project: string, override: Record<string, boolean | null> | null, effective: { enabled: boolean; canonical: boolean; non_canonical: boolean }) {
    return { project_key: project, override, effective };
}

function settings(projects: ReturnType<typeof entry>[]) {
    return {
        data: {
            defaults: { enabled: true, canonical: true, non_canonical: true },
            wildcard: entry('*', null, { enabled: true, canonical: true, non_canonical: true }),
            projects,
        },
    };
}

describe('AutoWikiSettingsView', () => {
    it('renders the loading state initially', () => {
        mockGet.mockImplementation(() => new Promise(() => {}));
        render(withQueryClient(<AutoWikiSettingsView />));
        expect(screen.getByTestId('admin-autowiki-settings-loading')).toHaveAttribute('data-state', 'loading');
    });

    it('renders the tenant-wide row + per-project rows with effective values', async () => {
        mockGet.mockResolvedValue(settings([
            entry('eng', { enabled: false, canonical: null, non_canonical: null }, { enabled: false, canonical: false, non_canonical: false }),
        ]));
        render(withQueryClient(<AutoWikiSettingsView />));
        await waitFor(() => expect(screen.getByTestId('admin-autowiki-settings-list')).toHaveAttribute('data-state', 'ready'));
        expect(screen.getByTestId('admin-autowiki-setting-*')).toBeInTheDocument();
        expect(screen.getByTestId('admin-autowiki-setting-eng')).toBeInTheDocument();
        // eng has Auto-build overridden OFF → effective off.
        expect(screen.getByTestId('admin-autowiki-setting-eng-enabled-effective')).toHaveTextContent('off');
        // The control reflects the override value (off), not inherit.
        expect(screen.getByTestId('admin-autowiki-setting-eng-enabled')).toHaveValue('off');
    });

    it('changing a flag PUTs the override', async () => {
        mockGet.mockResolvedValue(settings([
            entry('eng', null, { enabled: true, canonical: true, non_canonical: true }),
        ]));
        mockPut.mockResolvedValue({ data: { ok: true, setting: entry('eng', { enabled: false, canonical: null, non_canonical: null }, { enabled: false, canonical: false, non_canonical: false }) } });
        render(withQueryClient(<AutoWikiSettingsView />));
        await waitFor(() => expect(screen.getByTestId('admin-autowiki-setting-eng-enabled')).toBeInTheDocument());
        await userEvent.selectOptions(screen.getByTestId('admin-autowiki-setting-eng-enabled'), 'off');
        await waitFor(() => expect(mockPut).toHaveBeenCalledWith('/api/admin/kb/autowiki-settings', { project_key: 'eng', autowiki_enabled: false }));
    });

    it('shows the empty-projects message when only the tenant default exists', async () => {
        mockGet.mockResolvedValue(settings([]));
        render(withQueryClient(<AutoWikiSettingsView />));
        const empty = await screen.findByTestId('admin-autowiki-settings-empty');
        expect(empty).toHaveAttribute('data-state', 'empty');
    });

    it('surfaces a save error in the DOM (not silent)', async () => {
        mockGet.mockResolvedValue(settings([entry('eng', null, { enabled: true, canonical: true, non_canonical: true })]));
        mockPut.mockRejectedValue(new Error('save boom 500'));
        render(withQueryClient(<AutoWikiSettingsView />));
        await waitFor(() => expect(screen.getByTestId('admin-autowiki-setting-eng-enabled')).toBeInTheDocument());
        await userEvent.selectOptions(screen.getByTestId('admin-autowiki-setting-eng-enabled'), 'off');
        expect(await screen.findByTestId('admin-autowiki-settings-save-error')).toHaveTextContent('save boom 500');
    });

    it('renders an error state when the settings query fails', async () => {
        mockGet.mockRejectedValue(new Error('load boom 500'));
        render(withQueryClient(<AutoWikiSettingsView />));
        const err = await screen.findByTestId('admin-autowiki-settings-error');
        expect(err).toHaveAttribute('data-state', 'error');
    });
});
