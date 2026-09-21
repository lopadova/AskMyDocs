import { useState, type ReactNode } from 'react';
import { Button } from '../../components/Button';
import { Icon } from '../../components/Icons';
import { useAuthStore } from '../../lib/auth-store';
import { chatApi } from './chat.api';

export interface ConversationDebugDownloadButtonProps {
    conversationId: number;
}

/**
 * Downloads the persisted forensic record for a chat. Visibility is a small
 * UX convenience only: the API applies the actual super-admin authorization.
 */
export function ConversationDebugDownloadButton({
    conversationId,
}: ConversationDebugDownloadButtonProps): ReactNode {
    const isSuperAdmin = useAuthStore((state) => state.roles.includes('super-admin'));
    const [isDownloading, setIsDownloading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (!isSuperAdmin) {
        return null;
    }

    const download = async (): Promise<void> => {
        setError(null);
        setIsDownloading(true);

        try {
            const { blob, filename } = await chatApi.downloadDebugTranscript(conversationId);
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            // Safari can cancel a download when its blob URL is reclaimed in
            // the same task, so release it shortly after the click instead.
            window.setTimeout(() => URL.revokeObjectURL(url), 5_000);
        } catch {
            setError('Download del debug JSON non riuscito.');
        } finally {
            setIsDownloading(false);
        }
    };

    const errorId = `chat-debug-export-error-${conversationId}`;

    return (
        <div className="chat-header-debug-export-wrap">
            <Button
                variant="secondary"
                size="sm"
                leadingIcon={<Icon.Download size={13} />}
                className="chat-header-debug-export"
                data-testid="chat-debug-export"
                aria-label="Scarica il debug JSON della chat"
                aria-describedby={error === null ? undefined : errorId}
                title="Scarica il debug JSON della chat"
                busy={isDownloading}
                onClick={() => void download()}
            >
                Scarica JSON debug
            </Button>
            {error !== null && (
                <span
                    id={errorId}
                    role="alert"
                    data-testid="chat-debug-export-error"
                    className="chat-header-debug-export-error"
                >
                    {error}
                </span>
            )}
        </div>
    );
}
