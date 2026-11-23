import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Button } from '../../../components/Button';
import { useUiTranslation } from '../../../lib/ui-locale';
import { toAdminError } from '../shared/errors';
import { UploadModal } from '../kb/upload/UploadModal';
import { kbUploadApi } from '../kb/upload/kb-upload.api';
import type { WidgetSetup } from './widget-project-setup.api';

const batchLabels = {
    staged: 'Ready for review',
    committing: 'Starting indexing…',
    processing: 'Indexing in progress',
    completed: 'Indexed',
    completed_with_errors: 'Indexed with errors',
    cancelled: 'Cancelled',
    expired: 'Expired',
} as const;

export function WidgetSetupDocuments({ setup, refresh }: { setup: WidgetSetup; refresh: () => void }) {
    const { t } = useUiTranslation();
    const [upload, setUpload] = useState<{ batchId?: string; subPath?: string } | null>(null);
    const batch = useQuery({
        queryKey: ['widget-setup-upload', setup.id, upload?.batchId],
        queryFn: () => kbUploadApi.get(upload?.batchId ?? ''),
        enabled: Boolean(upload?.batchId),
        staleTime: 0,
        gcTime: 0,
        retry: false,
    });

    const closeUpload = () => {
        setUpload(null);
        refresh();
    };

    return (
        <div className="grid gap-4">
            <p className="text-sm text-muted-foreground">{t('Upload the approved documents for {project}. Review the files before starting ingestion.', { project: setup.project_key })}</p>
            <p role="status" className="text-sm" data-testid="widget-setup-indexing">
                {t('Indexed documents: {count}', { count: setup.indexed_documents })}
                {setup.is_indexing && ` · ${t('Indexing in progress')}`}
            </p>
            {setup.failures.length > 0 && (
                <section className="grid gap-2 rounded-md border border-border p-3" aria-labelledby="widget-setup-failures-heading">
                    <h3 id="widget-setup-failures-heading" className="text-sm font-medium">{t('Files to correct')}</h3>
                    <p className="text-xs text-muted-foreground">{t('Use the same filename and subfolder when uploading a corrected file.')}</p>
                    <ul className="grid gap-2 text-sm" data-testid="widget-setup-failures">
                        {setup.failures.map((failure) => (
                            <li key={failure.path} className="[overflow-wrap:anywhere]">
                                <span className="font-medium">{failure.path}</span>
                                {failure.error && <p className="text-xs text-muted-foreground">{failure.error}</p>}
                            </li>
                        ))}
                    </ul>
                </section>
            )}
            {setup.batches.length > 0 && (
                <ul className="divide-y divide-border rounded-md border border-border" aria-label={t('Recent uploads')}>
                    {setup.batches.map((item) => (
                        <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 p-2 text-sm">
                            <span>{t(batchLabels[item.status])}{item.sub_path ? ` · ${item.sub_path}` : ''}</span>
                            <div className="flex flex-wrap gap-2">
                                {item.status === 'completed_with_errors' && (
                                    <Button size="sm" data-testid={`widget-setup-retry-${item.id}`} onClick={() => setUpload({ subPath: item.sub_path ?? '' })}>
                                        {t('Upload corrections')}
                                    </Button>
                                )}
                                <Button
                                    size="sm"
                                    data-testid={`widget-setup-batch-${item.id}`}
                                    onClick={() => setUpload({ batchId: item.id })}
                                    busy={upload?.batchId === item.id && batch.isPending}
                                >
                                    {t(item.status === 'staged' ? 'Review upload' : 'View files')}
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            {upload?.batchId && batch.isError && (
                <p role="alert" className="text-sm text-destructive">{t('Could not open the upload. It may have expired.')} {toAdminError(batch.error).message}</p>
            )}
            <div>
                <Button size="sm" onClick={() => setUpload({})} data-testid="widget-setup-upload">{t('Upload documents')}</Button>
            </div>
            {upload && (!upload.batchId || batch.data?.batch.project_key === setup.project_key) && (
                <UploadModal
                    seed={{ projectKey: setup.project_key, subPath: upload.subPath ?? '', files: [] }}
                    defaultProject={setup.project_key}
                    projectOptions={[setup.project_key]}
                    projectLocked
                    resume={upload.batchId ? batch.data : undefined}
                    onClose={closeUpload}
                    onCommitted={refresh}
                />
            )}
        </div>
    );
}
