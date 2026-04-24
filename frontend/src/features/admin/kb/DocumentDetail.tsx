import { useState } from 'react';
import { Icon } from '../../../components/Icons';
import { adminKbDocumentApi, type KbDocument } from '../admin.api';
import {
    useDeleteKbDocument,
    useKbDocument,
    useRestoreKbDocument,
} from './kb-document.api';
import { PreviewTab } from './PreviewTab';
import { MetaTab } from './MetaTab';
import { HistoryTab } from './HistoryTab';

/*
 * Phase G2 — KB document detail pane.
 *
 * Three tabs only at this phase: Preview / Meta / History. Source
 * editor (CodeMirror) lands in G3; Graph viewer + PDF Export land
 * in G4. The `activeTab` + `tab` URL search param are kept in
 * lockstep so deep-links reopen the correct pane.
 *
 * Destructive actions (delete / force-delete) go through a
 * controlled confirmation dialog so R11 (testids) stay stable
 * for Playwright.
 */

export type KbDetailTab = 'preview' | 'meta' | 'history';

export interface DocumentDetailProps {
    documentId: number;
    activeTab: KbDetailTab;
    onTabChange: (next: KbDetailTab) => void;
    /** Called after a successful destructive op (delete / force-delete). */
    onDeleted?: () => void;
}

