import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { EditorState } from '@codemirror/state';
import { EditorView, keymap, lineNumbers } from '@codemirror/view';
import { markdown } from '@codemirror/lang-markdown';
import { useKbRaw, useUpdateKbRaw } from './kb-document.api';
import { useToast } from '../shared/Toast';
import type { KbUpdateRawErrorShape } from '../admin.api';

/*
 * Phase G3 — Source tab. Minimal CodeMirror 6 bundle (state + view +
 * lang-markdown only; no basic-setup), a Save / Cancel / Diff toolbar,
 * and a hand-rolled line-by-line diff panel.
 *
 * Failure surfaces are testid-addressable (R11):
 *   - kb-editor-error            — generic 4xx / 5xx
 *   - kb-editor-error-frontmatter— per-key 422 from CanonicalParser
 * On 422 the per-key messages render as a list so operators can fix
 * field-by-field before retrying.
 *
 * The editor's CodeMirror instance writes into a ref-held `buffer`;
 * the render tree only reads `isDirty` so typing doesn't re-render the
 * editor on every keystroke (a fresh `EditorView.update` would reset
 * the cursor). Save publishes the current buffer via useUpdateKbRaw().
 */

export interface SourceTabProps {
    documentId: number;
}

interface FrontmatterErrorBag {
    [key: string]: string[];
}

