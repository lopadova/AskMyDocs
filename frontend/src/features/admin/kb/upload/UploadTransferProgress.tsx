import { useEffect, useRef, useState } from 'react';
import { useUiTranslation } from '../../../../lib/ui-locale';

export interface TransferProgress {
    loaded: number;
    total?: number;
}

/** Bytes describe the whole multipart request, never invented per-file progress. */
export function UploadTransferProgress({ progress, fileCount }: { progress: TransferProgress; fileCount: number }) {
    const { t } = useUiTranslation();
    const startedAt = useRef(Date.now());
    const [time, setTime] = useState({ elapsed: 0, idle: 0 });
    useEffect(() => {
        const changedAt = Date.now();
        const timer = window.setInterval(() => setTime({
            elapsed: Math.floor((Date.now() - startedAt.current) / 1000),
            idle: Math.floor((Date.now() - changedAt) / 1000),
        }), 1000);
        return () => window.clearInterval(timer);
    }, [progress.loaded]);

    const total = progress.total && progress.total > 0 ? progress.total : undefined;
    const percent = total ? Math.min(100, Math.floor(progress.loaded / total * 100)) : undefined;
    const waiting = total !== undefined && progress.loaded >= total;

    return (
        <section className="kb-upload-transfer" data-testid="kb-upload-transfer" data-state={waiting ? 'waiting' : 'uploading'}>
            <div className="kb-upload-transfer-heading">
                <strong><span className="ui-button-spinner" aria-hidden="true" /> {t('Uploading {count} files', { count: fileCount })}</strong>
                <span>{percent === undefined ? t(progress.loaded > 0 ? 'Uploading…' : 'Connecting…') : `${percent}%`}</span>
            </div>
            <progress className="kb-upload-meter" aria-label={t('File transfer progress')} max={100} value={percent} />
            <div className="kb-upload-transfer-heading">
                <span>{total ? t('{loaded} of {total} transferred', { loaded: formatTransferBytes(progress.loaded), total: formatTransferBytes(total) }) : t('{loaded} transferred', { loaded: formatTransferBytes(progress.loaded) })}</span>
                <span aria-hidden="true">{time.elapsed}s</span>
            </div>
            <p role="status" aria-live="polite">
                {waiting
                    ? t('Transfer complete. Waiting for the server to confirm the files before review…')
                    : t('Uploading files to the server. Keep this window open until the review appears.')}
            </p>
            {time.idle >= 15 && (
                <p role="status" className="kb-upload-slow" data-testid="kb-upload-slow">
                    {waiting
                        ? t('The server is taking longer to confirm the files. You can keep waiting or stop and try again.')
                        : t('No transfer progress for 15 seconds. Check your connection; you can stop and try again.')}
                </p>
            )}
        </section>
    );
}

function formatTransferBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
