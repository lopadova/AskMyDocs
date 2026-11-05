import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, expect, it, vi } from 'vitest';
import { ApiToolsView } from './ApiToolsView';
import { apiToolsApi } from './api-tools.api';
import { adminProjectsApi } from '../projects/admin-projects.api';
import { blankDefinition } from './model';
import type { ApiTool } from './types';
vi.mock('./api-tools.api', () => ({ apiToolsApi: { list: vi.fn(), show: vi.fn(), save: vi.fn(), action: vi.fn(), generate: vi.fn(), export: vi.fn(), import: vi.fn(), cancel: vi.fn() } }));
vi.mock('../projects/admin-projects.api', () => ({ adminProjectsApi: { list: vi.fn() } }));
const tool: ApiTool = { id: 3, name: 'Ordini cliente', description: 'Recupera ordini', project_key: 'sales', failure_threshold: 3, status: 'draft', definition: blankDefinition() };
beforeEach(() => { vi.clearAllMocks(); vi.mocked(apiToolsApi.list).mockResolvedValue({ enabled: true, tools: [tool], endpoints: [] }); vi.mocked(adminProjectsApi.list).mockResolvedValue([{ id: 1, project_key: 'sales', name: 'Sales', description: null, document_count: 0, member_count: 0 }]); vi.mocked(apiToolsApi.show).mockResolvedValue({ tool, endpoints: [], runs: [], revisions: [], verifications: [] }); });
it('requires saving changed definitions before validation, testing or publishing', async () => {
    const user = userEvent.setup(); render(<ApiToolsView />);
    await user.click(await screen.findByRole('button', { name: /Ordini cliente/ }));
    expect(await screen.findByRole('button', { name: 'Valida' })).toBeEnabled();
    await user.type(screen.getByLabelText('Nome tool'), ' aggiornato');
    for (const name of ['Valida', 'Prova simulata', 'Pubblica']) expect(screen.getByRole('button', { name })).toBeDisabled();
    vi.mocked(apiToolsApi.save).mockResolvedValue({ ...tool, name: 'Ordini cliente aggiornato' });
    await user.click(screen.getByRole('button', { name: 'Salva bozza' }));
    expect(apiToolsApi.save).toHaveBeenCalledWith(3, expect.objectContaining({ name: 'Ordini cliente aggiornato' }));
    expect(await screen.findByRole('button', { name: 'Valida' })).toBeEnabled();
});
it('retains unsaved work when a save fails', async () => {
    const user = userEvent.setup(); vi.mocked(apiToolsApi.save).mockRejectedValue(new Error('offline')); render(<ApiToolsView />);
    await user.click(await screen.findByRole('button', { name: /Ordini cliente/ })); await screen.findByLabelText('Nome tool');
    await user.type(screen.getByLabelText('Nome tool'), ' nuovo');
    await user.click(screen.getByRole('button', { name: 'Salva bozza' }));
    expect(await screen.findByRole('alert')).toBeVisible(); expect(screen.getByLabelText('Nome tool')).toHaveValue('Ordini cliente nuovo'); expect(screen.getByRole('button', { name: 'Salva bozza' })).toBeEnabled();
});
it('shows per-step simulation diagnostics without requiring live confirmation', async () => {
    const user = userEvent.setup(); vi.mocked(apiToolsApi.action).mockResolvedValue({ runs: [{ id: 10, status: 'failed', steps: [{ step_id: 'orders', status: 'failed', error: 'Fixture mancante' }] }] }); render(<ApiToolsView />);
    await user.click(await screen.findByRole('button', { name: /Ordini cliente/ })); await screen.findByLabelText('Nome tool');
    expect(screen.getByRole('button', { name: 'Prova live' })).toBeDisabled();
    await user.click(screen.getByRole('button', { name: 'Prova simulata' }));
    expect(apiToolsApi.action).toHaveBeenCalledWith(3, 'test', { mode: 'simulation' });
    expect(await screen.findByText('orders · failed')).toBeInTheDocument();
});
it('blocks saving malformed advanced JSON rather than submitting the previous value', async () => {
    const user = userEvent.setup(); render(<ApiToolsView />);
    await user.click(await screen.findByRole('button', { name: /Ordini cliente/ })); await screen.findByLabelText('Nome tool');
    await user.type(screen.getByLabelText('Nome tool'), ' modificato');
    await user.click(screen.getByText('Schema input annidato avanzato'));
    await user.clear(screen.getByLabelText('Schema input (JSON Schema)'));
    expect(await screen.findByRole('alert')).toHaveTextContent('JSON non valido');
    await user.click(screen.getByRole('button', { name: 'Salva bozza' }));
    expect(apiToolsApi.save).not.toHaveBeenCalled();
});

it('refreshes pending verification results without overwriting unsaved editor changes', async () => {
    vi.useFakeTimers();
    const active = { tool, endpoints: [], runs: [], revisions: [], verifications: [{ id: 5, status: 'running' }] };
    vi.mocked(apiToolsApi.show).mockResolvedValueOnce({ ...active, verifications: [] }).mockResolvedValueOnce(active).mockResolvedValue({ ...active, tool: { ...tool, name: 'Nome remoto' }, verifications: [{ id: 5, status: 'passed' }] });
    vi.mocked(apiToolsApi.action).mockResolvedValue({ verifications: active.verifications });
    let view!: ReturnType<typeof render>;
    try {
        await act(async () => { view = render(<ApiToolsView />); });
        await act(async () => { fireEvent.click(screen.getByRole('button', { name: /Ordini cliente/ })); });
        fireEvent.click(screen.getByRole('checkbox', { name: /La prova live effettua richieste reali/ }));
        await act(async () => { fireEvent.click(screen.getByRole('button', { name: 'Prova live' })); });
        expect(apiToolsApi.action).toHaveBeenCalledWith(3, 'test', { mode: 'live' });
        expect(screen.getByText(/Prova live avviata/)).toBeVisible();
        fireEvent.change(screen.getByLabelText('Nome tool'), { target: { value: 'Ordini cliente locale' } });
        expect(apiToolsApi.show).toHaveBeenCalledTimes(2);
        await act(() => vi.advanceTimersByTimeAsync(2000));
        expect(apiToolsApi.show).toHaveBeenCalledTimes(3);
        expect(screen.getByLabelText('Nome tool')).toHaveValue('Ordini cliente locale');
        expect(screen.getByText(/Aggiornamento completato alle/)).toBeVisible();
        await act(() => vi.advanceTimersByTimeAsync(60000)); expect(apiToolsApi.show).toHaveBeenCalledTimes(3);
    } finally { view?.unmount(); vi.useRealTimers(); }
});
