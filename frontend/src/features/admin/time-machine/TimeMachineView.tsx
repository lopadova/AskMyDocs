import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { getDiff, getVersions, restoreVersion, type DocVersion } from './timemachine.api';

/**
 * v8.7/W5 — Cloud Time Machine: browse a document's version timeline, diff
 * two versions, and restore an archived version to live.
 *
 * R11 testids · R14 distinct loading/empty/error states · R15 a11y.
 */
export function TimeMachineView({ docId }: { docId: number }): ReactNode {
    const qc = useQueryClient();
    const [fromId, setFromId] = useState<number | null>(null);
    const [toId, setToId] = useState<number | null>(null);
    const [restoreError, setRestoreError] = useState<string | null>(null);

    const timeline = useQuery({
        queryKey: ['kb-time-machine', docId],
        queryFn: () => getVersions(docId),
        staleTime: 15_000,
    });

    const diff = useQuery({
        queryKey: ['kb-time-machine-diff', docId, fromId, toId],
        queryFn: () => getDiff(docId, fromId as number, toId as number),
        enabled: fromId !== null && toId !== null && fromId !== toId,
        staleTime: 15_000,
    });

    const restoreMutation = useMutation({
        mutationFn: (versionId: number) => restoreVersion(versionId),
        onSuccess: () => {
            setRestoreError(null);
            qc.invalidateQueries({ queryKey: ['kb-time-machine', docId] });
        },
        onError: (err: unknown) => {
            setRestoreError(err instanceof Error ? err.message : 'Could not restore this version.');
        },
    });

    const versions = timeline.data?.data ?? [];

    return (
        <div data-testid="kb-time-machine-view" style={{ padding: 24 }}>
            <header style={{ marginBottom: 8 }}>
                <h1 style={{ margin: 0, fontSize: 18, color: 'var(--fg-0)' }}>Time Machine</h1>
                {timeline.data && (
                    <p data-testid="kb-time-machine-source" style={{ margin: '2px 0 0', color: 'var(--fg-3)', fontSize: 11.5 }}>
                        {timeline.data.meta.project_key} · {timeline.data.meta.source_path} · {timeline.data.meta.total} versions
                    </p>
                )}
            </header>
            <p style={{ margin: '0 0 16px', color: 'var(--fg-3)', fontSize: 11.5, maxWidth: 640 }}>
                Every re-ingest keeps the previous version. Pick two versions to diff, or restore an archived
                version to make it live again.
            </p>

            {restoreError && (
                <p data-testid="kb-time-machine-restore-error" role="alert" style={{ color: 'var(--err)', fontSize: 12, marginBottom: 8 }}>
                    {restoreError}
                </p>
            )}

            {timeline.isLoading && (
                <p data-testid="kb-time-machine-loading" data-state="loading" style={{ color: 'var(--fg-3)' }}>Loading…</p>
            )}
            {!timeline.isLoading && timeline.isError && (
                <p data-testid="kb-time-machine-error" data-state="error" role="alert" style={{ color: 'var(--err)', padding: 24, textAlign: 'center', border: '1px dashed var(--err)', borderRadius: 8 }}>
                    Failed to load the version timeline. {timeline.error instanceof Error ? timeline.error.message : ''}
                </p>
            )}
            {!timeline.isLoading && !timeline.isError && versions.length === 0 && (
                <p data-testid="kb-time-machine-empty" data-state="empty" style={{ color: 'var(--fg-3)', padding: 24, textAlign: 'center', border: '1px dashed var(--panel-border)', borderRadius: 8 }}>
                    No versions found for this document.
                </p>
            )}

            {versions.length > 0 && (
                <div data-testid="kb-time-machine-timeline" data-state="ready" style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                    {versions.map((v) => (
                        <VersionRow
                            key={v.id}
                            v={v}
                            isFrom={fromId === v.id}
                            isTo={toId === v.id}
                            onPickFrom={() => setFromId(v.id)}
                            onPickTo={() => setToId(v.id)}
                            onRestore={() => restoreMutation.mutate(v.id)}
                            restoring={restoreMutation.isPending}
                        />
                    ))}
                </div>
            )}

            {fromId !== null && toId !== null && fromId !== toId && (
                <section data-testid="kb-time-machine-diff" style={{ marginTop: 20 }}>
                    <h2 style={{ fontSize: 13, color: 'var(--fg-1)' }}>Diff</h2>
                    {diff.isLoading && <p data-testid="kb-time-machine-diff-loading" data-state="loading" style={{ color: 'var(--fg-3)' }}>Diffing…</p>}
                    {diff.isError && (
                        <p data-testid="kb-time-machine-diff-error" data-state="error" role="alert" style={{ color: 'var(--err)', fontSize: 12 }}>
                            Failed to load diff. {diff.error instanceof Error ? diff.error.message : ''}
                        </p>
                    )}
                    {diff.data && (
                        <>
                            <p data-testid="kb-time-machine-diff-summary" style={{ fontSize: 12, color: 'var(--fg-2)' }}>
                                +{diff.data.added} / −{diff.data.removed}
                            </p>
                            {/* A styled <div> (not <pre>) — block-level <div>
                                children are invalid inside <pre> (Copilot review). */}
                            <div data-testid="kb-time-machine-diff-body" style={{ background: 'var(--bg-2, rgba(255,255,255,.02))', border: '1px solid var(--panel-border)', borderRadius: 8, padding: 12, fontSize: 12, fontFamily: 'var(--font-mono, monospace)', overflowX: 'auto', margin: 0 }}>
                                {diff.data.rows.map((r, i) => (
                                    <div
                                        key={i}
                                        data-diff-type={r.type}
                                        style={{
                                            color: r.type === 'add' ? 'var(--ok, #3fb950)' : r.type === 'remove' ? 'var(--err, #c4391d)' : 'var(--fg-2)',
                                            whiteSpace: 'pre-wrap',
                                        }}
                                    >
                                        {r.type === 'add' ? '+ ' : r.type === 'remove' ? '- ' : '  '}{r.text}
                                    </div>
                                ))}
                            </div>
                        </>
                    )}
                </section>
            )}
        </div>
    );
}

