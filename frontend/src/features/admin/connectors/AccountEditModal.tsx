import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { toAdminError } from '../shared/errors';
import type { AdminProject } from '../projects/admin-projects.api';
import { AccountMetaForm, type AccountMetaFormValues } from './AccountMetaForm';
import { CredentialConnectorForm } from './CredentialConnectorForm';
import { ConnectionSettingsForm } from './ConnectionSettingsForm';
import { useInstallationExport } from './connectors-hooks';
import type { ConfigureConnectorPayload, ConnectorEntry, ConnectorInstallationDto } from './connectors.api';

/**
 * v8.29 — the tabbed Edit modal for a connector account. One dialog, three tabs
 * over the SAME account, each reusing an existing form as `embedded` tab content:
 *   - Details   : label + KB project binding (PATCH metadata).
 *   - Connection: host / port / username / encryption + optional new password
 *     (reconfigure) — CREDENTIAL connectors only. Prefilled from the account's
 *     secret-free export (the listing shape hides connection values); the password
 *     is left blank = keep current.
 *   - Settings  : the full sync-settings editor (folders / window / filters).
 *
 * The modal owns ONE submit/error surface at a time (reset on tab switch) and closes
 * on a successful save. The per-tab forms throw on failure; the modal parses the
 * error into an inline message + field errors (R14 — never a silent failure).
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
    // Stable reference so CredentialConnectorForm's re-seed effect doesn't wipe the
    // operator's edits on every render — only when the fetched params change.
    const connInitial = useMemo(
        () => exportQuery.data?.params ?? {},
        [exportQuery.data],
    );

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

    const titleId = `connector-account-${account.id}-edit-title`;

    return (
        <div
            data-testid={`connector-account-${account.id}-edit-backdrop`}
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
            style={{
                position: 'fixed',
                inset: 0,
                background: 'rgba(0,0,0,.4)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                zIndex: 100,
            }}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                data-testid={`connector-account-${account.id}-edit-modal`}
                data-active-tab={tab}
                style={{
                    background: 'var(--panel-solid, #1a1a22)',
                    border: '1px solid var(--panel-border-strong, rgba(255,255,255,.12))',
                    borderRadius: 12,
                    boxShadow: 'var(--shadow, 0 8px 24px rgba(0,0,0,.4))',
                    minWidth: 420,
                    maxWidth: 520,
                    maxHeight: '88vh',
                    padding: 16,
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 12,
                    overflow: 'hidden',
                }}
            >
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 8 }}>
                    <div style={{ flex: 1, minWidth: 0 }}>
                        <h2 id={titleId} style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>
                            Edit {entry.display_name}
                        </h2>
                        <div style={{ fontSize: 11.5, color: 'var(--fg-3)', marginTop: 2 }}>
                            {account.label}
                        </div>
                    </div>
                    <button
                        type="button"
                        data-testid={`connector-account-${account.id}-edit-close`}
                        aria-label="Close"
                        onClick={onClose}
                        style={{
                            background: 'transparent',
                            border: 0,
                            color: 'var(--fg-2)',
                            fontSize: 18,
                            lineHeight: 1,
                            cursor: 'pointer',
                            padding: 2,
                        }}
                    >
                        ×
                    </button>
                </div>

                <div
                    role="tablist"
                    aria-label="Edit sections"
                    style={{ display: 'flex', gap: 4, borderBottom: '1px solid var(--hairline)' }}
                >
                    {tabs
                        .filter((t) => t.show)
                        .map((t) => {
                            const selected = tab === t.id;
                            return (
                                <button
                                    key={t.id}
                                    type="button"
                                    role="tab"
                                    id={`connector-account-${account.id}-edit-tab-${t.id}`}
                                    aria-selected={selected}
                                    aria-controls={`connector-account-${account.id}-edit-panel`}
                                    data-testid={`connector-account-${account.id}-edit-tab-${t.id}`}
                                    onClick={() => setTab(t.id)}
                                    style={{
                                        padding: '6px 12px',
                                        fontSize: 12,
                                        background: 'transparent',
                                        color: selected ? 'var(--fg-0)' : 'var(--fg-3)',
                                        border: 0,
                                        borderBottom: `2px solid ${selected ? 'var(--accent, #6366f1)' : 'transparent'}`,
                                        cursor: 'pointer',
                                        fontWeight: selected ? 600 : 400,
                                    }}
                                >
                                    {t.label}
                                </button>
                            );
                        })}
                </div>

                <div
                    id={`connector-account-${account.id}-edit-panel`}
                    role="tabpanel"
                    // Label the panel by its active tab so a screen reader announces
                    // which section the content belongs to.
                    aria-labelledby={`connector-account-${account.id}-edit-tab-${tab}`}
                    style={{ overflowY: 'auto', display: 'flex', flexDirection: 'column' }}
                >
                    {tab === 'details' && (
                        <AccountMetaForm
                            embedded
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
                    )}

                    {tab === 'connection' && isCredential && (
                        <ConnectionTabBody
                            entry={entry}
                            loading={exportQuery.isLoading}
                            error={exportQuery.isError}
                            onRetry={() => exportQuery.refetch()}
                        >
                            <CredentialConnectorForm
                                // Stable key: the form only mounts once the prefill is
                                // ready (ConnectionTabBody gates on `loading`), so it
                                // seeds from the fetched params on mount. Keying on
                                // `dataUpdatedAt` would remount + wipe in-progress edits
                                // on any later refetch — the prefill query now also
                                // refuses to refetch while open (see useInstallationExport).
                                key={`conn-${account.id}`}
                                embedded
                                mode="edit"
                                entry={entry}
                                projects={projects}
                                initialValues={connInitial}
                                submitLabel="Save connection"
                                onSubmit={(payload) => runSubmit(() => onSubmitConnection(payload))}
                                onTest={onTestConnection}
                                onClose={onClose}
                                submitError={error}
                                fieldErrors={fieldErrors}
                                isSubmitting={submitting}
                            />
                        </ConnectionTabBody>
                    )}

                    {tab === 'settings' && isCredential && (
                        <ConnectionSettingsForm
                            embedded
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
            </div>
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
 * Gates the Connection form on the export prefill fetch — a loading spinner while it
 * resolves, a loud error + retry if it fails (R14: never render the form seeded with
 * empty defaults that would silently overwrite the stored connection params).
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
