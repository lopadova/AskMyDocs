import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { NameDialog } from './NameDialog';

function renderDialog(onSubmit = vi.fn().mockResolvedValue(undefined), initialValue = '') {
    const onClose = vi.fn();
    render(
        <NameDialog
            testId="name-dialog"
            title="New folder"
            description="Group related sessions."
            submitLabel="Create"
            initialValue={initialValue}
            onSubmit={onSubmit}
            onClose={onClose}
        />,
    );

    return { onSubmit, onClose };
}

describe('NameDialog', () => {
    it('submits the trimmed name and closes', async () => {
        const { onSubmit, onClose } = renderDialog();

        await userEvent.type(screen.getByTestId('name-dialog-input'), '  Issue 42  ');
        await userEvent.click(screen.getByTestId('name-dialog-submit'));

        expect(onSubmit).toHaveBeenCalledWith('Issue 42');
        expect(onClose).toHaveBeenCalled();
    });

    it('submits on Enter without needing the button', async () => {
        const { onSubmit } = renderDialog();

        await userEvent.type(screen.getByTestId('name-dialog-input'), 'Issue 42{Enter}');

        expect(onSubmit).toHaveBeenCalledWith('Issue 42');
    });

    it('refuses an empty name locally instead of round-tripping', async () => {
        const { onSubmit } = renderDialog();

        await userEvent.click(screen.getByTestId('name-dialog-submit'));

        expect(onSubmit).not.toHaveBeenCalled();
        expect(screen.getByTestId('name-dialog-error')).toHaveTextContent('Enter a name.');
    });

    it('renders the server field error rather than swallowing the rejection', async () => {
        // The BE answers 422 with a field error for a duplicate folder
        // name (R14). Without this the user retypes the same name forever.
        const onSubmit = vi.fn().mockRejectedValue({
            response: { data: { errors: { name: ['You already have a folder with this name.'] } } },
        });
        const { onClose } = renderDialog(onSubmit);

        await userEvent.type(screen.getByTestId('name-dialog-input'), 'Issue 42');
        await userEvent.click(screen.getByTestId('name-dialog-submit'));

        expect(await screen.findByTestId('name-dialog-error')).toHaveTextContent(
            'You already have a folder with this name.',
        );
        // A failed save must NOT close the dialog and lose the input.
        expect(onClose).not.toHaveBeenCalled();
        expect(screen.getByTestId('name-dialog-input')).toHaveValue('Issue 42');
    });

    it('falls back to the response message when there is no field error', async () => {
        const onSubmit = vi.fn().mockRejectedValue({
            response: { data: { message: 'Folder not found.' } },
        });
        renderDialog(onSubmit);

        await userEvent.type(screen.getByTestId('name-dialog-input'), 'Whatever');
        await userEvent.click(screen.getByTestId('name-dialog-submit'));

        expect(await screen.findByTestId('name-dialog-error')).toHaveTextContent('Folder not found.');
    });

    it('falls back to the Error message for a transport failure', async () => {
        const onSubmit = vi.fn().mockRejectedValue(new Error('Network Error'));
        renderDialog(onSubmit);

        await userEvent.type(screen.getByTestId('name-dialog-input'), 'Whatever');
        await userEvent.click(screen.getByTestId('name-dialog-submit'));

        expect(await screen.findByTestId('name-dialog-error')).toHaveTextContent('Network Error');
    });

    it('clears a stale error as soon as the user edits the name', async () => {
        renderDialog();
        await userEvent.click(screen.getByTestId('name-dialog-submit'));
        expect(screen.getByTestId('name-dialog-error')).toBeInTheDocument();

        await userEvent.type(screen.getByTestId('name-dialog-input'), 'x');

        expect(screen.queryByTestId('name-dialog-error')).not.toBeInTheDocument();
    });

    it('prefills the current name when renaming', () => {
        renderDialog(vi.fn(), 'Existing name');

        expect(screen.getByTestId('name-dialog-input')).toHaveValue('Existing name');
    });

    it('binds a real label to the input', () => {
        renderDialog();

        // R15: a programmatic label, not a placeholder.
        expect(screen.getByLabelText('Name')).toBe(screen.getByTestId('name-dialog-input'));
    });

    it('cancels without submitting', async () => {
        const { onSubmit, onClose } = renderDialog();

        await userEvent.click(screen.getByTestId('name-dialog-cancel'));

        expect(onSubmit).not.toHaveBeenCalled();
        expect(onClose).toHaveBeenCalled();
    });
});