function VersionRow({
    v, isFrom, isTo, onPickFrom, onPickTo, onRestore, restoring,
}: {
    v: DocVersion;
    isFrom: boolean;
    isTo: boolean;
    onPickFrom: () => void;
    onPickTo: () => void;
    onRestore: () => void;
    restoring: boolean;
}): ReactNode {
    return (
        <div
            data-testid={`kb-time-machine-version-${v.id}`}
            data-version-status={v.status}
            data-is-live={v.is_live ? 'true' : 'false'}
            style={{
                display: 'flex', alignItems: 'center', gap: 10,
                border: '1px solid var(--panel-border, rgba(255,255,255,.1))',
                borderRadius: 8, padding: '8px 12px',
                background: v.is_live ? 'var(--accent-bg, rgba(99,102,241,.08))' : 'transparent',
            }}
        >
            <span style={{ fontFamily: 'var(--font-mono, monospace)', fontSize: 11, color: 'var(--fg-3)' }}>
                {(v.version_hash ?? '').slice(0, 8) || `#${v.id}`}
            </span>
            <span style={{ fontSize: 12.5, color: 'var(--fg-0)' }}>{v.title ?? `Version ${v.id}`}</span>
            <span style={{ fontSize: 11, color: v.is_live ? 'var(--ok, #3fb950)' : 'var(--fg-3)' }}>
                {v.is_live ? 'live' : v.status}
            </span>
            <span style={{ flex: 1 }} />
            <button type="button" data-testid={`kb-time-machine-version-${v.id}-from`} onClick={onPickFrom} aria-pressed={isFrom} aria-label={`Diff from ${versionLabel(v)}`} style={pill(isFrom)}>From</button>
            <button type="button" data-testid={`kb-time-machine-version-${v.id}-to`} onClick={onPickTo} aria-pressed={isTo} aria-label={`Diff to ${versionLabel(v)}`} style={pill(isTo)}>To</button>
            {!v.is_live && (
                <button
                    type="button"
                    data-testid={`kb-time-machine-version-${v.id}-restore`}
                    onClick={onRestore}
                    disabled={restoring}
                    aria-label={`Restore ${versionLabel(v)}`}
                    style={{ ...pill(false), border: '1px solid var(--accent, #6366f1)', color: 'var(--accent, #6366f1)' }}
                >
                    Restore
                </button>
            )}
        </div>
    );
}

/** A human-readable, unique label for a version (for SR aria-labels). */
function versionLabel(v: DocVersion): string {
    const hash = (v.version_hash ?? '').slice(0, 8);
    const name = v.title ?? `version ${v.id}`;
    return hash ? `${name} (${hash}${v.is_live ? ', live' : ''})` : `${name}${v.is_live ? ' (live)' : ''}`;
}

function pill(active: boolean): React.CSSProperties {
    return {
        padding: '3px 10px', borderRadius: 6, fontSize: 11.5, cursor: 'pointer',
        border: '1px solid ' + (active ? 'var(--accent, #6366f1)' : 'var(--panel-border, rgba(255,255,255,.15))'),
        background: active ? 'var(--accent, #6366f1)' : 'transparent',
        color: active ? 'white' : 'var(--fg-2)',
    };
}
