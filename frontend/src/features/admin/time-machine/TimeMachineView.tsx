import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { getDiff, getVersions, restoreVersion, type DocVersion, type VersionContentIntegrity, type VersionContentSource } from './timemachine.api';

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
    const [restoredId, setRestoredId] = useState<number | null>(null);
    // Older pages of a bounded family (R3): appended by "Load older versions".
    const [older, setOlder] = useState<DocVersion[]>([]);
    const [olderError, setOlderError] = useState<string | null>(null);

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
        onMutate: () => {
            // A retry starts clean: the previous outcome (either way) is gone.
            setRestoredId(null);
            setRestoreError(null);
        },
        onSuccess: (restored) => {
            setRestoreError(null);
            setRestoredId(restored.id);
            setOlder([]);
            qc.invalidateQueries({ queryKey: ['kb-time-machine', docId] });
        },
        onError: (err: unknown) => {
            setRestoreError(err instanceof Error ? err.message : 'Could not restore this version.');
        },
    });

    const loadOlder = useMutation({
        mutationFn: () => getVersions(docId, { offset: (timeline.data?.data.length ?? 0) + older.length }),
        onSuccess: (page) => {
            setOlderError(null);
            setOlder((current) => [...current, ...page.data]);
        },
        onError: (err: unknown) => {
            setOlderError(err instanceof Error ? err.message : 'Could not load older versions.');
        },
    });

    const versions = [...(timeline.data?.data ?? []), ...older];
    const familyTotal = timeline.data?.meta.total ?? versions.length;
    const hasOlder = familyTotal > versions.length;
    const restoring = restoreMutation.isPending;
    const pick = (setter: (id: number) => void) => (id: number) => {
        setRestoredId(null); // the announcement is about the last action, not a permanent banner
        setter(id);
    };
    // R11 — the restore mutation is an observable async state of its own:
    // announced (role="status", aria-live) and exposed on the timeline
    // container (aria-busy + data-state), not only as disabled buttons.
    const restoreState: 'idle' | 'restoring' | 'restored' | 'error' = restoring ? 'restoring' : restoreError ? 'error' : restoredId !== null ? 'restored' : 'idle';
    const diffEnabled = fromId !== null && toId !== null && fromId !== toId;
    const diffState: 'idle' | 'loading' | 'ready' | 'error' = !diffEnabled ? 'idle' : diff.isLoading ? 'loading' : diff.isError ? 'error' : diff.data ? 'ready' : 'idle';

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
            <p
                data-testid="kb-time-machine-restore-status"
                data-state={restoreState === 'restoring' ? 'loading' : restoreState === 'error' ? 'error' : restoreState === 'restored' ? 'ready' : 'idle'}
                data-restore-state={restoreState}
                role="status"
                aria-live="polite"
                style={{ fontSize: 12, color: 'var(--fg-3)', margin: restoreState === 'idle' || restoreState === 'error' ? 0 : '0 0 8px', minHeight: 0 }}
            >
                {restoreState === 'restoring' ? 'Restoring version…' : restoreState === 'restored' ? `Version ${restoredId} restored and live.` : ''}
            </p>
            {timeline.data && hasOlder && (
                <p data-testid="kb-time-machine-truncated" role="note" style={{ fontSize: 11.5, color: 'var(--warn, #d29922)', margin: '0 0 8px' }}>
                    Showing the newest {versions.length} of {familyTotal} versions.
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
                <div data-testid="kb-time-machine-timeline" data-state="ready" data-restore-state={restoreState} aria-busy={restoring} style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                    {versions.map((v) => (
                        <VersionRow
                            key={v.id}
                            v={v}
                            isFrom={fromId === v.id}
                            isTo={toId === v.id}
                            onPickFrom={() => pick(setFromId)(v.id)}
                            onPickTo={() => pick(setToId)(v.id)}
                            onRestore={() => restoreMutation.mutate(v.id)}
                            restoring={restoring}
                        />
                    ))}
                    {hasOlder && (
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 4 }}>
                            <button
                                type="button"
                                data-testid="kb-time-machine-load-older"
                                data-state={loadOlder.isPending ? 'loading' : olderError ? 'error' : 'idle'}
                                aria-busy={loadOlder.isPending}
                                disabled={loadOlder.isPending}
                                onClick={() => loadOlder.mutate()}
                                style={pill(false)}
                            >
                                {loadOlder.isPending ? 'Loading older versions…' : `Load older versions (${familyTotal - versions.length} more)`}
                            </button>
                            {olderError && (
                                <span data-testid="kb-time-machine-load-older-error" role="alert" style={{ color: 'var(--err)', fontSize: 12 }}>{olderError}</span>
                            )}
                        </div>
                    )}
                </div>
            )}

            {diffEnabled && (
                <section data-testid="kb-time-machine-diff" data-state={diffState} aria-busy={diff.isLoading} style={{ marginTop: 20 }}>
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
                            <DiffSourceNote fromSource={diff.data.from_source} toSource={diff.data.to_source} fromIntegrity={diff.data.from_integrity} toIntegrity={diff.data.to_integrity} />
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

/**
 * Names the reconstructed side(s) by the pick that selected them — "From" /
 * "To" are chosen independently, so neither is necessarily the newer or the
 * older version and the note never says so.
 */
function indexDiffSides(fromSource: VersionContentSource, toSource: VersionContentSource): string {
    if (fromSource !== 'artifact' && toSource !== 'artifact') {
        return 'both the From and the To side are';
    }
    return fromSource !== 'artifact' ? 'the From side is' : 'the To side is';
}

/**
 * v8.36 / ADR 0030 §5 — says whether the diff compares the stored documents
 * (faithful) or chunk reconstructions (an index diff), so an operator never
 * reads an index diff as the document's own history. Older servers omit the
 * sources: then nothing is claimed either way.
 */
function DiffSourceNote({ fromSource, toSource, fromIntegrity, toIntegrity }: { fromSource?: VersionContentSource; toSource?: VersionContentSource; fromIntegrity?: VersionContentIntegrity; toIntegrity?: VersionContentIntegrity }): ReactNode {
    if (!fromSource || !toSource) {
        return null;
    }
    const stored = fromSource === 'artifact' && toSource === 'artifact';
    // A "faithful" claim is a VERIFIED claim (ADR 0030 §5): both sides read
    // from their stored document AND both hashed to the recorded
    // content_hash. A stored document with no hash to check against (a
    // legacy pointer, `integrity: null`) is compared, but not vouched for.
    const faithful = stored && fromIntegrity === 'verified' && toIntegrity === 'verified';
    const state: 'faithful' | 'unverified' | 'index' = faithful ? 'faithful' : stored ? 'unverified' : 'index';
    return (
        <p
            data-testid="kb-time-machine-diff-source"
            data-diff-faithful={faithful ? 'true' : 'false'}
            data-diff-state={state}
            style={{ fontSize: 11.5, color: faithful ? 'var(--ok, #3fb950)' : state === 'unverified' ? 'var(--warn, #d29922)' : 'var(--fg-3)', margin: '0 0 6px' }}
        >
            {state === 'faithful'
                ? 'Faithful diff — both versions compared from their stored documents, verified against their recorded hashes.'
                : state === 'unverified'
                    ? `Stored documents compared, but not verified — ${unverifiedSides(fromIntegrity, toIntegrity)} no recorded hash to check against.`
                    : `Index diff — ${indexDiffSides(fromSource, toSource)} reconstructed from indexed chunks, not the stored document.`}
        </p>
    );
}

function unverifiedSides(fromIntegrity?: VersionContentIntegrity, toIntegrity?: VersionContentIntegrity): string {
    const from = fromIntegrity !== 'verified';
    const to = toIntegrity !== 'verified';
    if (from && to) {
        return 'both the From and the To side have';
    }
    return from ? 'the From side has' : 'the To side has';
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
            data-has-artifact={v.has_artifact ? 'true' : 'false'}
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
            {/* v8.36 / ADR 0030 §4 — who created the version and why; null on rows that predate it */}
            <span data-testid={`kb-time-machine-version-${v.id}-actor`} title={v.version_reason ?? undefined} style={{ fontSize: 11, color: 'var(--fg-3)', fontFamily: 'var(--font-mono, monospace)' }}>
                {v.version_actor ?? 'unknown actor'}
                {v.version_reason ? ` · ${v.version_reason}` : ''}
                {v.restored_by ? ` · restored by ${v.restored_by}` : ''}
            </span>
            {v.has_artifact && (
                <span data-testid={`kb-time-machine-version-${v.id}-artifact`} title="The converted document of this version is stored and diffs are faithful" style={{ fontSize: 10.5, padding: '1px 6px', borderRadius: 999, border: '1px solid var(--ok, #3fb950)', color: 'var(--ok, #3fb950)' }}>
                    stored
                </span>
            )}
            {(v.artifact_state === 'missing' || v.artifact_state === 'mismatch') && (
                <span
                    data-testid={`kb-time-machine-version-${v.id}-artifact-warning`}
                    data-artifact-state={v.artifact_state}
                    role="img"
                    aria-label={v.artifact_state === 'missing' ? 'Stored document missing: diffs fall back to the index' : 'Stored document does not match its recorded hash: diffs fall back to the index'}
                    title={v.artifact_state === 'missing' ? 'The stored document behind this version is missing; kb:artifacts-backfill repairs it' : 'The stored document behind this version does not match its recorded hash; kb:artifacts-backfill repairs it'}
                    style={{ fontSize: 10.5, padding: '1px 6px', borderRadius: 999, border: '1px solid var(--warn, #d29922)', color: 'var(--warn, #d29922)' }}
                >
                    {v.artifact_state === 'missing' ? 'artifact missing' : 'artifact mismatch'}
                </span>
            )}
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