export function DocumentDetail(props: DocumentDetailProps) {
    const { documentId, activeTab, onTabChange, onDeleted } = props;

    const query = useKbDocument(documentId);
    const restoreMut = useRestoreKbDocument();
    const deleteMut = useDeleteKbDocument();

    const [confirm, setConfirm] = useState<null | { mode: 'soft' | 'force' }>(null);

    if (query.isLoading) {
        // Copilot #4 fix: keep the `kb-detail` root element stable
        // across all three states (loading / error / ready) so the
        // observable-state contract (data-state + aria-busy) holds
        // regardless of the fetch phase. Playwright can now wait on
        // `kb-detail` without a branch; screen readers announce the
        // busy state during the initial fetch.
        return (
            <div
                data-testid="kb-detail"
                data-state="loading"
                aria-busy="true"
                style={{ padding: 16, color: 'var(--fg-3)' }}
            >
                <div data-testid="kb-detail-loading">Loading document…</div>
            </div>
        );
    }

    if (query.isError || !query.data) {
        return (
            <div
                data-testid="kb-detail"
                data-state="error"
                aria-busy="false"
                style={{ padding: 16, color: 'var(--danger-fg, #b91c1c)' }}
            >
                <div data-testid="kb-detail-error">
                    Could not load the document (id #{documentId}).
                </div>
            </div>
        );
    }

    const doc = query.data;
    // `aria-busy` flips back on during mutations so assistive tech
    // announces in-flight restore/delete requests.
    const isBusy = restoreMut.isPending || deleteMut.isPending || query.isFetching;

    function handleRestore() {
        restoreMut.mutate(documentId);
    }

    function handleDelete() {
        if (confirm === null) return;
        deleteMut.mutate(
            { id: documentId, force: confirm.mode === 'force' },
            {
                onSuccess: () => {
                    setConfirm(null);
                    onDeleted?.();
                },
            },
        );
    }

    return (
        <div
            data-testid="kb-detail"
            data-state="ready"
            data-doc-id={doc.id}
            aria-busy={isBusy ? 'true' : 'false'}
            style={{ display: 'flex', flexDirection: 'column', gap: 12, minHeight: 0, flex: 1 }}
        >
            <DocHeader
                doc={doc}
                onRestore={handleRestore}
                onAskDelete={(mode) => setConfirm({ mode })}
                isRestoring={restoreMut.isPending}
            />

            <TabStrip activeTab={activeTab} onTabChange={onTabChange} />

            <div
                style={{
                    flex: 1,
                    minHeight: 0,
                    overflow: 'auto',
                    padding: 14,
                    border: '1px solid var(--hairline)',
                    borderRadius: 10,
                    background: 'var(--bg-1)',
                }}
            >
                {activeTab === 'preview' ? (
                    <PreviewTab documentId={doc.id} project={doc.project_key} />
                ) : null}
                {activeTab === 'meta' ? <MetaTab doc={doc} /> : null}
                {activeTab === 'history' ? <HistoryTab documentId={doc.id} /> : null}
            </div>

            {confirm !== null ? (
                <ConfirmDialog
                    mode={confirm.mode}
                    isSubmitting={deleteMut.isPending}
                    onCancel={() => setConfirm(null)}
                    onConfirm={handleDelete}
                />
            ) : null}
        </div>
    );
}

function DocHeader({
    doc,
    onRestore,
    onAskDelete,
    isRestoring,
}: {
    doc: KbDocument;
    onRestore: () => void;
    onAskDelete: (mode: 'soft' | 'force') => void;
    isRestoring: boolean;
}) {
    const trashed = doc.deleted_at !== null;
    return (
        <div
            data-testid="kb-detail-header"
            style={{
                display: 'flex',
                flexDirection: 'column',
                gap: 8,
                padding: 14,
                border: '1px solid var(--hairline)',
                borderRadius: 10,
                background: 'var(--bg-1)',
            }}
        >
            <div
                style={{
                    fontSize: 11,
                    color: 'var(--fg-3)',
                    fontFamily: 'var(--font-mono)',
                }}
            >
                {doc.source_path}
            </div>
            <div style={{ display: 'flex', gap: 10, alignItems: 'baseline', flexWrap: 'wrap' }}>
                <h2
                    data-testid="kb-detail-title"
                    style={{
                        margin: 0,
                        fontSize: 18,
                        fontWeight: 600,
                        letterSpacing: '-0.02em',
                        color: 'var(--fg-0)',
                    }}
                >
                    {doc.title ?? doc.source_path}
                </h2>
                {doc.canonical_type ? (
                    <Pill
                        testId="kb-detail-type-pill"
                        label={doc.canonical_type}
                        variant="accent"
                    />
                ) : null}
                {doc.canonical_status ? (
                    <Pill
                        testId="kb-detail-status-pill"
                        label={doc.canonical_status}
                    />
                ) : null}
                {trashed ? (
                    <Pill testId="kb-detail-trashed-badge" label="trashed" variant="danger" />
                ) : null}
            </div>

            <div
                style={{
                    display: 'flex',
                    gap: 8,
                    flexWrap: 'wrap',
                    paddingTop: 4,
                }}
            >
                <HeaderLink
                    testId="kb-action-download"
                    href={adminKbDocumentApi.downloadUrl(doc.id)}
                    label="Download"
                    icon={<Icon.File size={13} />}
                />
                <HeaderLink
                    testId="kb-action-print"
                    href={adminKbDocumentApi.printUrl(doc.id)}
                    label="Print"
                    icon={<Icon.File size={13} />}
                />
                {trashed ? (
                    <HeaderButton
                        testId="kb-action-restore"
                        label={isRestoring ? 'Restoring…' : 'Restore'}
                        onClick={onRestore}
                        disabled={isRestoring}
                    />
                ) : (
                    <HeaderButton
                        testId="kb-action-delete"
                        label="Delete"
                        onClick={() => onAskDelete('soft')}
                        variant="soft-danger"
                    />
                )}
                <HeaderButton
                    testId="kb-action-force-delete"
                    label="Force delete"
                    onClick={() => onAskDelete('force')}
                    variant="danger"
                />
            </div>
        </div>
    );
}

function TabStrip({
    activeTab,
    onTabChange,
}: {
    activeTab: KbDetailTab;
    onTabChange: (next: KbDetailTab) => void;
}) {
    const tabs: Array<{ key: KbDetailTab; label: string }> = [
        { key: 'preview', label: 'Preview' },
        { key: 'meta', label: 'Meta' },
        { key: 'history', label: 'History' },
    ];
    return (
        <div
            data-testid="kb-tabs"
            role="tablist"
            style={{
                display: 'flex',
                gap: 4,
                padding: 4,
                border: '1px solid var(--hairline)',
                borderRadius: 10,
                background: 'var(--bg-1)',
                width: 'fit-content',
            }}
        >
            {tabs.map((tab) => {
                const active = tab.key === activeTab;
                return (
                    <button
                        key={tab.key}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        data-testid={`kb-tab-${tab.key}`}
                        data-active={active ? 'true' : 'false'}
                        onClick={() => onTabChange(tab.key)}
                        style={{
                            padding: '6px 14px',
                            fontSize: 12.5,
                            fontWeight: active ? 600 : 400,
                            border: '1px solid ' + (active ? 'var(--accent)' : 'transparent'),
                            background: active ? 'var(--grad-accent-soft)' : 'transparent',
                            color: active ? 'var(--fg-0)' : 'var(--fg-2)',
                            borderRadius: 6,
                            cursor: 'pointer',
                        }}
                    >
                        {tab.label}
                    </button>
                );
            })}
        </div>
    );
}

function Pill({
    testId,
    label,
    variant,
}: {
    testId?: string;
    label: string;
    variant?: 'accent' | 'danger';
}) {
    const palette =
        variant === 'accent'
            ? {
                  bg: 'var(--grad-accent-soft)',
                  fg: 'var(--accent-fg)',
                  border: 'var(--accent)',
              }
            : variant === 'danger'
              ? {
                    bg: 'var(--danger-soft, rgba(220, 38, 38, 0.08))',
                    fg: 'var(--danger-fg, #b91c1c)',
                    border: 'var(--danger-fg, #b91c1c)',
                }
              : {
                    bg: 'var(--bg-0)',
                    fg: 'var(--fg-2)',
                    border: 'var(--hairline)',
                };
    return (
        <span
            data-testid={testId}
            style={{
                padding: '2px 8px',
                borderRadius: 999,
                border: '1px solid ' + palette.border,
                background: palette.bg,
                color: palette.fg,
                fontSize: 11,
                fontFamily: 'var(--font-mono)',
                textTransform: 'uppercase',
                letterSpacing: '0.04em',
            }}
        >
            {label}
        </span>
    );
}

function HeaderLink({
    testId,
    href,
    label,
    icon,
}: {
    testId: string;
    href: string;
    label: string;
    icon?: React.ReactNode;
}) {
    return (
        <a
            data-testid={testId}
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: 6,
                padding: '5px 10px',
                fontSize: 12,
                textDecoration: 'none',
                color: 'var(--fg-1)',
                background: 'var(--bg-0)',
                border: '1px solid var(--hairline)',
                borderRadius: 6,
            }}
        >
            {icon}
            {label}
        </a>
    );
}

