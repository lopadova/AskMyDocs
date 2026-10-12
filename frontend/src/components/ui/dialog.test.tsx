import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from './dialog';

function EditableDialog() {
    return (
        <Dialog>
            <DialogTrigger>Apri impostazioni</DialogTrigger>
            <DialogContent>
                <DialogTitle>Impostazioni</DialogTitle>
                <DialogDescription>Modifica e chiudi esplicitamente.</DialogDescription>
                <label>
                    Nome
                    <input />
                </label>
                <DialogClose>Chiudi</DialogClose>
            </DialogContent>
        </Dialog>
    );
}

describe('Dialog dismissal', () => {
    it('preserves the form on backdrop click and Escape, then closes with Chiudi', async () => {
        const user = userEvent.setup();
        render(<EditableDialog />);
        const trigger = screen.getByRole('button', { name: 'Apri impostazioni' });
        await user.click(trigger);
        const input = screen.getByLabelText('Nome');
        await user.type(input, 'Bozza da conservare');

        const backdrop = document.querySelector<HTMLElement>('[data-slot="dialog-overlay"]')!;
        await user.click(backdrop);
        await user.keyboard('{Escape}');

        expect(screen.getByRole('dialog')).toBeVisible();
        expect(input).toHaveValue('Bozza da conservare');
        await user.click(screen.getByRole('button', { name: 'Chiudi' }));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(trigger).toHaveFocus();
    });

    it('closes with the X and restores focus to the trigger', async () => {
        const user = userEvent.setup();
        render(<EditableDialog />);
        const trigger = screen.getByRole('button', { name: 'Apri impostazioni' });
        await user.click(trigger);
        await user.click(screen.getByRole('button', { name: 'Close' }));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(trigger).toHaveFocus();
    });
});
