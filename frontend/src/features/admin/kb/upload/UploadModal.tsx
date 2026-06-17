import { useEffect, useState, type ReactNode } from 'react';
import { UploadDropzone } from './UploadDropzone';
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

const ACCEPT = '.md,.markdown,.txt,.pdf,.docx';

type Phase = 'selecting' | 'staging' | 'review' | 'committing' | 'progress' | 'done' | 'error';

export interface UploadModalProps {
    seed: { projectKey: string | null; subPath: string; files: File[] } | null;
    defaultProject: string | null;
    projectOptions: string[];
    onClose: () => void;
    onCommitted: () => void;
}

function errMessage(err: unknown): string {
    const anyErr = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } }; message?: string };
    const data = anyErr?.response?.data;
    if (data?.errors) {
        const first = Object.values(data.errors)[0];
        if (Array.isArray(first) && first[0]) {
            return first[0];
        }
    }
    return data?.message ?? anyErr?.message ?? 'Something went wrong.';
}

export function UploadModal({ seed, defaultProject, projectOptions, onClose, onCommitted }: UploadModalProps): ReactNode {
    const [phase, setPhase] = useState<Phase>('selecting');
    const [projectKey, setProjectKey] = useState(seed?.projectKey ?? defaultProject ?? '');
    const [subPath, setSubPath] = useState(seed?.subPath ?? '');
    const [pickedFiles, setPickedFiles] = useState<File[]>(seed?.files ?? []);
    const [batchId, setBatchId] = useState<string | null>(null);
    const [staged, setStaged] = useState<UploadBatchResponse | null>(null);
    const [actionError, setActionError] = useState<string | null>(null);

    const stageMut = useStageBatch();
    const commitMut = useCommitBatch();
    const cancelMut = useCancelBatch();
    const removeMut = useRemoveStagedItem();

    const poll = phase === 'committing' || phase === 'progress';
    const progress = useBatchProgress(batchId, poll);

    // Esc closes (mirror ProjectFormDialog).
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    // Transition to `done` once the polled batch is terminal, exactly once.
    useEffect(() => {
        if ((phase === 'progress' || phase === 'committing') && isTerminalBatch(progress.data?.batch)) {
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
        setActionError(null);
        setPhase('staging');
        stageMut.mutate(
            { projectKey: projectKey.trim(), subPath: subPath.trim(), files: pickedFiles },
            {
                onSuccess: (resp) => {
                    setBatchId(resp.batch.id);
                    setStaged(resp);
                    setPhase('review');
                },
                onError: (err) => {
                    setActionError(errMessage(err));
                    setPhase('selecting');
                },
            },
        );
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
        if (batchId) {
            cancelMut.mutate(batchId);
        }
        onClose();
    }

    const dataState =
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
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
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
                aria-busy={dataState === 'loading'}
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
                        Upload documents
                    </h2>
                    <button
                        type="button"
                        data-testid="kb-upload-close"
                        aria-label="Close"
                        onClick={onClose}
                        style={ghostBtn}
                    >
                        ✕
                    </button>
                </div>

                {/* Pre-commit: picker + dropzone + staged list. */}
                {(phase === 'selecting' || phase === 'staging' || phase === 'review') && (
                    <>
                        <div style={{ display: 'flex', gap: 8 }}>
                            <label htmlFor="kb-upload-project-select" style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 4 }}>
                                <span style={labelTxt}>Target project</span>
                                <select
                                    id="kb-upload-project-select"
                                    data-testid="kb-upload-project-select"
                                    aria-label="Target project"
                                    value={projectKey}
                                    disabled={phase !== 'selecting'}
                                    onChange={(e) => setProjectKey(e.target.value)}
                                    style={fieldStyle}
                                >
                                    <option value="">Select a project…</option>
                                    {projectOptions.map((key) => (
                                        <option key={key} value={key}>
                                            {key}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label htmlFor="kb-upload-target-path" style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 4 }}>
                                <span style={labelTxt}>Subfolder (optional)</span>
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
                            <UploadDropzone onAddFiles={(f) => setPickedFiles((prev) => [...prev, ...f])} accept={ACCEPT} />
                        )}

                        {/* Picked-but-not-staged file names. */}
                        {phase === 'selecting' && pickedFiles.length > 0 && (
                            <ul data-testid="kb-upload-picked" style={listStyle}>
                                {pickedFiles.map((f, idx) => (
                                    <li key={`${f.name}-${idx}`} style={rowStyle}>
                                        <span style={{ color: 'var(--fg-1)' }}>{f.name}</span>
                                        <span style={{ color: 'var(--fg-3)', fontSize: 10.5 }}>{formatBytes(f.size)}</span>
                                    </li>
                                ))}
                            </ul>
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
                                            <span style={{ color: 'var(--fg-3)', fontSize: 10.5 }}>→ {item.destination_path}</span>
                                            {item.status === 'failed' && item.error && (
                                                <span data-testid={`kb-upload-item-${item.id}-error`} role="alert" style={{ color: 'var(--err)', fontSize: 10.5 }}>
                                                    {item.error}
                                                </span>
                                            )}
                                        </div>
                                        <button
                                            type="button"
                                            data-testid={`kb-upload-item-${item.id}-remove`}
                                            aria-label={`Remove ${item.original_filename}`}
                                            onClick={() => handleRemove(item.id)}
                                            style={ghostBtn}
                                        >
                                            ✕
                                        </button>
                                    </li>
                                ))}
                            </ul>
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
                            {total === 0 ? 'Preparing…' : `${done} of ${total} done${failed > 0 ? ` · ${failed} failed` : ''}`}
                        </div>
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
                                        <span style={{ color: 'var(--fg-1)' }}>{item.original_filename}</span>
                                        {item.status === 'failed' && item.error && (
                                            <span data-testid={`kb-upload-item-${item.id}-error`} role="alert" style={{ color: 'var(--err)', fontSize: 10.5 }}>
                                                {item.error}
                                            </span>
                                        )}
                                    </div>
                                    <span style={statusChip(item.status)}>{item.status}</span>
                                </li>
                            ))}
                        </ul>
                    </>
                )}

                {actionError && (
                    <p data-testid="kb-upload-error" role="alert" style={{ margin: 0, fontSize: 11.5, color: 'var(--err)' }}>
                        {actionError}
                    </p>
                )}

                {/* Footer actions per phase. */}
                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 4 }}>
                    {phase === 'selecting' && (
                        <button type="button" data-testid="kb-upload-stage" disabled={!canStage} onClick={handleStage} style={primaryBtn(!canStage)}>
                            Stage {pickedFiles.length > 0 ? `${pickedFiles.length} file${pickedFiles.length > 1 ? 's' : ''}` : 'files'}
                        </button>
                    )}
                    {phase === 'staging' && <span style={{ fontSize: 12, color: 'var(--fg-3)' }}>Uploading…</span>}
                    {phase === 'review' && (
                        <>
                            <button type="button" data-testid="kb-upload-cancel" aria-label="Cancel batch" onClick={handleCancel} style={secondaryBtn}>
                                Cancel
                            </button>
                            <button type="button" data-testid="kb-upload-commit" disabled={stagedCount === 0 || commitMut.isPending} onClick={handleCommit} style={primaryBtn(stagedCount === 0)}>
                                Commit &amp; ingest
                            </button>
                        </>
                    )}
                    {(phase === 'committing' || phase === 'progress') && (
                        <button type="button" data-testid="kb-upload-close-keep-ingesting" aria-label="Close (keeps ingesting)" onClick={onClose} style={secondaryBtn}>
                            Close (keeps ingesting)
                        </button>
                    )}
                    {phase === 'done' && (
                        <button type="button" data-testid="kb-upload-done-close" onClick={onClose} style={primaryBtn(false)}>
                            Done
                        </button>
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
const ghostBtn: React.CSSProperties = {
    background: 'transparent',
    border: 'none',
    color: 'var(--fg-3)',
    cursor: 'pointer',
    fontSize: 13,
    lineHeight: 1,
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

function primaryBtn(disabled: boolean): React.CSSProperties {
    return {
        padding: '5px 14px',
        borderRadius: 6,
        border: '1px solid var(--accent, #6366f1)',
        background: 'var(--accent, #6366f1)',
        color: 'white',
        fontSize: 11.5,
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.6 : 1,
    };
}
const secondaryBtn: React.CSSProperties = {
    padding: '5px 14px',
    borderRadius: 6,
    border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
    background: 'transparent',
    color: 'var(--fg-1)',
    fontSize: 11.5,
    cursor: 'pointer',
};

function statusChip(status: BatchItemStatus): React.CSSProperties {
    const palette: Record<BatchItemStatus, string> = {
        staged: 'var(--fg-3)',
        moving: 'var(--fg-3)',
        queued: 'var(--fg-3)',
        processing: 'var(--accent, #6366f1)',
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
