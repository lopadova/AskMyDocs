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

        expect(screen.getByText('Scegli una riga per continuare')).toBeInTheDocument();
        expect(screen.getAllByText('Riccardo Lorini')).toHaveLength(2);
        fireEvent.click(screen.getByTestId('agent-table-select-102'));

        await waitFor(() => expect(onSelect).toHaveBeenCalledOnce());
        const selection = onSelect.mock.calls[0]?.[0];
        expect(selection).toMatchObject({ messageId: 90, rowKey: '102', label: 'Riccardo Lorini' });
        expect(selection?.content).toContain('Ho selezionato questa riga (Riccardo Lorini)');
        expect(selection?.content).toContain('"id": 102');
        expect(selection?.content).toContain('"email": "two@example.test"');
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

        expect(screen.queryByText('Scegli una riga per continuare')).not.toBeInTheDocument();
        expect(screen.getByText('Seleziona una riga per approfondire')).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: 'Scegli' })).toHaveLength(2);
    });
});
