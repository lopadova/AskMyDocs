import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, expect, it, vi } from 'vitest';
import { EmailTriagePanel } from './EmailTriagePanel';
import { emailTriageApi, type TriageStatus } from './email-triage.api';

vi.mock('./email-triage.api', () => ({ emailTriageApi: { status: vi.fn(), save: vi.fn(), preview: vi.fn(), campaignPreview: vi.fn(), start: vi.fn(), control: vi.fn(), retry: vi.fn() } }));
const status: TriageStatus = { profile: null, versions: [], readiness: { credentials: true, worker: true, asynchronous_queue: true }, counts: {}, campaign: null, sample_documents: [{ id: 42, title: 'Delayed parcel' }], messages: [] };
function mount() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(<QueryClientProvider client={client}><EmailTriagePanel installationId={10} /></QueryClientProvider>);
}
beforeEach(() => { vi.resetAllMocks(); vi.mocked(emailTriageApi.status).mockResolvedValue(status); });

it('saves a configured characteristic using the shared busy button and keyboard', async () => {
    let finish!: (value: Awaited<ReturnType<typeof emailTriageApi.save>>) => void;
    vi.mocked(emailTriageApi.save).mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
    mount();
    await userEvent.click(await screen.findByRole('button', { name: 'Aggiungi esempio lamentela' }));
    const save = screen.getByRole('button', { name: 'Salva regole' });
    save.focus();
    await userEvent.keyboard('{Enter}');
    await waitFor(() => expect(save).toHaveAttribute('aria-busy', 'true'));
    expect(save).toBeDisabled();
    const config = vi.mocked(emailTriageApi.save).mock.calls[0]![1];
    expect(config.rules[0]?.options[0]?.tag).toBe('lamentela');
    expect(config.rules[0]?.threshold).toBe(0.85);
    finish({ ...config, id: 1, revision: 1, model: 'typesafe/jev-1.13' });
    await screen.findByText(/Regole v1 salvate/);
    expect(emailTriageApi.save).toHaveBeenCalledTimes(1);
});

it('previews one email with the draft without starting a campaign or ingest', async () => {
    vi.mocked(emailTriageApi.preview).mockResolvedValue({ card: 'Scheda di triage automatica: lamentela', coverage: { partial: true, body_truncated: false, attachments: [] }, tags: ['lamentela'], usage: { cost: 0.00001 }, latency_ms: 50, result: { fields: [] } });
    mount();
    await userEvent.click(await screen.findByRole('button', { name: 'Aggiungi esempio lamentela' }));
    await userEvent.selectOptions(screen.getByLabelText('Email'), '42');
    await userEvent.click(screen.getByRole('button', { name: 'Analizza senza aggiornare l’ingest' }));
    await screen.findByText('Scheda di triage automatica: lamentela');
    expect(emailTriageApi.preview).toHaveBeenCalledWith(10, 42, expect.objectContaining({ rules: expect.any(Array) }));
    expect(emailTriageApi.save).not.toHaveBeenCalled();
    expect(emailTriageApi.start).not.toHaveBeenCalled();
});

it('disables activation without a worker and renders validation failures', async () => {
    vi.mocked(emailTriageApi.status).mockResolvedValue({ ...status, readiness: { ...status.readiness, worker: false } });
    vi.mocked(emailTriageApi.save).mockRejectedValue({ response: { status: 422, data: { message: 'Invalid rule.', errors: { 'rules.0.key': ['Duplicate key.'] } } } });
    mount();
    expect(await screen.findByRole('checkbox', { name: 'Attiva triage sulle nuove email' })).toBeDisabled();
    await userEvent.click(screen.getByRole('button', { name: 'Aggiungi esempio lamentela' }));
    await userEvent.click(screen.getByRole('button', { name: 'Salva regole' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Duplicate key.');
});

it('only permits a campaign after an explicit preview and invalidates it when filters change', async () => {
    vi.mocked(emailTriageApi.status).mockResolvedValue({ ...status, profile: { id: 2, revision: 2, model: 'typesafe/jev-1.13', enabled: true, rules: [] } });
    vi.mocked(emailTriageApi.campaignPreview).mockResolvedValue({ total: 1000, profile_id: 2, availability_sample: { checked: 100, available: 90, unavailable: 10, unchecked: 900 }, estimated_decision_usd: null, estimated_reindex_usd: null });
    mount();
    expect(screen.queryByRole('button', { name: 'Avvia triage e aggiorna ingest' })).not.toBeInTheDocument();
    await userEvent.click(await screen.findByRole('button', { name: 'Calcola anteprima' }));
    await screen.findByRole('button', { name: 'Avvia triage e aggiorna ingest' });
    expect(screen.getByText(/ancora da verificare: 900/)).toBeInTheDocument();
    await userEvent.type(screen.getByLabelText('Cartella'), 'INBOX');
    expect(screen.queryByRole('button', { name: 'Avvia triage e aggiorna ingest' })).not.toBeInTheDocument();
    expect(emailTriageApi.start).not.toHaveBeenCalled();
});
