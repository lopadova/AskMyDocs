import { SelectField, SelectOption } from '@/components/ui/select-field';
import { translateUi, useUiTranslation } from '../../../../lib/ui-locale';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Button } from '../../../../components/Button';
import { Icon } from '../../../../components/Icons';
import { UploadTransferProgress, type TransferProgress } from './UploadTransferProgress';
import './upload.css';
import { useAuthStore } from '../../../../lib/auth-store';
import { UploadDropzone } from './UploadDropzone';
import { OcrEstimateLine } from './OcrEstimateLine';
import {
    isTerminalBatch,
    type BatchItemStatus,
    type UploadBatchItem,
    type UploadBatchResponse,
} from './kb-upload.api';
import {
    useBatchProgress,
    useCancelBatch,
    useCommitBatch,
    useOcrEstimate,
    useRemoveStagedItem,
    useStageBatch,
} from './kb-upload.hooks';

/**
 * v8.9 — drag-and-drop upload modal. State machine:
 *   selecting → staging → review → committing → progress → done | error
 *
 * Pre-commit items come from the stage response; post-commit they come from
 * the polled status endpoint (poll stops at terminal). Errors surface in the
 * DOM (R14). R11/R29 testids + R15 a11y (role=dialog, Esc, focusable controls,
 * errors next to context).
 */

/**
 * v8.36 / R18 — the picker's `accept` derives from the extensions the server
 * delivered on `/api/auth/me` (`SourceType::knownExtensions()`: images only
 * while KB_OCR_ENABLED is on, R43), never from a second literal list here.
 * Until they arrive the picker is unfiltered: acceptance is the server's
 * call anyway (StageKbUploadRequest answers 422 and the error surfaces in
 * the DOM, R14).
 */
export function acceptAttribute(extensions: readonly string[]): string | undefined {
    const cleaned = extensions.map((e) => e.trim().replace(/^\./, '').toLowerCase()).filter((e) => e !== '');
    return cleaned.length === 0 ? undefined : cleaned.map((e) => `.${e}`).join(',');
}

type Phase = 'selecting' | 'staging' | 'review' | 'committing' | 'progress' | 'done' | 'error';

export interface UploadModalProps {
    seed: { projectKey: string | null; subPath: string; files: File[] } | null;
    defaultProject: string | null;
    projectOptions: string[];
    onClose: () => void;
    onCommitted: () => void;
}

function errMessage(err: unknown, files: File[] = []): string {
    const error = err as { code?: string; response?: { status?: number; headers?: Record<string, unknown>; data?: { message?: string; errors?: Record<string, string[]> } }; message?: string };
    const header = (name: string) => Object.entries(error?.response?.headers ?? {})
        .find(([key]) => key.toLowerCase() === name)?.[1];
    if (String(header('cf-mitigated') ?? '').toLowerCase() === 'challenge') {
        const explanation = translateUi('Cloudflare blocked this request with a browser verification. The site administrator must check the security rule for this API.');
        const ray = header('cf-ray');
        return typeof ray === 'string' ? `${explanation} Cloudflare Ray ID: ${ray}` : explanation;
    }
    if (error?.code === 'ECONNABORTED' || error?.code === 'ETIMEDOUT') {
        return translateUi('The server did not respond in time. Check your connection and try again.');
    }
    const status = error?.response?.status;
    if (status === 413) return translateUi('These files exceed the server upload limit. Try fewer or smaller files.');
    if (status === 419 || status === 401) return translateUi('Your session has expired. Sign in again before retrying the upload.');
    if (error?.code === 'ERR_NETWORK') return translateUi('The connection was lost. Check your network and try again.');
    const data = error?.response?.data;
    if (status === 403 && !data?.message) {
        return translateUi('Access denied (403). Check your account permissions and the site access rules.');
    }
    if (data?.errors) {
        return Object.entries(data.errors).flatMap(([key, messages]) => {
            const index = /^files\.(\d+)/.exec(key)?.[1];
            const name = index === undefined ? undefined : files[Number(index)]?.name;
            return messages.map((message) => name ? `${name}: ${message}` : message);
        }).join(' ');
    }
    return data?.message ?? error?.message ?? translateUi('Something went wrong.');
}

