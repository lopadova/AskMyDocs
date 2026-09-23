import { afterAll, afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useAuthStore } from '../../lib/auth-store';
import { chatApi } from './chat.api';
import { ConversationDebugDownloadButton } from './ConversationDebugDownloadButton';

describe('ConversationDebugDownloadButton', () => {
    const download = vi.spyOn(chatApi, 'downloadDebugTranscript');
    const createObjectUrl = vi.fn(() => 'blob:debug-transcript');
    const revokeObjectUrl = vi.fn();
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);

    beforeEach(() => {
        useAuthStore.setState({ roles: [], loading: false });
        download.mockReset();
        createObjectUrl.mockClear();
        revokeObjectUrl.mockClear();
        click.mockClear();
        vi.stubGlobal('URL', {
            createObjectURL: createObjectUrl,
            revokeObjectURL: revokeObjectUrl,
        });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    afterAll(() => {
        vi.restoreAllMocks();
    });

    it('does not expose the forensic export control to a non-super-admin', () => {
        render(<ConversationDebugDownloadButton conversationId={42} />);

        expect(screen.queryByTestId('chat-debug-export')).not.toBeInTheDocument();
    });

    it('downloads the JSON attachment for a super-admin', async () => {
        const user = userEvent.setup();
        useAuthStore.setState({ roles: ['super-admin'] });
        download.mockResolvedValue({
            blob: new Blob(['{"schema_version":1}'], { type: 'application/json' }),
            filename: 'chat-debug-42.json',
        });

        render(<ConversationDebugDownloadButton conversationId={42} />);
        await user.click(screen.getByTestId('chat-debug-export'));

        await waitFor(() => {
            expect(download).toHaveBeenCalledWith(42);
        });
        expect(createObjectUrl).toHaveBeenCalledTimes(1);
        expect(click).toHaveBeenCalledTimes(1);
    });

    it('shows an actionable failure message when the export request fails', async () => {
        const user = userEvent.setup();
        useAuthStore.setState({ roles: ['super-admin'] });
        download.mockRejectedValue(new Error('forbidden'));

        render(<ConversationDebugDownloadButton conversationId={42} />);
        await user.click(screen.getByTestId('chat-debug-export'));

        expect(await screen.findByTestId('chat-debug-export-error')).toHaveTextContent(
            'Download del debug JSON non riuscito.',
        );
    });
});
