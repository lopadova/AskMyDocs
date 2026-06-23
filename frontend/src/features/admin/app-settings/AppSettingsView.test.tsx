import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { AppSettingDto } from './app-settings.api';

/*
 * v8.22 (Ciclo 3) — AppSettingsView unit tests. Mocks the list/mutation hooks
 * so loading / error / empty / ready states + the edit→save transition assert
 * without a backend. R16: each test drives the state it claims.
 */

interface QueryMock {
    data: AppSettingDto[] | undefined;
    isLoading: boolean;
    isError: boolean;
}

const listMock: QueryMock = { data: undefined, isLoading: false, isError: false };
const mutateMock = vi.fn();
const mutationState = { isPending: false, isError: false, error: null as unknown };

vi.mock('./app-settings-hooks', () => ({
    useAppSettings: () => ({ ...listMock, refetch: vi.fn() }),
    useSetAppSetting: () => ({ mutate: mutateMock, ...mutationState }),
}));

vi.mock('../shell/AdminShell', () => ({
    AdminShell: ({ children }: { children: React.ReactNode }) => <div data-testid="admin-shell">{children}</div>,
}));

import { AppSettingsView } from './AppSettingsView';

function wrap(ui: React.ReactElement) {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

function setting(over: Partial<AppSettingDto> = {}): AppSettingDto {
    return {
        key: 'ai.provider',
        label: 'AI chat provider',
        type: 'enum',
        scope: 'tenant',
        deploy_only: false,
        enum: ['openai', 'anthropic', 'gemini'],
        value: 'openai',
        source: 'config',
        ...over,
    };
}

beforeEach(() => {
    listMock.data = undefined;
    listMock.isLoading = false;
    listMock.isError = false;
    mutateMock.mockReset();
    mutationState.isPending = false;
    mutationState.isError = false;
    mutationState.error = null;
});

describe('AppSettingsView', () => {
    it('shows the loading state', () => {
        listMock.isLoading = true;
        wrap(<AppSettingsView />);
        expect(screen.getByTestId('admin-app-settings')).toHaveAttribute('data-state', 'loading');
        expect(screen.getByTestId('admin-app-settings-loading')).toBeInTheDocument();
    });

    it('surfaces the error state with a retry', () => {
        listMock.isError = true;
        wrap(<AppSettingsView />);
        expect(screen.getByTestId('admin-app-settings')).toHaveAttribute('data-state', 'error');
        expect(screen.getByTestId('admin-app-settings-retry')).toBeInTheDocument();
    });

    it('shows the empty state when no settings are registered', () => {
        listMock.data = [];
        wrap(<AppSettingsView />);
        expect(screen.getByTestId('admin-app-settings-empty')).toBeInTheDocument();
        expect(screen.queryByTestId('admin-app-settings-table')).not.toBeInTheDocument();
    });

    it('renders a row per setting with its source badge', () => {
        listMock.data = [
            setting({ source: 'tenant', value: 'anthropic' }),
            setting({ key: 'connector.sync_cadence_minutes', label: 'Cadence', type: 'int', scope: 'both', enum: null, value: 15, source: 'config' }),
        ];
        wrap(<AppSettingsView />);
        expect(screen.getByTestId('app-setting-row-ai.provider')).toHaveAttribute('data-source', 'tenant');
        expect(screen.getByTestId('app-setting-ai.provider-source')).toHaveTextContent('tenant override');
        expect(screen.getByTestId('app-setting-connector.sync_cadence_minutes-source')).toHaveTextContent('config default');
    });

    it('renders a deploy-only key read-only (no input, deploy-managed pill)', () => {
        listMock.data = [
            setting({ key: 'ai_finops.enabled', label: 'FinOps', type: 'bool', enum: null, value: true, deploy_only: true }),
        ];
        wrap(<AppSettingsView />);
        expect(screen.getByTestId('app-setting-ai_finops.enabled-deploy-only')).toBeInTheDocument();
        expect(screen.queryByTestId('app-setting-ai_finops.enabled-input')).not.toBeInTheDocument();
    });

    it('saves an edited enum value (drives the edit → Save transition)', () => {
        listMock.data = [setting({ value: 'openai', source: 'config' })];
        wrap(<AppSettingsView />);

        const save = screen.getByTestId('app-setting-ai.provider-save');
        // Save is disabled until the value actually changes.
        expect(save).toBeDisabled();

        fireEvent.change(screen.getByTestId('app-setting-ai.provider-input'), { target: { value: 'anthropic' } });
        expect(save).toBeEnabled();

        fireEvent.click(save);
        expect(mutateMock).toHaveBeenCalledWith({ key: 'ai.provider', value: 'anthropic' });
    });

    it('renders a null-valued enum with the unset option selected (no crash)', () => {
        listMock.data = [setting({ value: null, source: 'config' })];
        wrap(<AppSettingsView />);
        const select = screen.getByTestId('app-setting-ai.provider-input') as HTMLSelectElement;
        expect(select.value).toBe('');
    });

    it('clears an override by selecting the unset option (submits null)', () => {
        listMock.data = [setting({ value: 'anthropic', source: 'tenant' })];
        wrap(<AppSettingsView />);
        fireEvent.change(screen.getByTestId('app-setting-ai.provider-input'), { target: { value: '' } });
        fireEvent.click(screen.getByTestId('app-setting-ai.provider-save'));
        expect(mutateMock).toHaveBeenCalledWith({ key: 'ai.provider', value: null });
    });

    it('marks a tenant-scoped key read-only when a project scope is active', () => {
        listMock.data = [setting()];
        wrap(<AppSettingsView />);

        // Initially (tenant-wide '*') the enum is editable.
        expect(screen.getByTestId('app-setting-ai.provider-input')).toBeInTheDocument();

        // Enter a project scope → the tenant-scoped key becomes read-only.
        fireEvent.change(screen.getByTestId('app-settings-scope'), { target: { value: 'engineering' } });
        expect(screen.getByTestId('app-setting-ai.provider-tenant-only')).toBeInTheDocument();
        expect(screen.queryByTestId('app-setting-ai.provider-input')).not.toBeInTheDocument();
    });

    it('surfaces a mutation error inline', () => {
        listMock.data = [setting({ value: 'openai' })];
        mutationState.isError = true;
        mutationState.error = new Error('boom');
        wrap(<AppSettingsView />);
        expect(screen.getByTestId('app-setting-ai.provider-error')).toBeInTheDocument();
    });
});
