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

        await waitFor(() => expect(onSelect).toHaveBeenCalledWith({
            messageId: 90,
            rowKey: '102',
            label: 'Riccardo Lorini',
            content: 'Ho scelto: Riccardo Lorini. Continua usando questa selezione.',
        }));
    });

    it('renders list results without selection controls in view mode', () => {
        render(<AgentTableArtifact artifact={{ ...artifact, interaction_mode: 'view' }} messageId={91} locale="it-IT" />);

        expect(screen.queryByText('Scegli una riga per continuare')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Scegli' })).not.toBeInTheDocument();
    });
});
