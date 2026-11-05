import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { chooseSelectOption } from '@/test-utils/select';
import { setLocalUiLocale } from '../../../lib/ui-locale';
import { useTeamStore } from '../../../lib/team-store';
import { adminProjectsApi } from '../projects/admin-projects.api';
import { AppSettingsView } from './AppSettingsView';
import { adminAppSettingsApi, type AiModelCatalog, type AiModelOption, type AppSettingDto } from './app-settings.api';
import { modelPrice } from './ModelPicker';

vi.mock('../shell/AdminShell', () => ({ AdminShell: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));

export const model = (id: string, overrides: Partial<AiModelOption> = {}): AiModelOption => ({
    id, name: id, input_per_million: .1, output_per_million: .4, currency: 'USD', price_source: 'openrouter',
    price_updated_at: '2026-10-27T08:00:00Z', context_length: 100000, tools_supported: true, selectable: true, deprecated: false, created: 1, ...overrides,
});
export const modelCatalog = (provider = 'openrouter'): AiModelCatalog => ({
    provider, configured: true, default_model: provider === 'openrouter' ? 'vendor/current' : 'claude-current',
    models: provider === 'openrouter' ? [model('vendor/current', { name: 'Current model' }), model('vendor/other', { name: 'Other model', input_per_million: .2 })]
        : [model('claude-current', { name: 'Current Claude', price_source: 'litellm', input_per_million: 3, output_per_million: 15 })],
    updated_at: '2026-10-27T08:00:00Z', stale: false, error: null,
});

export const settingsRoster = (): AppSettingDto[] => [
    { key: 'agent.tenant_instructions', label: 'Tenant assistant operating instructions', type: 'string', scope: 'tenant', deploy_only: false, enum: null, value: 'Original operating brief', source: 'tenant', inherited_value: null },
    { key: 'ai.provider', label: 'AI chat provider', type: 'enum', scope: 'tenant', deploy_only: false, enum: ['openrouter', 'anthropic'], value: 'openrouter', source: 'tenant', inherited_value: 'openrouter' },
    { key: 'ai.chat_model', label: 'Agent text model for every stage', type: 'string', scope: 'both', deploy_only: false, enum: null, value: 'vendor/current', source: 'tenant', inherited_value: null },
    { key: 'agent.synthesis.model', label: 'Agent answer model', type: 'string', scope: 'both', deploy_only: false, enum: null, value: 'vendor/other', source: 'tenant', inherited_value: null },
    { key: 'agent.synthesis.repair_model', label: 'Agent answer repair model', type: 'string', scope: 'both', deploy_only: false, enum: null, value: null, source: 'config', inherited_value: null },
    { key: 'ai.chat_routing', label: 'Agent provider routing', type: 'enum', scope: 'both', deploy_only: false, enum: ['default', 'economy'], value: 'economy', source: 'tenant', inherited_value: 'default' },
    { key: 'connector.sync_cadence_minutes', label: 'Connector sync cadence (minutes)', type: 'int', scope: 'both', deploy_only: false, enum: null, value: 15, source: 'config', inherited_value: 15, min: 5, max: 1440 },
    { key: 'ai_finops.enabled', label: 'AI FinOps metering (deploy-managed)', type: 'bool', scope: 'tenant', deploy_only: true, enum: null, value: true, source: 'config' },
];

function mount() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
    return render(<QueryClientProvider client={client}><AppSettingsView /></QueryClientProvider>);
}

beforeEach(() => {
    vi.restoreAllMocks();
    setLocalUiLocale('en');
    useTeamStore.setState({ currentTeam: 'autry', teams: [{ tenant_id: 'autry', name: 'Autry', hash: 'autry', projects: [] }], userId: 1 });
    vi.spyOn(adminProjectsApi, 'list').mockResolvedValue([{ id: 1, project_key: 'sales', name: 'Sales', description: null, document_count: 0, member_count: 0 }]);
    vi.spyOn(adminAppSettingsApi, 'list').mockResolvedValue(settingsRoster());
    vi.spyOn(adminAppSettingsApi, 'models').mockImplementation(async (provider) => modelCatalog(provider));
    vi.spyOn(adminAppSettingsApi, 'setMany').mockImplementation(async (values) => settingsRoster().map((setting) => setting.key in values ? { ...setting, value: values[setting.key] } : setting));
    vi.spyOn(adminAppSettingsApi, 'assist').mockResolvedValue({ summary: 'A clearer operating brief', provider: 'openrouter', model: 'vendor/current',
        changes: [{ key: 'agent.tenant_instructions', value: 'Use authorized sources and reconcile later corrections.', reason: 'Keep advice evidence-based.' }] });
});

