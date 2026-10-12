import { describe, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { CommandPalette } from './CommandPalette';

describe('CommandPalette', () => {
    it('opens on Ctrl+K and preserves the query until explicitly closed', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        await user.keyboard('{Control>}k{/Control}');
        const dialog = await screen.findByRole('dialog', { name: /command palette/i });
        await user.type(screen.getByLabelText('Search'), 'insights');
        await user.click(dialog);
        await user.keyboard('{Escape}');
        await user.keyboard('{Control>}k{/Control}');
        expect(dialog).toBeInTheDocument();
        expect(screen.getByLabelText('Search')).toHaveValue('insights');
        await user.click(screen.getByRole('button', { name: 'Close command palette' }));
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
