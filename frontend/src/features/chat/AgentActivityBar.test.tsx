import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { AgentRunEvent } from '../../lib/agent-run-events';
import { AgentActivityBar } from './AgentActivityBar';

const progressEvent: AgentRunEvent = {
    run_id: 'run-1',
    sequence: 2,
    type: 'tool.progress',
    phase: 'tool',
    locale: 'it-IT',
    message_key: 'tool.progress',
    message_params: { completed: 3, estimated: 10 },
    message: 'Completate 3 richieste API su circa 10.',
    progress: {
        logical: { completed: 1, estimated: { min: 1, likely: 2, max: 4 } },
        physical: { completed: 3, estimated: { min: 5, likely: 10, max: 20 } },
        eta_ms: 4200,
    },
    can_cancel: true,
    data: { tool: 'list_orders' },
    created_at: null,
};

describe('AgentActivityBar', () => {
    it('renders localized live progress and cancellation', () => {
        const cancel = vi.fn();
        render(<AgentActivityBar events={[progressEvent]} active awaitingConfirmation={false} onCancel={cancel} onContinue={() => undefined} />);

        expect(screen.getByTestId('agent-activity-heading')).toHaveTextContent('Ricerca in corso');
        expect(screen.getByTestId('agent-activity-message')).toHaveTextContent('Completate 3 richieste API');
        expect(screen.getByTestId('agent-activity-calls')).toHaveTextContent('3 / ~10 chiamate');
        expect(screen.getByTestId('agent-activity-progress')).toHaveStyle({ width: '30%' });
        fireEvent.click(screen.getByTestId('agent-activity-cancel'));
        expect(cancel).toHaveBeenCalledOnce();
    });

    it('offers continuation when the backend requests confirmation', () => {
        const proceed = vi.fn();
        render(<AgentActivityBar events={[{ ...progressEvent, type: 'run.awaiting_confirmation' }]} active={false} awaitingConfirmation onCancel={() => undefined} onContinue={proceed} />);

        fireEvent.click(screen.getByTestId('agent-activity-continue'));
        expect(proceed).toHaveBeenCalledOnce();
        expect(screen.getByTestId('agent-activity-bar')).toHaveAttribute('data-state', 'confirmation');
        expect(screen.getByTestId('agent-activity-heading')).toHaveTextContent('Conferma necessaria');
    });

    it('keeps a settled summary compact and exposes the chronological timeline on demand', () => {
        const completedEvent: AgentRunEvent = {
            ...progressEvent,
            sequence: 3,
            type: 'run.completed',
            message: 'La risposta è pronta.',
            progress: {
                ...progressEvent.progress!,
                physical: { completed: 10, estimated: { min: 10, likely: 10, max: 10 } },
            },
        };

        render(<AgentActivityBar events={[progressEvent, completedEvent]} active={false} awaitingConfirmation={false} onCancel={() => undefined} onContinue={() => undefined} />);

        expect(screen.getByTestId('agent-activity-heading')).toHaveTextContent('Attività completata');
        expect(screen.getByText('Cronologia attività')).toBeInTheDocument();
        expect(screen.getByText('2')).toBeInTheDocument();
        expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '100');
    });
});
