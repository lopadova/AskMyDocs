import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { toAdminError } from '../shared/errors';
import type { AdminProject } from '../projects/admin-projects.api';
import { AccountMetaForm, type AccountMetaFormValues } from './AccountMetaForm';
import { ConnectionSettingsForm } from './ConnectionSettingsForm';
import { CredentialConnectorForm } from './CredentialConnectorForm';
import { SourceAvatar } from './SourceAvatar';
import { StatusBadge } from './connections-shared';
import { SyncSettingsForm } from './SyncSettingsForm';
import { buildConnections } from './connection-vm';
import { useInstallationExport, useInstallationStats } from './connectors-hooks';
import type { ConfigureConnectorPayload, ConnectorEntry, ConnectorInstallationDto } from './connectors.api';
import { formatRelative } from './status-utils';

/**
 * v8.29 — the tabbed Edit modal for a connector account. v8.31 redesigned per the
 * "Config Modals" design handoff: a larger dialog with a STICKY header (source
 * avatar + account + live status badge), underline tabs, a scrollable body, and
 * ONE sticky footer ("Changes apply on next sync." + Cancel + Save) that submits
 * the active tab's form via the `form` attribute (each tab form is `footerless`).
 *
 * Three tabs over the SAME account:
 *   - Details   : label + KB project binding (PATCH metadata) + at-a-glance stats
 *                 (documents synced · last sync — lazily fetched on open).
 *   - Connection: host / port / username / encryption + optional new password
 *     (reconfigure) — CREDENTIAL connectors only. Prefilled from the account's
 *     secret-free export; a blank password keeps the current one.
 *   - Settings  : the sync-settings editor. Folder-shaped connectors (IMAP) get
 *     the opinionated tri-state {@see SyncSettingsForm}; any other credential
 *     connector falls back to the generic {@see ConnectionSettingsForm}.
 *
 * The modal owns ONE submit/error surface (reset on tab switch) and closes on a
 * successful save; the per-tab forms throw on failure and the modal renders the
 * parsed message inline (R14 — never a silent failure).
 *
 * R15: role=dialog + aria-modal; a real tablist (role=tab/tabpanel, aria-selected);
 *      Esc + backdrop close. R29: `connector-account-{id}-edit-*` testids.
 */

export type EditTab = 'details' | 'connection' | 'settings';

export interface AccountEditModalProps {
    entry: ConnectorEntry;
    account: ConnectorInstallationDto;
    projects: AdminProject[];
    initialTab?: EditTab;
    onSubmitDetails: (values: AccountMetaFormValues) => Promise<void>;
    onSubmitConnection: (payload: ConfigureConnectorPayload) => Promise<void>;
    onTestConnection: (payload: ConfigureConnectorPayload) => Promise<{ ok: boolean; error?: string | null }>;
    onSubmitSettings: (settings: Record<string, unknown>) => Promise<void>;
    onClose: () => void;
    now?: Date;
}

function parseError(e: unknown): { message: string; fieldErrors: Record<string, string> } {
    const base = toAdminError(e);
    const connectorError = (e as { response?: { data?: { error?: string } } })?.response?.data?.error;
    return { message: connectorError ?? base.message, fieldErrors: base.fieldErrors };
}

