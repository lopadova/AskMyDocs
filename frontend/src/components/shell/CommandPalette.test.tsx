import { describe, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { CommandPalette } from './CommandPalette';

describe('CommandPalette', () => {
    it('opens on Ctrl+K and closes on Escape', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        await user.keyboard('{Control>}k{/Control}');
        expect(await screen.findByRole('dialog', { name: /command palette/i })).toBeInTheDocument();
        await user.keyboard('{Escape}');
        // Closing is a state update that resolves on a later tick
        // (Copilot PR #33 flakiness fix). `waitForElementToBeRemoved`
        // is the wrong primitive here because the dialog is sometimes
        // already gone by the time we check; `waitFor` against
        // queryByRole === null is the right async-poll primitive.
        await waitFor(() => {
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        });
    });

    it('filters items by query', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);
        window.dispatchEvent(new CustomEvent('amd:palette'));
        const input = await screen.findByLabelText('Search');
        await user.type(input, 'insights');
        expect(screen.getByText(/AI Insights \(5 new\)/i)).toBeInTheDocument();
        expect(screen.queryByText(/New chat/i)).not.toBeInTheDocument();
    });
});