function HeaderButton({
    testId,
    label,
    onClick,
    disabled,
    variant,
}: {
    testId: string;
    label: string;
    onClick: () => void;
    disabled?: boolean;
    variant?: 'danger' | 'soft-danger';
}) {
    const palette =
        variant === 'danger'
            ? {
                  bg: 'var(--danger-soft, rgba(220, 38, 38, 0.12))',
                  fg: 'var(--danger-fg, #b91c1c)',
                  border: 'var(--danger-fg, #b91c1c)',
              }
            : variant === 'soft-danger'
              ? {
                    bg: 'var(--bg-0)',
                    fg: 'var(--danger-fg, #b91c1c)',
                    border: 'var(--hairline)',
                }
              : {
                    bg: 'var(--bg-0)',
                    fg: 'var(--fg-1)',
                    border: 'var(--hairline)',
                };
    return (
        <button
            type="button"
            data-testid={testId}
            onClick={onClick}
            disabled={disabled}
            style={{
                padding: '5px 10px',
                fontSize: 12,
                border: '1px solid ' + palette.border,
                background: palette.bg,
                color: palette.fg,
                borderRadius: 6,
                cursor: disabled ? 'not-allowed' : 'pointer',
                opacity: disabled ? 0.6 : 1,
            }}
        >
            {label}
        </button>
    );
}

function ConfirmDialog({
    mode,
    isSubmitting,
    onCancel,
    onConfirm,
}: {
    mode: 'soft' | 'force';
    isSubmitting: boolean;
    onCancel: () => void;
    onConfirm: () => void;
}) {
    const title = mode === 'force' ? 'Force delete document?' : 'Delete document?';
    const body =
        mode === 'force'
            ? 'This permanently removes the row, chunks, graph nodes, and the file on disk. Cannot be undone.'
            : 'The document will be soft-deleted. It can be restored later from the trash view.';
    return (
        <div
            data-testid="kb-detail-confirm"
            data-mode={mode}
            style={{
                position: 'fixed',
                inset: 0,
                background: 'rgba(0, 0, 0, 0.4)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                zIndex: 500,
            }}
        >
            <div
                role="dialog"
                aria-modal="true"
                style={{
                    background: 'var(--bg-0)',
                    border: '1px solid var(--hairline)',
                    borderRadius: 10,
                    padding: 16,
                    width: 420,
                    maxWidth: '90vw',
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 10,
                }}
            >
                <h3 style={{ margin: 0, fontSize: 15, color: 'var(--fg-0)' }}>{title}</h3>
                <p style={{ margin: 0, fontSize: 12.5, color: 'var(--fg-2)', lineHeight: 1.45 }}>{body}</p>
                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 4 }}>
                    <button
                        type="button"
                        data-testid="kb-detail-confirm-cancel"
                        onClick={onCancel}
                        disabled={isSubmitting}
                        style={{
                            padding: '6px 12px',
                            fontSize: 12,
                            border: '1px solid var(--hairline)',
                            background: 'var(--bg-1)',
                            color: 'var(--fg-1)',
                            borderRadius: 6,
                            cursor: 'pointer',
                        }}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        data-testid="kb-detail-confirm-submit"
                        onClick={onConfirm}
                        disabled={isSubmitting}
                        style={{
                            padding: '6px 12px',
                            fontSize: 12,
                            border: '1px solid var(--danger-fg, #b91c1c)',
                            background: 'var(--danger-soft, rgba(220, 38, 38, 0.12))',
                            color: 'var(--danger-fg, #b91c1c)',
                            borderRadius: 6,
                            cursor: isSubmitting ? 'not-allowed' : 'pointer',
                            opacity: isSubmitting ? 0.6 : 1,
                        }}
                    >
                        {isSubmitting ? 'Working…' : mode === 'force' ? 'Force delete' : 'Delete'}
                    </button>
                </div>
            </div>
        </div>
    );
}
