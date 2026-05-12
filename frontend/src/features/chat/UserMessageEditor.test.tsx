import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { UserMessageEditor } from './UserMessageEditor';

describe('UserMessageEditor', () => {
    it('renders the textarea pre-populated with the initial value', () => {
        render(
            <UserMessageEditor
                messageId="42"
                initialValue="How does PTO work?"
                onSubmit={() => undefined}
                onCancel={() => undefined}
            />,
        );
        const ta = screen.getByTestId('chat-message-42-editor-textarea');
        expect(ta).toHaveValue('How does PTO work?');
    });

    it('calls onSubmit with the trimmed new content when Save is clicked', async () => {
        const onSubmit = vi.fn();
        render(
            <UserMessageEditor
                messageId="42"
                initialValue="old"
                onSubmit={onSubmit}
                onCancel={() => undefined}
            />,
        );
        const ta = screen.getByTestId('chat-message-42-editor-textarea');
        fireEvent.change(ta, { target: { value: '  new content  ' } });
        fireEvent.click(screen.getByTestId('chat-message-42-editor-save'));
        await waitFor(() => {
            expect(onSubmit).toHaveBeenCalledWith('new content');
        });
    });

    it('disables the Save button when the textarea is empty / whitespace-only', () => {
        const onSubmit = vi.fn();
        render(
            <UserMessageEditor
                messageId="42"
                initialValue="hi"
                onSubmit={onSubmit}
                onCancel={() => undefined}
            />,
        );
        const ta = screen.getByTestId('chat-message-42-editor-textarea');
        fireEvent.change(ta, { target: { value: '   ' } });
        const save = screen.getByTestId('chat-message-42-editor-save') as HTMLButtonElement;
        // The Save button is disabled when the trimmed value is empty —
        // a click is a no-op AND the disabled flag is the screen-reader
        // signal that the action is unavailable (R15).
        expect(save).toBeDisabled();
        fireEvent.click(save);
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('does nothing (cancels) when Save is clicked but the content is unchanged', () => {
        const onSubmit = vi.fn();
        const onCancel = vi.fn();
        render(
            <UserMessageEditor
                messageId="42"
                initialValue="same"
                onSubmit={onSubmit}
                onCancel={onCancel}
            />,
        );
        fireEvent.click(screen.getByTestId('chat-message-42-editor-save'));
        expect(onSubmit).not.toHaveBeenCalled();
        expect(onCancel).toHaveBeenCalled();
    });

    it('fires onCancel when Cancel button clicked', () => {
        const onCancel = vi.fn();
        render(
            <UserMessageEditor
                messageId="42"
                initialValue="hi"
                onSubmit={() => undefined}
                onCancel={onCancel}
            />,
        );
        fireEvent.click(screen.getByTestId('chat-message-42-editor-cancel'));
        expect(onCancel).toHaveBeenCalled();
    });

    it('fires onCancel when Escape is pressed', () => {
        const onCancel = vi.fn();
        render(
            <UserMessageEditor
                messageId="42"
                initialValue="hi"
                onSubmit={() => undefined}
                onCancel={onCancel}
            />,
        );
        const ta = screen.getByTestId('chat-message-42-editor-textarea');
        fireEvent.keyDown(ta, { key: 'Escape' });
        expect(onCancel).toHaveBeenCalled();
    });

    it('saves when Ctrl+Enter is pressed', async () => {
        const onSubmit = vi.fn();
        render(
            <UserMessageEditor
                messageId="42"
                initialValue="old"
                onSubmit={onSubmit}
                onCancel={() => undefined}
            />,
        );
        const ta = screen.getByTestId('chat-message-42-editor-textarea');
        fireEvent.change(ta, { target: { value: 'new content' } });
        fireEvent.keyDown(ta, { key: 'Enter', ctrlKey: true });
        await waitFor(() => {
            expect(onSubmit).toHaveBeenCalledWith('new content');
        });
    });
});