export function UploadModal({ seed, defaultProject, projectOptions, onClose, onCommitted }: UploadModalProps): ReactNode {
    const { t: translateInterface } = useUiTranslation();
    const accept = useAuthStore((s) => acceptAttribute(s.kbUploadAcceptedExtensions));
    const [phase, setPhase] = useState<Phase>('selecting');
    const [projectKey, setProjectKey] = useState(seed?.projectKey ?? defaultProject ?? '');
    const [subPath, setSubPath] = useState(seed?.subPath ?? '');
    const [pickedFiles, setPickedFiles] = useState<File[]>(seed?.files ?? []);
    const [batchId, setBatchId] = useState<string | null>(null);
    const [staged, setStaged] = useState<UploadBatchResponse | null>(null);
    const transferController = useRef<AbortController | null>(null);
    const [transfer, setTransfer] = useState<TransferProgress>({ loaded: 0 });
    const [uploadStopped, setUploadStopped] = useState(false);
    useEffect(() => () => {
        transferController.current?.abort();
        transferController.current = null;
    }, []);
    const [actionError, setActionError] = useState<string | null>(null);
    // Bumped whenever a staged item is removed so the OCR estimate re-reads
    // the batch it describes (R17 — the cached copy follows the source).
    const [estimateRevision, setEstimateRevision] = useState(0);

    const stageMut = useStageBatch();
    const commitMut = useCommitBatch();
    const cancelMut = useCancelBatch();
    const removeMut = useRemoveStagedItem();

    const poll = phase === 'progress';
    const progress = useBatchProgress(batchId, poll);
    // v8.36 / ADR 0029 §8 — the OCR cost line, read only in the review phase.
    const ocrEstimate = useOcrEstimate(batchId, phase === 'review', estimateRevision);

    // Transition to `done` once the polled batch is terminal, exactly once.
    useEffect(() => {
        if (phase === 'progress' && isTerminalBatch(progress.data?.batch)) {
            setPhase('done');
            onCommitted();
        }
    }, [phase, progress.data?.batch, onCommitted]);

    const displayItems: UploadBatchItem[] =
        phase === 'progress' || phase === 'done'
            ? (progress.data?.items ?? staged?.items ?? [])
            : (staged?.items ?? []);

    const counts = (phase === 'progress' || phase === 'done' ? progress.data?.batch.counts : staged?.batch.counts) ?? null;
    const total = displayItems.length;
    const done = counts ? counts.succeeded + counts.failed : 0;
    const failed = counts ? counts.failed : 0;
    const anyCanonical = (staged?.items ?? []).some((i) => i.is_canonical);

    const canStage = phase === 'selecting' && pickedFiles.length > 0 && projectKey.trim() !== '';
    const stagedCount = (staged?.items ?? []).filter((i) => i.status === 'staged').length;

    function handleStage() {
        if (transferController.current) return;
        const controller = new AbortController();
        transferController.current = controller;
        setTransfer({ loaded: 0 });
        setUploadStopped(false);
        setActionError(null);
        setPhase('staging');
        stageMut.mutate(
            {
                projectKey: projectKey.trim(), subPath: subPath.trim(), files: pickedFiles,
                signal: controller.signal,
                onUploadProgress: ({ loaded, total }) => {
                    if (transferController.current === controller) setTransfer({ loaded, total });
                },
            },
            {
                onSuccess: (resp) => {
                    if (transferController.current !== controller) return;
                    transferController.current = null;
                    setBatchId(resp.batch.id);
                    setStaged(resp);
                    setPhase('review');
                },
                onError: (err) => {
                    if (transferController.current !== controller) return;
                    transferController.current = null;
                    setActionError(errMessage(err, pickedFiles));
                    setPhase('selecting');
                },
            },
        );
    }

    function stopUpload() {
        const controller = transferController.current;
        transferController.current = null;
        controller?.abort();
        setUploadStopped(true);
        setPhase('selecting');
    }

    function handleRemove(itemId: string) {
        if (!batchId || !staged) return;
        const removedIndex = staged.items.findIndex((i) => i.id === itemId);
        if (removedIndex === -1) return;
        const removed = staged.items[removedIndex];
        if (!removed) return;

        setActionError(null);
        // Optimistic: drop the row now; dedupe by id so it appears at most once.
        setStaged((prev) =>
            prev ? { ...prev, items: prev.items.filter((i) => i.id !== itemId) } : prev,
        );
        removeMut.mutate(
            { batchId, itemId },
            {
                onSuccess: () => setEstimateRevision((r) => r + 1),
                // R14: a failed DELETE must NOT read as success. The file is still
                // staged server-side and would be ingested on commit, so restore
                // the row at its original position and surface the error.
                onError: (err) => {
                    setStaged((prev) => {
                        if (!prev || prev.items.some((i) => i.id === itemId)) return prev;
                        const items = [...prev.items];
                        items.splice(Math.min(removedIndex, items.length), 0, removed);
                        return { ...prev, items };
                    });
                    setActionError(errMessage(err));
                },
            },
        );
    }

    function handleCommit() {
        if (!batchId) return;
        setActionError(null);
        setPhase('committing');
        // Send the currently-visible staged ids so the BE optimistic-concurrency
        // guard can 409 if the set drifted (e.g. an in-flight or silently-failed
        // remove) instead of ingesting a stale set the operator no longer sees.
        const expectedItemIds = (staged?.items ?? [])
            .filter((i) => i.status === 'staged')
            .map((i) => i.id);
        commitMut.mutate(
            { batchId, expectedItemIds },
            {
                onSuccess: (resp) => {
                    setStaged(resp);
                    setPhase('progress');
                },
                onError: (err) => {
                    setActionError(errMessage(err));
                    setPhase('review');
                },
            },
        );
    }

    function handleCancel() {
        if (!batchId) return;
        setActionError(null);
        cancelMut.mutate(batchId, {
            onSuccess: onClose,
            onError: (err) => setActionError(errMessage(err)),
        });
    }

    const dataState =
        progress.isError && poll ? 'error' :
        actionError && (phase === 'selecting' || phase === 'review')
            ? 'error'
            : phase === 'staging' || phase === 'committing' || phase === 'progress'
              ? 'loading'
              : phase === 'done'
                ? failed > 0
                    ? 'error'
                    : 'ready'
                : phase === 'review'
                  ? 'ready'
                  : 'idle';

    return (
        <div
            data-testid="kb-upload-backdrop"
            style={{
                position: 'fixed',
                inset: 0,
                background: 'rgba(0,0,0,.45)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                zIndex: 110,
            }}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="kb-upload-title"
                data-testid="kb-upload-modal"
                data-state={dataState}
                style={{
                    background: 'var(--panel-solid, #1a1a22)',
                    border: '1px solid var(--panel-border-strong, rgba(255,255,255,.12))',
                    borderRadius: 12,
                    boxShadow: 'var(--shadow, 0 8px 24px rgba(0,0,0,.4))',
                    width: 'min(560px, 92vw)',
                    maxHeight: '86vh',
                    overflow: 'auto',
                    padding: 16,
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 12,
                }}
            >
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                    <h2 id="kb-upload-title" data-testid="kb-upload-title" style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>
                        {translateInterface("Upload documents")}</h2>
                    <Button
                        variant="quiet" size="sm" iconOnly
                        disabled={phase === 'staging' || phase === 'committing' || cancelMut.isPending}
                        data-testid="kb-upload-close"
                        aria-label={translateInterface("Close")}
                        onClick={onClose}
                    >
                        <Icon.Close size={16} />
                    </Button>
                </div>

                {/* Pre-commit: picker + dropzone + staged list. */}
                {(phase === 'selecting' || phase === 'staging' || phase === 'review') && (
                    <>
                        <div className="kb-upload-fields">
                            <label htmlFor="kb-upload-project-select" style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 4 }}>
                                <span style={labelTxt}>{translateInterface("Target project")}</span>
                                <SelectField
                                    id="kb-upload-project-select"
                                    data-testid="kb-upload-project-select"
                                    aria-label="Target project"
                                    value={projectKey}
                                    disabled={phase !== 'selecting'}
                                    onValueChange={(nextValue) => setProjectKey(nextValue)}
                                    style={fieldStyle}
                                >
                                    <SelectOption value="">{translateInterface("Select a project…")}</SelectOption>
                                    {projectOptions.map((key) => (
                                        <SelectOption key={key} value={key}>
                                            {key}
                                        </SelectOption>
                                    ))}
                                </SelectField>
                            </label>
                            <label htmlFor="kb-upload-target-path" style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 4 }}>
                                <span style={labelTxt}>{translateInterface("Subfolder (optional)")}</span>
                                <input
                                    id="kb-upload-target-path"
                                    data-testid="kb-upload-target-path"
                                    aria-label="Target subfolder"
                                    type="text"
                                    value={subPath}
                                    disabled={phase !== 'selecting'}
                                    onChange={(e) => setSubPath(e.target.value)}
                                    placeholder="runbooks"
                                    style={fieldStyle}
                                />
                            </label>
                        </div>

                        {phase === 'selecting' && (
                            <UploadDropzone onAddFiles={(f) => setPickedFiles((prev) => [...prev, ...f])} accept={accept} />
                        )}

                        {/* Picked-but-not-staged file names. */}
                        {(phase === 'selecting' || phase === 'staging') && pickedFiles.length > 0 && (
                            <ul data-testid="kb-upload-picked" style={listStyle}>
                                {pickedFiles.map((f, idx) => (
                                    <li key={`${f.name}-${idx}`} style={rowStyle}>
                                        <span style={{ color: 'var(--fg-1)', minWidth: 0, overflowWrap: 'anywhere' }}>{f.name}</span>
                                        <span style={{ color: 'var(--fg-3)', fontSize: 10.5 }}>{formatBytes(f.size)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {phase === 'staging' && <UploadTransferProgress progress={transfer} fileCount={pickedFiles.length} />}
                        {uploadStopped && phase === 'selecting' && (
                            <p role="status" style={hintStyle}>{translateInterface('Upload stopped. Your files are still selected; no indexing has been started.')}</p>
                        )}

                        {/* Staged rows (review). */}
                        {phase === 'review' && (
                            <ul data-testid="kb-upload-staged" style={listStyle}>
                                {displayItems.map((item) => (
                                    <li key={item.id} data-testid={`kb-upload-item-${item.id}`} data-status={item.status} style={rowStyle}>
                                        <div style={{ display: 'flex', flexDirection: 'column', gap: 2, minWidth: 0 }}>
                                            <span style={{ color: 'var(--fg-1)', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                                                {item.original_filename}
                                                {item.is_canonical && (
                                                    <span data-testid={`kb-upload-item-${item.id}-canonical`} title={item.canonical_warning ?? ''} style={canonicalBadge}>
                                                        canonical · not in git
                                                    </span>
                                                )}
                                            </span>
                                            <span style={{ color: 'var(--fg-3)', fontSize: 10.5, overflowWrap: 'anywhere' }}>{item.destination_path}</span>
                                            {item.status === 'failed' && item.error && (
                                                <span data-testid={`kb-upload-item-${item.id}-error`} role="alert" style={{ color: 'var(--err)', fontSize: 10.5 }}>
                                                    {item.error}
                                                </span>
                                            )}
                                        </div>
                                        <Button
                                            variant="quiet" size="sm" iconOnly
                                            disabled={removeMut.isPending || cancelMut.isPending}
                                            data-testid={`kb-upload-item-${item.id}-remove`}
                                            aria-label={`Remove ${item.original_filename}`}
                                            onClick={() => handleRemove(item.id)}
                                        >
                                            <Icon.Close size={14} />
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {phase === 'review' && (
                            <OcrEstimateLine
                                state={ocrEstimate.isError ? 'error' : ocrEstimate.data ? 'ready' : 'loading'}
                                estimate={ocrEstimate.data}
                                errorMessage={ocrEstimate.isError ? errMessage(ocrEstimate.error) : null}
                            />
                        )}

                        {anyCanonical && phase === 'review' && (
                            <p data-testid="kb-upload-canonical-warning" role="status" style={{ margin: 0, fontSize: 11, color: 'var(--warn, #d9a441)' }}>
                                Some files carry canonical frontmatter. They will be ingested but NOT added to your git repo — the canonical source of truth stays git → GitHub Action.
                            </p>
                        )}
                    </>
                )}

                {/* Post-commit progress. */}
                {(phase === 'committing' || phase === 'progress' || phase === 'done') && (
                    <>
                        <div
                            data-testid={`kb-upload-batch-${batchId}-progress`}
                            role="status"
                            data-done={done}
                            data-total={total}
                            data-failed={failed}
                            style={{ fontSize: 12.5, color: 'var(--fg-1)' }}
                        >
                            {phase === 'committing' ? translateInterface('Starting indexing…') : translateInterface('{done} of {total} files processed · {failed} errors', { done, total, failed })}
                            <progress className="kb-upload-meter" aria-label={translateInterface('Indexing progress')} max={Math.max(total, 1)} value={done} />
                        </div>
                        {phase !== 'done' && <p style={hintStyle}>{translateInterface('Files have been uploaded. Queued files are waiting to be processed; indexing continues in the background.')}</p>}
                        <ul data-testid="kb-upload-progress" style={listStyle}>
                            {displayItems.map((item) => (
                                <li
                                    key={item.id}
                                    data-testid={`kb-upload-item-${item.id}-progress`}
                                    data-status={item.status}
                                    aria-busy={!['succeeded', 'failed'].includes(item.status)}
                                    style={rowStyle}
                                >
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 2, minWidth: 0 }}>
                                        <span style={{ color: 'var(--fg-1)', overflowWrap: 'anywhere' }}>{item.original_filename}</span>
                                        {item.status === 'failed' && item.error && (
                                            <span data-testid={`kb-upload-item-${item.id}-error`} role="alert" style={{ color: 'var(--err)', fontSize: 10.5 }}>
                                                {item.error}
                                            </span>
                                        )}
                                    </div>
                                    <span style={statusChip(item.status)}>{translateInterface(statusLabels[item.status])}</span>
                                </li>
                            ))}
                        </ul>
                    </>
                )}

                {progress.isError && poll && (
                    <div data-testid="kb-upload-poll-error" role="alert" style={{ color: 'var(--err)', fontSize: 12 }}>
                        <p>{translateInterface('Could not refresh indexing status. The last known status is shown; indexing may still be running.')} {errMessage(progress.error)}</p>
                        <Button size="sm" busy={progress.isFetching} onClick={() => void progress.refetch()}>{translateInterface('Retry status check')}</Button>
                    </div>
                )}

                {actionError && (
                    <p data-testid="kb-upload-error" role="alert" style={{ margin: 0, fontSize: 11.5, color: 'var(--err)' }}>
                        {actionError}
                    </p>
                )}

                {/* Footer actions per phase. */}
                <div className="kb-upload-actions">
                    {phase === 'selecting' && (
                        <Button variant="primary" size="sm" autoFocus data-testid="kb-upload-stage" disabled={!canStage} onClick={handleStage}>
                            {translateInterface('Upload {count} files', { count: pickedFiles.length })}
                        </Button>
                    )}
                    {phase === 'staging' && (
                        <Button size="sm" autoFocus data-testid="kb-upload-stop" onClick={stopUpload}>{translateInterface('Stop upload')}</Button>
                    )}
                    {phase === 'review' && (
                        <>
                            <Button size="sm" data-testid="kb-upload-cancel" aria-label="Cancel batch" disabled={removeMut.isPending} busy={cancelMut.isPending} onClick={handleCancel}>
                                {translateInterface('Cancel')}</Button>
                            <Button variant="primary" size="sm" autoFocus data-testid="kb-upload-commit" disabled={stagedCount === 0 || removeMut.isPending || cancelMut.isPending} onClick={handleCommit}>
                                {translateInterface('Commit & ingest')}
                            </Button>
                        </>
                    )}
                    {phase === 'committing' && <Button variant="primary" size="sm" busy>{translateInterface('Starting indexing…')}</Button>}
                    {(phase === 'committing' || phase === 'progress') && (
                        <Button size="sm" data-testid="kb-upload-close-keep-ingesting" aria-label="Close (keeps ingesting)" onClick={onClose}>
                            {translateInterface('Close (keeps ingesting)')}
                        </Button>
                    )}
                    {phase === 'done' && (
                        <Button variant="primary" size="sm" data-testid="kb-upload-done-close" onClick={onClose}>
                            {translateInterface('Done')}</Button>
                    )}
                </div>
            </div>
        </div>
    );
}

function formatBytes(bytes: number | undefined): string {
    if (!Number.isFinite(bytes ?? NaN) || (bytes ?? 0) <= 0) {
        return '—';
    }
    const kb = (bytes as number) / 1024;
    return kb < 1024 ? `${kb.toFixed(0)} KB` : `${(kb / 1024).toFixed(1)} MB`;
}

const labelTxt: React.CSSProperties = { color: 'var(--fg-2)', fontSize: 11 };
const fieldStyle: React.CSSProperties = {
    padding: '5px 8px',
    borderRadius: 6,
    border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
    background: 'var(--bg-3, rgba(255,255,255,.04))',
    color: 'var(--fg-0)',
    fontSize: 12,
};
const listStyle: React.CSSProperties = {
    listStyle: 'none',
    margin: 0,
    padding: 0,
    display: 'flex',
    flexDirection: 'column',
    gap: 4,
    maxHeight: 240,
    overflow: 'auto',
};
const rowStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 8,
    padding: '6px 8px',
    border: '1px solid var(--hairline, rgba(255,255,255,.08))',
    borderRadius: 6,
    fontSize: 12,
};
const canonicalBadge: React.CSSProperties = {
    marginLeft: 6,
    padding: '1px 5px',
    borderRadius: 4,
    background: 'rgba(217,164,65,.15)',
    color: 'var(--warn, #d9a441)',
    fontSize: 9.5,
    textTransform: 'uppercase',
    letterSpacing: '0.03em',
};

const hintStyle: React.CSSProperties = { margin: 0, fontSize: 12, color: 'var(--fg-2)', lineHeight: 1.5 };
const statusLabels: Record<BatchItemStatus, string> = {
    staged: 'Ready to index', moving: 'Preparing file', queued: 'Queued for indexing',
    processing: 'Indexing', succeeded: 'Indexed', failed: 'Failed',
};

function statusChip(status: BatchItemStatus): React.CSSProperties {
    const palette: Record<BatchItemStatus, string> = {
        staged: 'var(--fg-3)',
        moving: 'var(--fg-3)',
        queued: 'var(--fg-3)',
        processing: 'var(--accent-a)',
        succeeded: 'var(--ok, #10b981)',
        failed: 'var(--err, #ef4444)',
    };
    return {
        padding: '1px 8px',
        borderRadius: 999,
        border: `1px solid ${palette[status]}`,
        color: palette[status],
        fontSize: 10,
        textTransform: 'uppercase',
        letterSpacing: '0.03em',
        whiteSpace: 'nowrap',
    };
}