export function SourceTab({ documentId }: SourceTabProps) {
    const raw = useKbRaw(documentId);
    const mutation = useUpdateKbRaw(documentId);
    const toast = useToast();

    const hostRef = useRef<HTMLDivElement | null>(null);
    const viewRef = useRef<EditorView | null>(null);
    const bufferRef = useRef<string>('');
    const savedRef = useRef<string>('');

    const [isDirty, setIsDirty] = useState(false);
    const [showDiff, setShowDiff] = useState(false);
    const [frontmatterErrors, setFrontmatterErrors] =
        useState<FrontmatterErrorBag | null>(null);
    const [genericError, setGenericError] = useState<string | null>(null);

    // Reset saved baseline whenever the raw document changes (id swap or
    // post-save cache invalidation bringing a fresh content_hash).
    //
    // Copilot #3 fix: before this, we only updated the `savedRef` /
    // `bufferRef` + flipped `isDirty=false` when the raw content
    // changed — but the `EditorView`'s internal doc state was seeded
    // once at mount and NEVER refreshed. After a save-triggered
    // refetch (or a docId swap), the editor could display stale
    // bytes while `isDirty=false` lied "nothing to save". Pressing
    // Save then would have posted the OLD content back, overwriting
    // the fresh version. Dispatch a full-doc replacement into the
    // existing view so the UI state, `bufferRef`, and CodeMirror
    // document stay in lockstep.
    useEffect(() => {
        if (!raw.data) return;
        const nextContent = raw.data.content;
        savedRef.current = nextContent;
        bufferRef.current = nextContent;
        setIsDirty(false);

        const view = viewRef.current;
        if (view && view.state.doc.toString() !== nextContent) {
            view.dispatch({
                changes: {
                    from: 0,
                    to: view.state.doc.length,
                    insert: nextContent,
                },
            });
        }
    }, [raw.data?.content_hash]);

    // Mount CodeMirror once the host div exists + raw content has loaded.
    useEffect(() => {
        if (!hostRef.current || !raw.data) return;
        if (viewRef.current) return; // already mounted

        const startDoc = raw.data.content;
        const state = EditorState.create({
            doc: startDoc,
            extensions: [
                lineNumbers(),
                EditorView.lineWrapping,
                markdown(),
                EditorView.updateListener.of((update) => {
                    if (!update.docChanged) return;
                    const next = update.state.doc.toString();
                    bufferRef.current = next;
                    setIsDirty(next !== savedRef.current);
                }),
                keymap.of([]),
            ],
        });
        viewRef.current = new EditorView({
            state,
            parent: hostRef.current,
        });
        bufferRef.current = startDoc;
        savedRef.current = startDoc;

        return () => {
            viewRef.current?.destroy();
            viewRef.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [raw.data !== undefined]);

    const resetEditorTo = useCallback((value: string) => {
        const view = viewRef.current;
        if (!view) return;
        view.dispatch({
            changes: { from: 0, to: view.state.doc.length, insert: value },
        });
        bufferRef.current = value;
    }, []);

    const handleCancel = useCallback(() => {
        resetEditorTo(savedRef.current);
        setIsDirty(false);
        setFrontmatterErrors(null);
        setGenericError(null);
    }, [resetEditorTo]);

    const handleSave = useCallback(() => {
        if (!isDirty || mutation.isPending) return;
        setFrontmatterErrors(null);
        setGenericError(null);
        const payload = bufferRef.current;
        mutation.mutate(payload, {
            onSuccess: () => {
                savedRef.current = payload;
                setIsDirty(false);
                toast.success('Saved — re-ingest queued', 'toast-success');
            },
            onError: (err: unknown) => {
                // axios AxiosError shape — narrow defensively rather than
                // importing AxiosError (Axios is a peer type on this
                // module and swapping it out shouldn't break the branch).
                const anyErr = err as {
                    response?: {
                        status?: number;
                        data?: KbUpdateRawErrorShape;
                    };
                    message?: string;
                };
                const status = anyErr.response?.status ?? 0;
                const data = anyErr.response?.data;
                if (status === 422 && data?.errors?.frontmatter) {
                    setFrontmatterErrors(data.errors.frontmatter);
                    toast.error('Frontmatter validation failed', 'toast-error');
                    return;
                }
                const msg =
                    data?.message ??
                    anyErr.message ??
                    'Failed to save the markdown.';
                setGenericError(msg);
                toast.error(msg, 'toast-error');
            },
        });
    }, [isDirty, mutation, toast]);

    const diffRows = useMemo(
        () =>
            showDiff
                ? computeLineDiff(savedRef.current, bufferRef.current)
                : [],
        // Re-run whenever the dirty-flag flips or the diff panel opens;
        // using bufferRef directly as a dependency wouldn't trigger a
        // re-render on keystrokes (which is by design — diff is
        // calculated on demand, not live).
        [showDiff, isDirty, raw.data?.content_hash],
    );

    if (raw.isLoading) {
        return (
            <div
                data-testid="kb-source"
                data-state="loading"
                aria-busy="true"
                style={{ padding: 12, color: 'var(--fg-3)' }}
            >
                Loading source…
            </div>
        );
    }

    if (raw.isError || !raw.data) {
        return (
            <div
                data-testid="kb-source"
                data-state="error"
                style={{
                    padding: 12,
                    color: 'var(--danger-fg, #b91c1c)',
                    fontSize: 12.5,
                }}
            >
                Could not load the markdown. The file may be missing on disk.
            </div>
        );
    }

    return (
        <div
            data-testid="kb-source"
            data-state="ready"
            data-dirty={isDirty ? 'true' : 'false'}
            style={{ display: 'flex', flexDirection: 'column', gap: 10, minHeight: 0 }}
        >
            <div
                data-testid="kb-editor-toolbar"
                style={{
                    display: 'flex',
                    gap: 6,
                    alignItems: 'center',
                    padding: 6,
                    border: '1px solid var(--hairline)',
                    borderRadius: 8,
                    background: 'var(--bg-0)',
                }}
            >
                <ToolbarButton
                    testid="kb-editor-save"
                    label={mutation.isPending ? 'Saving…' : 'Save'}
                    onClick={handleSave}
                    disabled={!isDirty || mutation.isPending}
                    primary
                />
                <ToolbarButton
                    testid="kb-editor-cancel"
                    label="Cancel"
                    onClick={handleCancel}
                    disabled={!isDirty}
                />
                <ToolbarButton
                    testid="kb-editor-diff"
                    label={showDiff ? 'Hide diff' : 'Show diff'}
                    onClick={() => setShowDiff((v) => !v)}
                />
                <span
                    data-testid="kb-editor-dirty-indicator"
                    style={{
                        marginLeft: 'auto',
                        fontSize: 11,
                        color: isDirty ? 'var(--accent-fg)' : 'var(--fg-3)',
                        fontFamily: 'var(--font-mono)',
                    }}
                >
                    {isDirty ? 'unsaved changes' : 'in sync with disk'}
                </span>
            </div>

            {genericError !== null ? (
                <div
                    data-testid="kb-editor-error"
                    style={{
                        padding: '8px 10px',
                        border: '1px solid var(--danger-fg, #b91c1c)',
                        background: 'var(--danger-soft, rgba(220, 38, 38, 0.08))',
                        color: 'var(--danger-fg, #b91c1c)',
                        fontSize: 12.5,
                        borderRadius: 8,
                    }}
                >
                    {genericError}
                </div>
            ) : null}

            {frontmatterErrors !== null ? (
                <div
                    data-testid="kb-editor-error-frontmatter"
                    style={{
                        padding: '8px 10px',
                        border: '1px solid var(--danger-fg, #b91c1c)',
                        background: 'var(--danger-soft, rgba(220, 38, 38, 0.08))',
                        color: 'var(--danger-fg, #b91c1c)',
                        fontSize: 12.5,
                        borderRadius: 8,
                        display: 'flex',
                        flexDirection: 'column',
                        gap: 4,
                    }}
                >
                    <strong style={{ fontSize: 12, letterSpacing: '0.04em' }}>
                        Invalid frontmatter
                    </strong>
                    <ul style={{ margin: 0, paddingLeft: 18 }}>
                        {Object.entries(frontmatterErrors).map(([key, msgs]) => (
                            <li
                                key={key}
                                data-testid={`kb-editor-error-${key}`}
                                style={{ fontFamily: 'var(--font-mono)', fontSize: 12 }}
                            >
                                <strong>{key}</strong>: {msgs.join('; ')}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            <div
                ref={hostRef}
                data-testid="kb-editor-cm"
                style={{
                    flex: 1,
                    minHeight: 320,
                    border: '1px solid var(--hairline)',
                    borderRadius: 8,
                    background: 'var(--bg-0)',
                    fontFamily: 'var(--font-mono)',
                    fontSize: 12.5,
                    overflow: 'auto',
                }}
            />

            {showDiff ? (
                <div
                    data-testid="kb-editor-diff-panel"
                    style={{
                        border: '1px solid var(--hairline)',
                        borderRadius: 8,
                        background: 'var(--bg-0)',
                        padding: 8,
                        maxHeight: 260,
                        overflow: 'auto',
                        fontFamily: 'var(--font-mono)',
                        fontSize: 11.5,
                    }}
                >
                    <div
                        style={{
                            display: 'grid',
                            gridTemplateColumns: '1fr 1fr',
                            gap: 6,
                        }}
                    >
                        <DiffColumn
                            title="On disk"
                            rows={diffRows.map((r) => ({
                                kind: r.left === null ? 'empty' : r.kind,
                                text: r.left ?? '',
                            }))}
                            testidPrefix="kb-editor-diff-left"
                        />
                        <DiffColumn
                            title="Buffer"
                            rows={diffRows.map((r) => ({
                                kind: r.right === null ? 'empty' : r.kind,
                                text: r.right ?? '',
                            }))}
                            testidPrefix="kb-editor-diff-right"
                        />
                    </div>
                </div>
            ) : null}
        </div>
    );
}

function ToolbarButton({
    testid,
    label,
    onClick,
    disabled,
    primary,
}: {
    testid: string;
    label: string;
    onClick: () => void;
    disabled?: boolean;
    primary?: boolean;
}) {
    return (
        <button
            type="button"
            data-testid={testid}
            onClick={onClick}
            disabled={disabled}
            style={{
                padding: '5px 12px',
                fontSize: 12,
                border: '1px solid ' + (primary ? 'var(--accent)' : 'var(--hairline)'),
                background: primary ? 'var(--grad-accent-soft)' : 'var(--bg-1)',
                color: primary ? 'var(--fg-0)' : 'var(--fg-1)',
                borderRadius: 6,
                cursor: disabled ? 'not-allowed' : 'pointer',
                opacity: disabled ? 0.55 : 1,
                fontWeight: primary ? 600 : 400,
            }}
        >
            {label}
        </button>
    );
}

interface DiffRow {
    kind: 'equal' | 'changed' | 'added' | 'removed' | 'empty';
    left: string | null;
    right: string | null;
}

/**
 * Dead-simple line-by-line diff. Not LCS-smart — just pairs up lines
 * at the same index and flags the ones that disagree. This is enough
 * for the "did I change what I think I changed" audit before a Save
 * click; a real structural diff would pull in a dependency which is
 * overkill for a single tab.
 */
export function computeLineDiff(left: string, right: string): DiffRow[] {
    const leftLines = left.split('\n');
    const rightLines = right.split('\n');
    const max = Math.max(leftLines.length, rightLines.length);
    const rows: DiffRow[] = [];
    for (let i = 0; i < max; i++) {
        const l = i < leftLines.length ? leftLines[i] : null;
        const r = i < rightLines.length ? rightLines[i] : null;
        let kind: DiffRow['kind'];
        if (l === null) {
            kind = 'added';
        } else if (r === null) {
            kind = 'removed';
        } else if (l === r) {
            kind = 'equal';
        } else {
            kind = 'changed';
        }
        rows.push({ kind, left: l, right: r });
    }
    return rows;
}

function DiffColumn({
    title,
    rows,
    testidPrefix,
}: {
    title: string;
    rows: Array<{ kind: DiffRow['kind']; text: string }>;
    testidPrefix: string;
}) {
    return (
        <div>
            <div
                style={{
                    fontSize: 10,
                    textTransform: 'uppercase',
                    letterSpacing: '0.06em',
                    color: 'var(--fg-3)',
                    marginBottom: 4,
                }}
            >
                {title}
            </div>
            <div style={{ display: 'flex', flexDirection: 'column' }}>
                {rows.map((row, idx) => {
                    const bg =
                        row.kind === 'changed'
                            ? 'rgba(234,179,8,0.10)'
                            : row.kind === 'added'
                              ? 'rgba(16,185,129,0.10)'
                              : row.kind === 'removed'
                                ? 'rgba(239,68,68,0.10)'
                                : 'transparent';
                    return (
                        <div
                            key={idx}
                            data-testid={`${testidPrefix}-${idx}`}
                            data-kind={row.kind}
                            style={{
                                background: bg,
                                padding: '0 4px',
                                whiteSpace: 'pre',
                                borderLeft:
                                    row.kind === 'changed' || row.kind === 'added' || row.kind === 'removed'
                                        ? '2px solid ' +
                                          (row.kind === 'removed'
                                              ? 'var(--danger-fg, #b91c1c)'
                                              : row.kind === 'added'
                                                ? 'var(--accent)'
                                                : 'var(--warning-fg, #b45309)')
                                        : '2px solid transparent',
                            }}
                        >
                            {row.text || ' '}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