export function AccountEditModal({
    entry,
    account,
    projects,
    initialTab = 'details',
    onSubmitDetails,
    onSubmitConnection,
    onTestConnection,
    onSubmitSettings,
    onClose,
    now,
}: AccountEditModalProps): ReactNode {
    const isCredential = entry.auth_kind === 'credential';
    // The Connection tab is credential-only; guard an out-of-range initialTab.
    const [tab, setTab] = useState<EditTab>(
        initialTab === 'connection' && !isCredential ? 'details' : initialTab,
    );
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

    // Clear the shared submit/error surface whenever the active tab changes.
    useEffect(() => {
        setError(null);
        setFieldErrors({});
    }, [tab]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    // Prefill the Connection tab from the account's secret-free export — only fetch
    // while that tab is actually open (lazy).
    const exportQuery = useInstallationExport(account.id, isCredential && tab === 'connection');
    const connInitial = useMemo(() => exportQuery.data?.params ?? {}, [exportQuery.data]);

    async function runSubmit(fn: () => Promise<void>): Promise<void> {
        setSubmitting(true);
        setError(null);
        setFieldErrors({});
        try {
            await fn();
            onClose();
        } catch (e) {
            const parsed = parseError(e);
            setError(parsed.message);
            setFieldErrors(parsed.fieldErrors);
        } finally {
            setSubmitting(false);
        }
    }

    const tabs: Array<{ id: EditTab; label: string; show: boolean }> = [
        { id: 'details', label: 'Details', show: true },
        { id: 'connection', label: 'Connection', show: isCredential },
        { id: 'settings', label: 'Sync settings', show: isCredential },
    ];

    const idBase = `connector-account-${account.id}-edit`;
    const titleId = `${idBase}-title`;
    const formId = `${idBase}-form`;
    // The Connection tab has no submittable form until the prefill export resolves;
    // disable the footer Save in that window (and while any submit is in flight).
    const connectionNotReady = tab === 'connection' && (exportQuery.isLoading || exportQuery.isError);
    // A folder-shaped schema (IMAP) gets the tri-state settings editor; anything
    // else falls back to the generic schema-driven form.
    const folderShaped = (account.connection_settings_schema ?? []).some((f) => f.discovery === 'folders');

    return (
        <div
            data-testid={`${idBase}-backdrop`}
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
            style={{
                position: 'fixed',
                inset: 0,
                background: 'rgba(4,5,7,.6)',
                backdropFilter: 'blur(3px)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                padding: 24,
                zIndex: 100,
            }}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                data-testid={`${idBase}-modal`}
                data-active-tab={tab}
                style={{
                    width: 600,
                    maxWidth: '100%',
                    height: 640,
                    maxHeight: '88vh',
                    display: 'flex',
                    flexDirection: 'column',
                    background: 'var(--panel-solid)',
                    border: '1px solid var(--panel-border-strong)',
                    borderRadius: 16,
                    boxShadow: 'var(--shadow-lg)',
                    overflow: 'hidden',
                }}
            >
                {/* ── Sticky header ─────────────────────────────────────────── */}
                <div style={{ flex: 'none', padding: '18px 22px 0', borderBottom: '1px solid var(--hairline)' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                        <SourceAvatar
                            connectorKey={entry.key}
                            displayName={entry.display_name}
                            iconUrl={entry.icon_url}
                            size={38}
                            radius={10}
                        />
                        <div style={{ flex: 1, minWidth: 0 }}>
                            <h2 id={titleId} style={{ margin: 0, fontSize: 16.5, fontWeight: 600, color: 'var(--fg-0)', lineHeight: 1.15 }}>
                                Edit connection
                            </h2>
                            <div style={{ fontSize: 12.5, color: 'var(--fg-3)', marginTop: 1 }}>
                                {entry.display_name} ·{' '}
                                <span style={{ fontFamily: 'var(--font-mono)', color: 'var(--fg-1)' }}>
                                    {account.label}
                                </span>
                            </div>
                        </div>
                        <StatusBadge
                            vm={buildConnections([{ ...entry, installations: [account] }])[0]}
                            testid={`${idBase}-status`}
                        />
                        <button
                            type="button"
                            data-testid={`${idBase}-close`}
                            aria-label="Close"
                            className="amd-cn-menu-btn focus-ring"
                            onClick={onClose}
                            style={{
                                flex: 'none',
                                width: 32,
                                height: 32,
                                borderRadius: 8,
                                border: '1px solid transparent',
                                background: 'transparent',
                                color: 'var(--fg-2)',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                cursor: 'pointer',
                            }}
                        >
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                <path d="M18 6 6 18" />
                                <path d="M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div role="tablist" aria-label="Edit sections" style={{ display: 'flex', gap: 2, marginTop: 16 }}>
                        {tabs
                            .filter((t) => t.show)
                            .map((t) => {
                                const selected = tab === t.id;
                                return (
                                    <button
                                        key={t.id}
                                        type="button"
                                        role="tab"
                                        id={`${idBase}-tab-${t.id}`}
                                        aria-selected={selected}
                                        aria-controls={`${idBase}-panel`}
                                        data-testid={`${idBase}-tab-${t.id}`}
                                        onClick={() => setTab(t.id)}
                                        style={{
                                            position: 'relative',
                                            background: 'transparent',
                                            border: 'none',
                                            color: selected ? 'var(--fg-0)' : 'var(--fg-3)',
                                            font: 'inherit',
                                            fontSize: 13.5,
                                            fontWeight: 600,
                                            padding: '8px 14px 12px',
                                            cursor: 'pointer',
                                            boxShadow: `inset 0 -2px 0 ${selected ? 'var(--accent-a, #8b5cf6)' : 'transparent'}`,
                                        }}
                                    >
                                        {t.label}
                                    </button>
                                );
                            })}
                    </div>
                </div>

                {/* ── Scrollable body ───────────────────────────────────────── */}
                <div
                    id={`${idBase}-panel`}
                    role="tabpanel"
                    aria-labelledby={`${idBase}-tab-${tab}`}
                    style={{ flex: 1, overflowY: 'auto', padding: '20px 22px 8px' }}
                >
                    {tab === 'details' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                            <AccountMetaForm
                                embedded
                                footerless
                                formId={formId}
                                connectorKey={entry.key}
                                title=""
                                submitLabel="Save changes"
                                projects={projects}
                                initialLabel={account.label}
                                initialProjectKey={account.project_key}
                                onSubmit={(values) => runSubmit(() => onSubmitDetails(values))}
                                onClose={onClose}
                                submitError={error}
                                fieldErrors={fieldErrors}
                                isSubmitting={submitting}
                            />
                            <div style={{ height: 1, background: 'var(--hairline)' }} />
                            <DetailsStats account={account} now={now} />
                        </div>
                    )}

                    {tab === 'connection' && isCredential && (
                        <ConnectionTabBody
                            entry={entry}
                            loading={exportQuery.isLoading}
                            error={exportQuery.isError}
                            onRetry={() => exportQuery.refetch()}
                        >
                            <CredentialConnectorForm
                                key={`conn-${account.id}`}
                                embedded
                                footerless
                                formId={formId}
                                mode="edit"
                                entry={entry}
                                projects={projects}
                                initialValues={connInitial}
                                submitLabel="Save changes"
                                onSubmit={(payload) => runSubmit(() => onSubmitConnection(payload))}
                                onTest={onTestConnection}
                                onClose={onClose}
                                submitError={error}
                                fieldErrors={fieldErrors}
                                isSubmitting={submitting}
                            />
                        </ConnectionTabBody>
                    )}

                    {tab === 'settings' && isCredential && folderShaped && (
                        <SyncSettingsForm
                            footerless
                            formId={formId}
                            connectorKey={entry.key}
                            account={account}
                            onSubmit={(settings) => runSubmit(() => onSubmitSettings(settings))}
                            onClose={onClose}
                            submitError={error}
                            fieldErrors={fieldErrors}
                            isSubmitting={submitting}
                        />
                    )}
                    {tab === 'settings' && isCredential && !folderShaped && (
                        <ConnectionSettingsForm
                            embedded
                            footerless
                            formId={formId}
                            connectorKey={entry.key}
                            account={account}
                            onSubmit={(settings) => runSubmit(() => onSubmitSettings(settings))}
                            onClose={onClose}
                            submitError={error}
                            fieldErrors={fieldErrors}
                            isSubmitting={submitting}
                        />
                    )}
                </div>

                {/* ── Sticky footer ─────────────────────────────────────────── */}
                <div
                    style={{
                        flex: 'none',
                        display: 'flex',
                        alignItems: 'center',
                        gap: 10,
                        padding: '14px 22px',
                        borderTop: '1px solid var(--hairline)',
                        background: 'var(--bg-1)',
                    }}
                >
                    {/* The save-failure message renders IN the active tab body
                        (next to the fields it belongs to) — one error surface, not
                        a duplicate footer copy. */}
                    <span style={{ flex: 1, fontSize: 12, color: 'var(--fg-3)' }}>Changes apply on next sync.</span>
                    <button
                        type="button"
                        data-testid={`${idBase}-cancel`}
                        onClick={onClose}
                        disabled={submitting}
                        style={{
                            border: '1px solid var(--panel-border-strong)',
                            background: 'transparent',
                            color: 'var(--fg-1)',
                            font: 'inherit',
                            fontSize: 13,
                            fontWeight: 600,
                            padding: '9px 16px',
                            borderRadius: 9,
                            cursor: submitting ? 'not-allowed' : 'pointer',
                            opacity: submitting ? 0.6 : 1,
                        }}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        form={formId}
                        data-testid={`${idBase}-save`}
                        disabled={submitting || connectionNotReady}
                        style={{
                            border: 'none',
                            background: 'var(--grad-accent)',
                            color: '#fff',
                            font: 'inherit',
                            fontSize: 13,
                            fontWeight: 600,
                            padding: '9px 18px',
                            borderRadius: 9,
                            cursor: submitting || connectionNotReady ? 'not-allowed' : 'pointer',
                            opacity: submitting || connectionNotReady ? 0.6 : 1,
                            boxShadow: '0 2px 12px rgba(139,92,246,.4)',
                        }}
                    >
                        {submitting ? 'Saving…' : 'Save changes'}
                    </button>
                </div>
            </div>
        </div>
    );
}

/**
 * Details-tab "at a glance" stats (documents synced · last sync), lazily fetched
 * on open. R14 — a load failure is surfaced (not silently blank); the last-sync
 * card falls back to the account's own timestamp so it renders even while stats load.
 */
function DetailsStats({ account, now }: { account: ConnectorInstallationDto; now?: Date }): ReactNode {
    const statsQuery = useInstallationStats(account.id, true);
    const docs = statsQuery.data?.documents_synced;
    const lastSyncIso = statsQuery.data?.last_sync_at ?? account.last_sync_at;
    const lastSync = formatRelative(lastSyncIso, now);

    return (
        <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap' }} data-testid={`connector-account-${account.id}-edit-stats`}>
            <StatCard
                testid={`connector-account-${account.id}-edit-stat-documents`}
                label="Documents synced"
                value={
                    statsQuery.isLoading
                        ? '…'
                        : statsQuery.isError
                          ? '—'
                          : docs != null
                            ? docs.toLocaleString()
                            : '—'
                }
            />
            <StatCard
                testid={`connector-account-${account.id}-edit-stat-last-sync`}
                label="Last sync"
                value={lastSync ?? 'Never'}
            />
        </div>
    );
}

function StatCard({ testid, label, value }: { testid: string; label: string; value: string }): ReactNode {
    return (
        <div
            data-testid={testid}
            style={{
                flex: 1,
                minWidth: 150,
                background: 'var(--bg-0)',
                border: '1px solid var(--hairline)',
                borderRadius: 10,
                padding: '12px 14px',
            }}
        >
            <div style={{ fontSize: 11.5, color: 'var(--fg-3)', textTransform: 'uppercase', letterSpacing: '.04em' }}>
                {label}
            </div>
            <div style={{ fontSize: 20, fontWeight: 700, marginTop: 4, color: 'var(--fg-0)' }}>{value}</div>
        </div>
    );
}

interface ConnectionTabBodyProps {
    entry: ConnectorEntry;
    loading: boolean;
    error: boolean;
    onRetry: () => void;
    children: ReactNode;
}

/**
 * Gates the Connection form on the export prefill fetch — a loading spinner while
 * it resolves, a loud error + retry if it fails (R14: never render the form seeded
 * with empty defaults that would silently overwrite the stored connection params).
 */
function ConnectionTabBody({ entry, loading, error, onRetry, children }: ConnectionTabBodyProps): ReactNode {
    if (loading) {
        return (
            <div
                data-testid={`connector-${entry.key}-connection-prefill-loading`}
                role="status"
                aria-busy="true"
                style={boxStyle()}
            >
                Loading current connection…
            </div>
        );
    }
    if (error) {
        return (
            <div
                data-testid={`connector-${entry.key}-connection-prefill-error`}
                role="alert"
                style={{ ...boxStyle(), color: '#fca5a5' }}
            >
                Could not load the current connection parameters.{' '}
                <button
                    type="button"
                    data-testid={`connector-${entry.key}-connection-prefill-retry`}
                    onClick={onRetry}
                    style={{
                        marginLeft: 8,
                        padding: '3px 10px',
                        fontSize: 11,
                        background: 'transparent',
                        color: 'inherit',
                        border: '1px solid currentColor',
                        borderRadius: 6,
                        cursor: 'pointer',
                    }}
                >
                    Retry
                </button>
            </div>
        );
    }
    return <>{children}</>;
}

function boxStyle(): React.CSSProperties {
    return {
        padding: 16,
        textAlign: 'center',
        color: 'var(--fg-3)',
        fontSize: 12,
        border: '1px dashed var(--hairline)',
        borderRadius: 8,
    };
}