describe('Configuration form', () => {
    it('groups settings, explains their purpose, and uses a full-size instruction editor', async () => {
        mount();
        expect(await screen.findByRole('heading', { name: 'Agent behavior' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Provider and models' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Integrations and execution' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Installation controls' })).toBeInTheDocument();
        const instructions = screen.getByTestId('app-setting-agent.tenant_instructions-input');
        expect(instructions.tagName).toBe('TEXTAREA');
        expect(instructions).toHaveValue('Original operating brief');
        expect(instructions).toHaveAttribute('aria-describedby', 'app-setting-agent.tenant_instructions-help');
        expect(screen.queryByTestId('app-setting-ai_finops.enabled-input')).toBeNull();
        expect(screen.getByTestId('app-setting-ai_finops.enabled-deploy-only')).toBeInTheDocument();
        expect(screen.getByTestId('app-settings-save')).toBeDisabled();
    });

    it('shows loading, empty, and retryable failures', async () => {
        vi.mocked(adminAppSettingsApi.list).mockRejectedValue(new Error('offline'));
        mount();
        expect(screen.getByTestId('admin-app-settings-loading')).toBeInTheDocument();
        expect(await screen.findByTestId('admin-app-settings-error')).toBeInTheDocument();
        vi.mocked(adminAppSettingsApi.list).mockResolvedValue([]);
        fireEvent.click(screen.getByTestId('admin-app-settings-retry'));
        expect(await screen.findByTestId('admin-app-settings-empty')).toBeInTheDocument();
    });

    it('generates a proposal in a modal, fills the editor only on apply, and saves only on the final action', async () => {
        const user = userEvent.setup();
        mount();
        await screen.findByTestId('app-setting-agent.tenant_instructions-input');
        await user.click(screen.getByRole('button', { name: 'Write with the agent' }));
        const prompt = screen.getByRole('textbox', { name: 'What would you like to configure?' });
        expect(screen.getByRole('button', { name: 'Generate proposal' })).toBeDisabled();
        await user.type(prompt, 'Help operators handle orders and returns using authorized sources.');
        await user.click(screen.getByRole('button', { name: 'Generate proposal' }));
        expect(await screen.findByText('Proposal ready to review')).toBeInTheDocument();
        expect(adminAppSettingsApi.setMany).not.toHaveBeenCalled();
        await user.click(screen.getByRole('button', { name: 'Fill the form' }));
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(screen.getByTestId('app-setting-agent.tenant_instructions-input')).toHaveValue('Use authorized sources and reconcile later corrections.');
        expect(adminAppSettingsApi.setMany).not.toHaveBeenCalled();
        await user.click(screen.getByTestId('app-settings-save'));
        await waitFor(() => expect(adminAppSettingsApi.setMany).toHaveBeenCalledWith({ 'agent.tenant_instructions': 'Use authorized sources and reconcile later corrections.' }, '*', 'autry'));
        expect(await screen.findByText('Configuration saved.')).toBeInTheDocument();
    });

    it('filters models by the selected provider, shows prices, and saves provider and compatible model atomically', async () => {
        const user = userEvent.setup();
        mount();
        await screen.findByTestId('app-setting-ai.provider-input');
        await chooseSelectOption(screen.getByTestId('app-setting-ai.provider-input'), 'anthropic');
        await waitFor(() => expect(adminAppSettingsApi.models).toHaveBeenCalledWith('anthropic', 'autry'));
        await user.click(screen.getByTestId('app-setting-ai.chat_model-input'));
        expect(await screen.findByRole('option', { name: /Current Claude/ })).toHaveTextContent('$3.00');
        expect(screen.queryByRole('option', { name: /Current model/ })).toBeNull();
        await user.type(screen.getByRole('textbox', { name: 'Search models' }), 'claude-current');
        await user.keyboard('{Enter}');
        await user.click(screen.getByTestId('app-settings-save'));
        await waitFor(() => expect(adminAppSettingsApi.setMany).toHaveBeenCalledWith({
            'ai.provider': 'anthropic', 'ai.chat_model': 'claude-current', 'agent.synthesis.model': null, 'ai.chat_routing': 'default',
        }, '*', 'autry'));
    });

    it('keeps organization-only fields read-only at project scope and discards the previous scope draft', async () => {
        mount();
        await screen.findByTestId('app-setting-agent.tenant_instructions-input');
        fireEvent.change(screen.getByTestId('app-setting-agent.tenant_instructions-input'), { target: { value: 'Unsaved draft' } });
        await chooseSelectOption(screen.getByTestId('app-settings-scope'), 'sales');
        await waitFor(() => expect(adminAppSettingsApi.list).toHaveBeenCalledWith('sales', 'autry'));
        expect(await screen.findByTestId('app-setting-agent.tenant_instructions-tenant-only')).toBeInTheDocument();
        expect(screen.getByTestId('app-setting-agent.tenant_instructions-input')).toHaveAttribute('readonly');
        expect(screen.getByTestId('app-setting-agent.tenant_instructions-input')).toHaveValue('Original operating brief');
        expect(screen.queryByRole('button', { name: 'Write with the agent' })).toBeNull();
        expect(screen.queryByTestId('app-setting-ai.provider-input')).toBeNull();
    });

    it('preserves edits when saving fails and supports clearing only the current override', async () => {
        vi.mocked(adminAppSettingsApi.setMany).mockRejectedValue(new Error('Could not save'));
        mount();
        await screen.findByTestId('app-setting-agent.tenant_instructions-input');
        fireEvent.click(screen.getByTestId('app-setting-agent.tenant_instructions-clear'));
        fireEvent.click(screen.getByTestId('app-settings-save'));
        await waitFor(() => expect(adminAppSettingsApi.setMany).toHaveBeenCalledWith({ 'agent.tenant_instructions': null }, '*', 'autry'));
        expect(await screen.findByRole('alert')).toBeInTheDocument();
        expect(screen.getByTestId('app-setting-agent.tenant_instructions-input')).toHaveValue('');
    });

    it('reloads the roster for a different organization without showing the previous organization’s cached instructions', async () => {
        mount();
        await screen.findByDisplayValue('Original operating brief');
        await chooseSelectOption(screen.getByTestId('app-settings-scope'), 'sales');
        expect(await screen.findByTestId('app-setting-agent.tenant_instructions-tenant-only')).toBeInTheDocument();
        vi.mocked(adminAppSettingsApi.list).mockResolvedValue(settingsRoster().map((setting) => setting.key === 'agent.tenant_instructions' ? { ...setting, value: 'Other organization brief' } : setting));
        act(() => useTeamStore.setState({ currentTeam: 'other-team' }));
        expect(await screen.findByDisplayValue('Other organization brief')).toBeInTheDocument();
        expect(screen.queryByDisplayValue('Original operating brief')).toBeNull();
        expect(adminAppSettingsApi.list).toHaveBeenCalledWith('*', 'other-team');
        expect(screen.getByTestId('app-settings-scope')).toHaveAttribute('data-value', '*');
    });

    it('keeps a late catalog refresh attached to the provider it was requested for', async () => {
        let complete!: (catalog: AiModelCatalog) => void;
        const pending = new Promise<AiModelCatalog>((resolve) => { complete = resolve; });
        vi.mocked(adminAppSettingsApi.models).mockImplementation(async (provider, _tenant, refresh) => refresh ? pending : modelCatalog(provider));
        mount();
        await screen.findByTestId('app-setting-ai.provider-input');
        fireEvent.click(screen.getByRole('button', { name: 'Refresh catalog' }));
        await waitFor(() => expect(adminAppSettingsApi.models).toHaveBeenCalledWith('openrouter', 'autry', true));
        await chooseSelectOption(screen.getByTestId('app-setting-ai.provider-input'), 'anthropic');
        await waitFor(() => expect(screen.getByTestId('app-setting-ai.chat_model-input')).toHaveTextContent('Current Claude'));
        await act(async () => { complete({ ...modelCatalog(), models: [model('vendor/late-refresh')] }); await pending; });
        expect(screen.getByTestId('app-setting-ai.chat_model-input')).toHaveTextContent('Current Claude');
        expect(screen.queryByText('vendor/late-refresh')).toBeNull();
    });

    it('refreshes the selected provider catalog and reports stale data without losing the current model', async () => {
        vi.mocked(adminAppSettingsApi.models).mockResolvedValue({ ...modelCatalog(), stale: true, error: 'catalog_unavailable' });
        mount();
        expect(await screen.findByText(/Previous data may be outdated/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Refresh catalog' }));
        await waitFor(() => expect(adminAppSettingsApi.models).toHaveBeenCalledWith('openrouter', 'autry', true));
        expect(screen.getByTestId('app-setting-ai.chat_model-input')).toHaveTextContent('Current model');
    });

    it('never displays unknown prices as free and preserves EUR prices', () => {
        expect(modelPrice(null, 'USD', 'it')).toBe('n/d');
        expect(modelPrice(0, 'USD', 'en')).toBe('$0.00');
        expect(modelPrice(.4, 'EUR', 'it')).toContain('€');
    });
});
