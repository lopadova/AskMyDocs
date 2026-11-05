import { useState } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, it } from 'vitest';
import { DefinitionEditor } from './DefinitionEditor';
import { BindingEditor } from './BindingEditor';
import { blankDefinition, newStep } from './model';
import type { Binding, Endpoint, ToolDefinition } from './types';
const endpoints: Endpoint[] = [{ id: 10, name: 'Orders', connector_id: 1, connector_name: 'CRM', method: 'GET', input_schema: { type: 'object', properties: { customer_id: { type: 'integer' }, page: { type: 'integer' } }, required: ['customer_id'] }, output_schema: { type: 'object', properties: { orders: { type: 'array', items: { type: 'object', properties: { id: { type: 'integer' } } } } } }, example: { orders: [{ id: 42 }] } }];
function Editor() { const first = { ...newStep([]), route_id: 10 }; const second = newStep([first]); const [definition, setDefinition] = useState<ToolDefinition>({ ...blankDefinition(), steps: [first, second] }); return <DefinitionEditor definition={definition} endpoints={endpoints} onChange={setDefinition} />; }
it('expands only one call and keeps optional parameters collapsed', async () => {
    const user = userEvent.setup(); render(<Editor />);
    expect(screen.getByText('customer_id', { selector: 'code' })).toBeVisible();
    const optional = screen.getByText('Parametri opzionali (1)').closest('details')!;
    expect(optional).not.toHaveAttribute('open');
    expect(within(optional).getByText('page', { selector: 'code' })).not.toBeVisible();
    await user.click(screen.getByRole('button', { name: 'Modifica Chiamata 2' }));
    expect(screen.queryByText('customer_id', { selector: 'code' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Endpoint Chiamata 2' })).toBeVisible();
    expect(screen.getByRole('button', { name: 'Modifica Chiamata 1' })).toHaveAttribute('aria-expanded', 'false');
});
it('searches nested outputs and maps arrays as projections, then requires an explicit index', async () => {
    const user = userEvent.setup();
    function Harness() { const [binding, setBinding] = useState<Binding>(); return <><BindingEditor label="Ordini" binding={binding} onChange={setBinding} inputSchema={{ type: 'object', properties: {} }} steps={[{ ...newStep([]), route_id: 10 }]} endpoints={endpoints} /><output data-testid="binding">{JSON.stringify(binding)}</output></>; }
    render(<Harness />);
    await user.click(screen.getByRole('button', { name: 'Sorgente Ordini' }));
    await user.type(screen.getByRole('combobox'), 'orders[[].id');
    expect(screen.getByRole('option')).toHaveTextContent('integer · es. 42');
    await user.keyboard('{Enter}');
    expect(JSON.parse(screen.getByTestId('binding').textContent!)).toEqual({ source: 'step', step: 'step_1', path: 'orders', mode: 'project', project_path: 'id' });
    await user.click(screen.getByText('Dettagli mapping'));
    await user.selectOptions(screen.getByLabelText('Uso del valore'), 'index');
    expect(screen.getByLabelText('Indice (da 0)')).toHaveValue(null);
    expect(JSON.parse(screen.getByTestId('binding').textContent!)).not.toHaveProperty('index');
    await user.type(screen.getByLabelText('Indice (da 0)'), '2');
    expect(JSON.parse(screen.getByTestId('binding').textContent!)).toMatchObject({ mode: 'index', index: 2 });
});

it('offers only explicit indexed selections when mapping a list field into a scalar endpoint input', async () => {
    const user = userEvent.setup();
    function Harness() { const [binding, setBinding] = useState<Binding>(); return <BindingEditor label="Customer ID" targetSchema={{ type: 'integer' }} binding={binding} onChange={setBinding} inputSchema={{ type: 'object', properties: {} }} steps={[{ ...newStep([]), route_id: 10 }]} endpoints={endpoints} />; }
    render(<Harness />);
    await user.click(screen.getByRole('button', { name: 'Sorgente Customer ID' }));
    expect(screen.queryByRole('option', { name: /risultato completo/ })).not.toBeInTheDocument();
    await user.click(screen.getByRole('option', { name: /orders\[\].id · indice richiesto/ }));
    expect(screen.getByLabelText('Indice (da 0)')).toHaveValue(null);
    await user.click(screen.getByText('Dettagli mapping'));
    expect(within(screen.getByLabelText('Uso del valore')).getAllByRole('option').map((option) => option.getAttribute('value'))).toEqual(['index']);
});
it('shows effective inputs, origins and results only in the opened call and separates tool output', async () => {
    const user = userEvent.setup();
    const first = { ...newStep([]), route_id: 10 };
    const second = { ...newStep([first]), bindings: { customer_id: { source: 'step' as const, step: first.id, path: 'orders' } } };
    const definition = { ...blankDefinition(), steps: [first, second] };
    render(<DefinitionEditor definition={definition} endpoints={endpoints} onChange={() => {}} latestSteps={[
        { step_id: first.id, status: 'completed', diagnostics: { inputs: { customer_id: 42 }, origins: { customer_id: { source: 'input', path: 'customer_id' } }, output: { summary: 'Risultato prima chiamata' } } },
        { step_id: second.id, status: 'failed', diagnostics: { error: 'api_tool_second_failed' } },
        { step_id: 'tool_output', status: 'completed', diagnostics: { output: { summary: 'Risultato finale distinto' } } },
    ]} />);
    const firstDiagnostic = screen.getByRole('region', { name: 'Ultima esecuzione Chiamata 1' });
    expect(within(firstDiagnostic).getByText('42')).toBeVisible();
    expect(within(firstDiagnostic).getByText('Input.customer_id')).toBeVisible();
    expect(within(firstDiagnostic).getByText('Risultato prima chiamata')).toBeVisible();
    expect(firstDiagnostic.querySelector('details')).not.toHaveAttribute('open');
    expect(screen.queryByRole('region', { name: 'Ultima esecuzione Chiamata 2' })).not.toBeInTheDocument();
    expect(screen.queryByText('api_tool_second_failed')).not.toBeInTheDocument();
    expect(within(screen.getByRole('button', { name: 'Modifica Chiamata 2' })).getByText('Dipende da Chiamata 1 · failed')).toBeVisible();
    expect(within(screen.getByRole('region', { name: 'Ultima esecuzione Output del tool' })).getByText('Risultato finale distinto')).toBeVisible();
    await user.click(screen.getByRole('button', { name: 'Modifica Chiamata 2' }));
    expect(screen.queryByRole('region', { name: 'Ultima esecuzione Chiamata 1' })).not.toBeInTheDocument();
    expect(within(screen.getByRole('region', { name: 'Ultima esecuzione Chiamata 2' })).getByRole('alert')).toHaveTextContent('api_tool_second_failed');
});
