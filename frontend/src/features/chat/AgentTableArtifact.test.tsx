import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { AgentTableArtifact } from './AgentTableArtifact';
import type { AgentTableArtifact as AgentTableArtifactData } from './chat.api';

const artifact: AgentTableArtifactData = {
    component_type: 'ui-data-table',
    interaction_mode: 'selection',
    source_execution_id: 44,
    tool: 'search-customers',
    title: 'Search customers',
    columns: [
        { key: 'id', label: 'Id' },
        { key: 'name', label: 'Name' },
        { key: 'email', label: 'Email' },
    ],
    rows: [
        { key: '101', label: 'Riccardo Lorini', values: { id: 101, name: 'Riccardo Lorini', email: 'one@example.test' } },
        { key: '102', label: 'Riccardo Lorini', values: { id: 102, name: 'Riccardo Lorini', email: 'two@example.test' } },
    ],
    total_rows: 2,
    truncated: false,
};

describe('AgentTableArtifact', () => {
    it('renders an automatic table and submits the selected server row key', async () => {
        const onSelect = vi.fn().mockResolvedValue(undefined);
        render(<AgentTableArtifact artifact={artifact} messageId={90} locale="it-IT" onSelect={onSelect} />);

        expect(screen.getByText('Scegli un risultato')).toBeInTheDocument();
        expect(screen.getAllByText('Riccardo Lorini')).toHaveLength(2);
        fireEvent.click(screen.getByText('two@example.test').closest('tr')!);

        await waitFor(() => expect(onSelect).toHaveBeenCalledOnce());
        const selection = onSelect.mock.calls[0]?.[0];
        expect(selection).toMatchObject({ messageId: 90, rowKey: '102', label: 'Riccardo Lorini' });
        expect(selection?.displayText).toBe('Ho selezionato “Riccardo Lorini”.');
        expect(selection?.displayText).not.toContain('{');
        expect(await screen.findByText('Scelta inviata alla chat.')).toBeInTheDocument();
        expect(screen.getByTestId('agent-table-select-102')).toHaveTextContent('Selezionata');

        fireEvent.click(screen.getByText('one@example.test').closest('tr')!);
        expect(onSelect).toHaveBeenCalledOnce();
    });

    it('lets the user select a row for inspection in view mode', () => {
        const onSelect = vi.fn().mockResolvedValue(undefined);
        render(
            <AgentTableArtifact
                artifact={{ ...artifact, interaction_mode: 'view' }}
                messageId={91}
                locale="it-IT"
                onSelect={onSelect}
            />,
        );

        expect(screen.queryByText('Scegli un risultato')).not.toBeInTheDocument();
        expect(screen.getByText('Apri una riga per i dettagli')).toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: 'Azione' })).toHaveClass('agent-table-artifact-action-heading');
        const actions = screen.getAllByRole('button', { name: /^Apri:/ });
        expect(actions).toHaveLength(2);
        expect(actions[0]).toHaveAttribute('data-variant', 'secondary');
        expect(actions[0]).toHaveAttribute('data-size', 'sm');
        expect(actions[0].closest('td')).toHaveClass('agent-table-artifact-action');
    });

    it('humanizes technical titles and formats ISO timestamps for readability', () => {
        render(
            <AgentTableArtifact
                artifact={{
                    ...artifact,
                    title: 'orders_get',
                    columns: [{ key: 'occurred_at', label: 'Occurred at' }],
                    rows: [{ key: 'order-1', label: 'Order 1', values: { occurred_at: '2026-08-20T09:00:00+00:00' } }],
                    total_rows: 1,
                    interaction_mode: 'view',
                }}
                messageId={92}
                locale="it-IT"
            />,
        );

        expect(screen.getByText('Orders get')).toBeInTheDocument();
        expect(screen.getByText('1 risultato')).toBeInTheDocument();
        expect(screen.queryByText('2026-08-20T09:00:00+00:00')).not.toBeInTheDocument();
    });
});
