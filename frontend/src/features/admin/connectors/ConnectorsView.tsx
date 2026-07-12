import { useEffect, useMemo, useRef, useState, type Dispatch, type SetStateAction } from 'react';
import { AdminShell } from '../shell/AdminShell';
import { ApiConnectionsSection } from './ApiConnectionsSection';
import { ToastHost, useToast } from '../shared/Toast';
import { toAdminError } from '../shared/errors';
import { AccountEditModal, type EditTab } from './AccountEditModal';
import { AccountMetaForm, type AccountMetaFormValues } from './AccountMetaForm';
import { ConnectionsCards } from './ConnectionsCards';
import { ConnectionsTable } from './ConnectionsTable';
import { ConnectionSettingsForm } from './ConnectionSettingsForm';
import { CredentialConnectorForm } from './CredentialConnectorForm';
import { SourceTile } from './SourceTile';
import { SyncErrorModal } from './SyncErrorModal';
import { TestFetchResultModal } from './TestFetchResultModal';
import {
    buildConnections,
    filterConnections,
    syncableIds,
    type ConnectionVM,
} from './connection-vm';
import type { ConnectionActions, ConnectionInFlight } from './connections-shared';
import { CONNECTORS_STYLES } from './connectors-styles';
import {
    adminConnectorsApi,
    type ConfigureConnectorPayload,
    type ConnectorEntry,
    type ConnectorInstallationDto,
    type ImportPrefill,
    type TestFetchResponse,
} from './connectors.api';
import {
    useConfigureConnector,
    useConnectors,
    useDestroyConnector,
    useDisableConnector,
    useEnableConnector,
    useImportConnectorConfig,
    useProjectOptions,
    useReconfigureConnector,
    useStartInstall,
    useSyncNow,
    useTestConnectorConnection,
    useTestFetch,
    useUpdateInstallation,
} from './connectors-hooks';

/**
 * Extracts a top-level message + per-field errors from an axios 422. Reuses
 * `toAdminError()` for the standard Laravel `{ message, errors }` flattening
 * and layers the connector-specific `{ error }` shape on top.
 */
function parseConfigureError(e: unknown): { message: string; fieldErrors: Record<string, string> } {
    const base = toAdminError(e);
    const connectorError = (e as { response?: { data?: { error?: string } } })?.response?.data?.error;
    return {
        message: connectorError ?? base.message,
        fieldErrors: base.fieldErrors,
    };
}

/** Trigger a client-side download of a JSON payload (the connector-config export). */
function downloadJson(data: unknown, filename: string): void {
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

/** A filesystem-safe export filename, e.g. `imap-Support.askmydocs-connector.json`. */
function connectorFilename(key: string, label: string): string {
    const safe = (s: string) => s.replace(/[^a-zA-Z0-9._-]+/g, '-').replace(/^-+|-+$/g, '') || 'account';
    return `${safe(key)}-${safe(label)}.askmydocs-connector.json`;
}

/*
 * v8.30 — Connector admin landing page, redesigned per the "Miglioramento
 * usabilità card" handoff (Connectors.dc.html):
 *
 *   1. "Available sources" — one compact tile per registered connector with a
 *      connection-count badge and an Add (+) / Import affordance.
 *   2. "Connections" — a FLAT list of every connected account across all
 *      sources (table view by default, cards view via the toolbar toggle),
 *      with search, "Sync all", per-row inline Sync and a ⋮ actions menu.
 *   3. A "Sync failed" detail modal for errored connections (full error text +
 *      Retry sync), replacing the old inline truncated error block.
 *
 * The mockup is dark-only; per the agreed brief this implementation keeps the
 * layout but drives every colour from the app's design tokens so light + dark
 * themes both work. All account-level modals (credential form, OAuth meta form,
 * tabbed Edit, Folders/settings, Test-fetch preview) are reused unchanged —
 * this page only changes how they are reached.
 *
 * R14 — every mutation surfaces success/failure via a toast (deterministic
 * testid). No silent 200 paths.
 */

type Modal =
    // v8.29 — credential-add carries an optional prefill (from an imported file):
    // non-secret params + label/project hints seed the create form; the secret is
    // still typed by the operator.
    | { kind: 'credential-add'; entry: ConnectorEntry; prefill?: ImportPrefill }
    | { kind: 'oauth-add'; entry: ConnectorEntry }
    // v8.29 — the tabbed Edit modal (Details / Connection / Sync settings).
    | { kind: 'edit'; entry: ConnectorEntry; account: ConnectorInstallationDto; tab?: EditTab }
    | { kind: 'folders'; entry: ConnectorEntry; account: ConnectorInstallationDto }
    | null;

/**
 * Read-only test-fetch result lives in its OWN state (not the `Modal` union): it
 * is triggered from a row, not a form, and reusing `Modal` would route it through
 * the project-binding modal machinery (e.g. the projects-load error effect).
 */
type TestFetchModal = { account: ConnectorInstallationDto; result: TestFetchResponse['data'] } | null;

type ConnectionsViewMode = 'table' | 'cards';

export function ConnectorsView() {
    const toast = useToast();
    const connectorsQuery = useConnectors();
    const projectsQuery = useProjectOptions();
    const startInstall = useStartInstall();
    const syncNow = useSyncNow();
    const disableConnector = useDisableConnector();
    const enableConnector = useEnableConnector();
    const destroyConnector = useDestroyConnector();
    const configureConnector = useConfigureConnector();
    const testConnection = useTestConnectorConnection();
    const updateInstallation = useUpdateInstallation();
    const reconfigureConnector = useReconfigureConnector();
    const importConfig = useImportConnectorConfig();
    const testFetch = useTestFetch();

    const [modal, setModal] = useState<Modal>(null);
    const [modalError, setModalError] = useState<string | null>(null);
    const [modalFieldErrors, setModalFieldErrors] = useState<Record<string, string>>({});
    // Mirror the open modal so an in-flight request can tell, on resolve, whether
    // the user has since switched/closed it (R17 — never paint onto a stale form).
    const modalRef = useRef<Modal>(null);
    useEffect(() => {
        modalRef.current = modal;
    }, [modal]);

    // ── Redesign state: view toggle, search, open ⋮ menu, sync-error modal ────
    const [view, setView] = useState<ConnectionsViewMode>('table');
    const [query, setQuery] = useState('');
    const [menuId, setMenuId] = useState<number | null>(null);
    // The errored connection whose "Sync failed" detail modal is open. Stored by
    // id (not by VM) so a background refetch keeps the modal on fresh data, and
    // the modal auto-dismisses if the row disappears (e.g. removed elsewhere).
    const [errorId, setErrorId] = useState<number | null>(null);

    const state: 'loading' | 'ready' | 'error' | 'empty' = connectorsQuery.isLoading
        ? 'loading'
        : connectorsQuery.isError
          ? 'error'
          : (connectorsQuery.data?.length ?? 0) === 0
            ? 'empty'
            : 'ready';

    const entries = connectorsQuery.data ?? [];
    const projects = projectsQuery.data ?? [];

const connections = useMemo(() => buildConnections(entries), [entries]);
const visible = useMemo(() => filterConnections(connections, query), [connections, query]);
// Re-derive the Sync-failed modal's connection from the fresh list each
// render, gated on it STILL being errored — so a background refetch that
// recovered the account (→ active) auto-dismisses the now-stale error modal
// instead of showing a hollow "Connector reported an error" (R17).
const errorVm: ConnectionVM | null =
    errorId === null
        ? null
        : (connections.find((c) => c.id === errorId && c.status === 'errored') ?? null);
    function openModalReset(next: Modal) {
        setModalError(null);
        setModalFieldErrors({});
        setModal(next);
    }

    // R14 — surface a projects-load failure loudly whenever a modal is open and
    // the project list errors — whether it had already failed when the modal
    // opened OR fails afterwards (the dropdown would otherwise sit silently empty
    // and read as "no projects"). Toast once per modal-open.
    const projectsErrorToasted = useRef(false);
    useEffect(() => {
        if (modal === null) {
            projectsErrorToasted.current = false;
            return;
        }
        if (projectsQuery.isError && !projectsErrorToasted.current) {
            projectsErrorToasted.current = true;
            toast.error(
                'Could not load the project list — KB project binding will default to tenant default.',
                'toast-projects-load-error',
            );
        }
    }, [modal, projectsQuery.isError, toast]);

    function handleAddAccount(key: string) {
        const entry = entries.find((c) => c.key === key);
        if (!entry) return;
        if (entry.auth_kind === 'credential') {
            if (!entry.credential_form_schema || entry.credential_form_schema.length === 0) {
                toast.error(`${entry.display_name} did not provide a credential form.`, 'toast-connector-error');
                return;
            }
            openModalReset({ kind: 'credential-add', entry });
            return;
        }
        openModalReset({ kind: 'oauth-add', entry });
    }

    function handleEdit(entry: ConnectorEntry, account: ConnectorInstallationDto) {
        openModalReset({ kind: 'edit', entry, account });
    }

    function handleManageFolders(entry: ConnectorEntry, account: ConnectorInstallationDto) {
        openModalReset({ kind: 'folders', entry, account });
    }

    async function handleCredentialSubmit(payload: ConfigureConnectorPayload) {
        const current = modal;
        if (current?.kind !== 'credential-add') return;
        const target = current.entry;
        setModalError(null);
        setModalFieldErrors({});
        try {
            const result = await configureConnector.mutateAsync({ key: target.key, payload });
            if (result.redirect_to) {
                window.location.assign(result.redirect_to);
                return;
            }
            setModal((cur) => (cur?.kind === 'credential-add' && cur.entry.key === target.key ? null : cur));
            toast.success(`${target.display_name} account connected.`, 'toast-connector-configured');
        } catch (e) {
            const open = modalRef.current;
            if (open?.kind !== 'credential-add' || open.entry.key !== target.key) return;
            const { message, fieldErrors } = parseConfigureError(e);
            setModalError(message);
            setModalFieldErrors(fieldErrors);
        }
    }

    // v8.26 — pre-save connection test for the "Test connection" button. The BE
    // pings the submitted credentials WITHOUT persisting anything and returns the
    // { ok, error } verdict; the form gates Connect on a passing test.
    async function handleCredentialTest(
        payload: ConfigureConnectorPayload,
    ): Promise<{ ok: boolean; error?: string | null }> {
        const current = modalRef.current;
        if (current?.kind !== 'credential-add') return { ok: false, error: 'The form was closed.' };
        try {
            return await testConnection.mutateAsync({ key: current.entry.key, payload });
        } catch (e) {
            // A refused/unreachable server is a 200 { ok:false } from the BE, so
            // only a transport/404 error reaches here — surface it as a failed
            // test (never a silent pass, R14).
            const { message } = parseConfigureError(e);
            return { ok: false, error: message ?? 'Connection test failed. Please try again.' };
        }
    }

    async function handleOAuthAddSubmit(values: AccountMetaFormValues) {
        const current = modal;
        if (current?.kind !== 'oauth-add') return;
        const target = current.entry;
        setModalError(null);
        setModalFieldErrors({});
        try {
            const result = await startInstall.mutateAsync({
                key: target.key,
                label: values.label,
                projectKey: values.projectKey || null,
            });
            // Navigate to the provider's OAuth screen; the existing callback route
            // finishes the flow.
            window.location.assign(result.redirect_to);
        } catch (e) {
            const open = modalRef.current;
            if (open?.kind !== 'oauth-add' || open.entry.key !== target.key) return;
            const { message, fieldErrors } = parseConfigureError(e);
            setModalError(message);
            setModalFieldErrors(fieldErrors);
        }
    }

    // v8.29 — the tabbed Edit modal owns its own submit/error state; the parent just
    // provides async callbacks that resolve on success (toast) and REJECT on failure
    // (the modal renders the error inline + keeps itself open). No shared modalError.
    async function submitEditDetails(account: ConnectorInstallationDto, values: AccountMetaFormValues) {
        await updateInstallation.mutateAsync({
            installationId: account.id,
            label: values.label,
            project_key: values.projectKey, // '' clears → tenant default
        });
        toast.success('Account updated.', 'toast-connector-updated');
    }

    async function submitEditConnection(account: ConnectorInstallationDto, payload: ConfigureConnectorPayload) {
        await reconfigureConnector.mutateAsync({ installationId: account.id, payload });
        toast.success('Connection updated.', 'toast-connector-reconfigured');
    }

    async function testEditConnection(
        entry: ConnectorEntry,
        payload: ConfigureConnectorPayload,
    ): Promise<{ ok: boolean; error?: string | null }> {
        try {
            return await testConnection.mutateAsync({ key: entry.key, payload });
        } catch (e) {
            return { ok: false, error: parseConfigureError(e).message };
        }
    }

    async function submitEditSettings(account: ConnectorInstallationDto, settings: Record<string, unknown>) {
        await updateInstallation.mutateAsync({ installationId: account.id, settings });
        toast.success('Connection settings saved.', 'toast-connector-settings-saved');
    }

    // v8.29 — export an account's connection params (secret-free) as a download.
    const [exportingIds, setExportingIds] = useState<ReadonlySet<number>>(() => new Set());
    const exportInFlightRef = useRef<Set<number>>(new Set());

    async function handleExport(account: ConnectorInstallationDto) {
        const id = account.id;
        if (exportInFlightRef.current.has(id)) return;
        exportInFlightRef.current.add(id);
        setExportingIds((s) => new Set(s).add(id));
        try {
            const cfg = await adminConnectorsApi.exportInstallation(id);
            downloadJson(cfg, connectorFilename(cfg.connector, cfg.label ?? account.label));
            toast.success('Parameters exported.', 'toast-connector-exported');
        } catch (e) {
            toast.error(toAdminError(e).message, 'toast-connector-error');
        } finally {
            exportInFlightRef.current.delete(id);
            setExportingIds((s) => {
                const next = new Set(s);
                next.delete(id);
                return next;
            });
        }
    }

    // v8.29 — import a config file → validate/sanitize on the BE → open the create
    // form prefilled (the operator types the secret and Connects). Reading the file
    // is client-side; the BE never sees a secret in the file.
    async function handleImportFile(key: string, file: File) {
        const entry = entries.find((c) => c.key === key);
        if (!entry) return;

        let blob: unknown;
        try {
            blob = JSON.parse(await file.text());
        } catch {
            toast.error('That file is not valid JSON.', 'toast-connector-error');
            return;
        }

        try {
            const prefill = await importConfig.mutateAsync({ key, blob });
            openModalReset({ kind: 'credential-add', entry, prefill });
            toast.success('Config loaded — enter the password to connect.', 'toast-connector-imported');
        } catch (e) {
            toast.error(parseConfigureError(e).message, 'toast-connector-error');
        }
    }

    async function handleSettingsSubmit(settings: Record<string, unknown>) {
        const current = modal;
        if (current?.kind !== 'folders') return;
        const target = current.account;
        setModalError(null);
        setModalFieldErrors({});
        try {
            await updateInstallation.mutateAsync({
                installationId: target.id,
                // v8.25 — the full schema-driven settings payload (nested partial
                // of config_json) the connection-settings form assembles.
                settings,
            });
            setModal((cur) => (cur?.kind === 'folders' && cur.account.id === target.id ? null : cur));
            toast.success('Connection settings saved.', 'toast-connector-settings-saved');
        } catch (e) {
            const open = modalRef.current;
            if (open?.kind !== 'folders' || open.account.id !== target.id) return;
            const { message, fieldErrors } = parseConfigureError(e);
            setModalError(message);
            setModalFieldErrors(fieldErrors);
        }
    }

    // Per-account in-flight tracking. A single `useMutation().variables` only
    // remembers the MOST RECENT mutate call, so two quick actions on different
    // accounts would lose the earlier row's pending state. Track the actual set
    // of in-flight ids instead so every busy account stays disabled until it
    // resolves.
    const [syncingIds, setSyncingIds] = useState<ReadonlySet<number>>(() => new Set());
    const [busyIds, setBusyIds] = useState<ReadonlySet<number>>(() => new Set());
    // Enable is tracked apart from `busyIds` so only the Enable button shows
    // "Enabling…"; another write on the same disabled account (e.g. Remove) must
    // not relabel Enable. It still locks every write button via the shared
    // in-flight guard + the row's `writeLocked` (which folds in `enabling`).
    const [enablingIds, setEnablingIds] = useState<ReadonlySet<number>>(() => new Set());
    // Read-only test-fetch probe in-flight ids — tracked separately from the write
    // actions so the diagnostic neither blocks nor is blocked by sync/disable/etc.
    const [probingIds, setProbingIds] = useState<ReadonlySet<number>>(() => new Set());
    const [testFetchModal, setTestFetchModal] = useState<TestFetchModal>(null);
    // Synchronous in-flight guard (a ref updates immediately, unlike batched
    // state) so a double-trigger on the same account can't start two overlapping
    // runs — the first finishing would otherwise clear the busy flag while the
    // second is still in flight. Probes use their own ref (independent of writes).
    const inFlightRef = useRef<Set<number>>(new Set());
    const probeInFlightRef = useRef<Set<number>>(new Set());

    async function track(
        setter: Dispatch<SetStateAction<ReadonlySet<number>>>,
        id: number,
        run: () => Promise<void>,
    ) {
        if (inFlightRef.current.has(id)) {
            return; // an action for this account is already running — ignore.
        }
        inFlightRef.current.add(id);
        setter((s) => new Set(s).add(id));
        try {
            await run();
        } finally {
            inFlightRef.current.delete(id);
            setter((s) => {
                const next = new Set(s);
                next.delete(id);
                return next;
            });
        }
    }

    async function handleSync(installationId: number) {
        await track(setSyncingIds, installationId, async () => {
            try {
                await syncNow.mutateAsync(installationId);
                toast.success('Sync queued.', 'toast-connector-synced');
            } catch (e) {
                toast.error(toAdminError(e).message, 'toast-connector-error');
            }
        });
    }

    // "Sync all" (toolbar) — queue a sync for every active/errored connection.
    // Runs the whole sweep with per-id tracking (each row shows its own spinner)
    // and reports ONE summary toast instead of N per-account ones.
    async function handleSyncAll() {
        const ids = syncableIds(connections).filter((id) => !inFlightRef.current.has(id));
        if (ids.length === 0) return;
        let failed = 0;
        await Promise.all(
            ids.map((id) =>
                track(setSyncingIds, id, async () => {
                    try {
                        await syncNow.mutateAsync(id);
                    } catch {
                        failed += 1;
                    }
                }),
            ),
        );
        if (failed === 0) {
            toast.success(
                `Sync queued for ${ids.length} connection${ids.length === 1 ? '' : 's'}.`,
                'toast-connector-synced',
            );
            return;
        }
        toast.error(
            `Sync could not be queued for ${failed} of ${ids.length} connection${ids.length === 1 ? '' : 's'}.`,
            'toast-connector-error',
        );
    }

    async function handleDisable(installationId: number) {
        await track(setBusyIds, installationId, async () => {
            try {
                await disableConnector.mutateAsync(installationId);
                toast.success('Account disabled.', 'toast-connector-disabled');
            } catch (e) {
                toast.error(toAdminError(e).message, 'toast-connector-error');
            }
        });
    }

    async function handleEnable(installationId: number) {
        await track(setEnablingIds, installationId, async () => {
            try {
                await enableConnector.mutateAsync(installationId);
                toast.success('Account enabled.', 'toast-connector-enabled');
            } catch (e) {
                toast.error(toAdminError(e).message, 'toast-connector-error');
            }
        });
    }

    async function handleTestFetch(account: ConnectorInstallationDto) {
        const id = account.id;
        if (probeInFlightRef.current.has(id)) {
            return; // a probe for this account is already running — ignore.
        }
        probeInFlightRef.current.add(id);
        setProbingIds((s) => new Set(s).add(id));
        try {
            const result = await testFetch.mutateAsync(id);
            // Re-read the account from the fresh list if it moved, but the preview
            // is point-in-time so the captured `account` is fine for the title.
            setTestFetchModal({ account, result });
        } catch (e) {
            toast.error(toAdminError(e).message, 'toast-connector-error');
        } finally {
            probeInFlightRef.current.delete(id);
            setProbingIds((s) => {
                const next = new Set(s);
                next.delete(id);
                return next;
            });
        }
    }

    async function handleRemove(installationId: number) {
        await track(setBusyIds, installationId, async () => {
            try {
                await destroyConnector.mutateAsync(installationId);
                toast.success('Account removed.', 'toast-connector-disconnected');
            } catch (e) {
                toast.error(toAdminError(e).message, 'toast-connector-error');
            }
        });
    }

    function addPendingFor(key: string): boolean {
        return (
            (startInstall.isPending && startInstall.variables?.key === key) ||
            (configureConnector.isPending && configureConnector.variables?.key === key)
        );
    }

    const actions: ConnectionActions = {
        onSync: handleSync,
        onTestFetch: (vm) => handleTestFetch(vm.installation),
        onEdit: (vm) => handleEdit(vm.entry, vm.installation),
        onFolders: (vm) => handleManageFolders(vm.entry, vm.installation),
        onExport: (vm) => handleExport(vm.installation),
        onDisable: handleDisable,
        onEnable: handleEnable,
        onRemove: handleRemove,
        onCancelInstall: handleRemove,
        onOpenError: (vm) => setErrorId(vm.id),
        onToggleMenu: (id) => setMenuId((cur) => (cur === id ? null : id)),
        onCloseMenu: () => setMenuId(null),
    };

    const inflight: ConnectionInFlight = {
        syncingIds,
        busyIds,
        enablingIds,
        probingIds,
        exportingIds,
    };

    const canSyncAll = syncableIds(connections).length > 0;

    return (
        <AdminShell section="connectors">
            <ToastHost />
            <style>{CONNECTORS_STYLES}</style>
            <div
                data-testid="admin-connectors"
                data-state={state}
                // Any click that bubbles up to the page closes the open ⋮ menu
                // (the menu trigger + panel stop propagation on their own clicks).
                onClick={() => {
                    if (menuId !== null) setMenuId(null);
                }}
                style={{
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 26,
                    maxWidth: 1220,
                    width: '100%',
                    margin: '0 auto',
                }}
            >
                <div>
                    <h1
                        style={{
                            fontSize: 22,
                            fontWeight: 700,
                            margin: 0,
                            letterSpacing: '-0.02em',
                            color: 'var(--fg-0)',
                        }}
                    >
                        Connectors
                    </h1>
                    <p
                        style={{
                            fontSize: 13,
                            color: 'var(--fg-3)',
                            margin: '6px 0 0',
                            lineHeight: 1.5,
                            maxWidth: 640,
                        }}
                    >
                        Connect multiple accounts per source and bind each to a project (or the tenant
                        default), then sync their content into your knowledge base.
                    </p>
                </div>

                {state === 'loading' && (
                    <div
                        data-testid="admin-connectors-loading"
                        role="status"
                        aria-busy="true"
                        style={{
                            padding: 28,
                            textAlign: 'center',
                            color: 'var(--fg-3)',
                            border: '1px dashed var(--hairline)',
                            borderRadius: 10,
                        }}
                    >
                        Loading connectors…
                    </div>
                )}

                {state === 'error' && (
                    <div
                        data-testid="admin-connectors-error"
                        role="alert"
                        style={{
                            padding: 16,
                            background: 'rgba(239, 68, 68, 0.08)',
                            border: '1px solid rgba(239, 68, 68, 0.30)',
                            borderRadius: 10,
                            color: '#fca5a5',
                            fontSize: 13,
                        }}
                    >
                        Could not load connectors.{' '}
                        <button
                            type="button"
                            data-testid="admin-connectors-retry"
                            className="focus-ring"
                            onClick={() => connectorsQuery.refetch()}
                            style={{
                                marginLeft: 8,
                                padding: '4px 10px',
                                fontSize: 12,
                                background: 'transparent',
                                color: '#fca5a5',
                                border: '1px solid rgba(239, 68, 68, 0.45)',
                                borderRadius: 6,
                                cursor: 'pointer',
                            }}
                        >
                            Retry
                        </button>
                    </div>
                )}

                {state === 'empty' && (
                    <div
                        data-testid="admin-connectors-empty"
                        role="status"
                        style={{
                            padding: 28,
                            textAlign: 'center',
                            color: 'var(--fg-3)',
                            border: '1px dashed var(--hairline)',
                            borderRadius: 10,
                        }}
                    >
                        No source connectors are registered in this AskMyDocs build.
                    </div>
                )}

                {state === 'ready' && (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 22 }}>
                        {/* Available sources — one add/import tile per registered source. */}
                        <section style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                            <h2 style={sectionHeadingStyle}>Available sources</h2>
                            <div
                                data-testid="admin-connectors-grid"
                                style={{
                                    display: 'grid',
                                    gridTemplateColumns: 'repeat(auto-fill, minmax(320px, 1fr))',
                                    gap: 12,
                                }}
                            >
                                {entries.map((entry) => (
                                    <SourceTile
                                        key={entry.key}
                                        entry={entry}
                                        connectionCount={entry.installations?.length ?? 0}
                                        onAdd={handleAddAccount}
                                        onImport={handleImportFile}
                                        addPending={addPendingFor(entry.key)}
                                    />
                                ))}
                            </div>
                        </section>

                        {/* Connections — flat list of every connected account, table/cards. */}
                        <section style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                                <h2 style={sectionHeadingStyle}>Connections</h2>
                                <span
                                    data-testid="connector-connections-count"
                                    style={{
                                        fontSize: 12,
                                        fontWeight: 600,
                                        color: 'var(--fg-2)',
                                        background: 'var(--bg-2)',
                                        borderRadius: 999,
                                        padding: '1px 8px',
                                    }}
                                >
                                    {visible.length}
                                </span>
                                <div style={{ flex: 1, minWidth: 12 }} />
                                <input
                                    data-testid="connector-connections-search"
                                    type="search"
                                    aria-label="Search connections"
                                    placeholder="Search connections…"
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    className="focus-ring"
                                    style={{
                                        fontSize: 13,
                                        padding: '6px 10px',
                                        borderRadius: 8,
                                        border: '1px solid var(--hairline)',
                                        background: 'var(--bg-1)',
                                        color: 'var(--fg-0)',
                                        minWidth: 180,
                                    }}
                                />
                                <div
                                    role="group"
                                    aria-label="Connections view mode"
                                    style={{
                                        display: 'flex',
                                        gap: 4,
                                        background: 'var(--bg-2)',
                                        borderRadius: 9,
                                        padding: 3,
                                    }}
                                >
                                    <ViewTab
                                        testid="connector-connections-view-table"
                                        label="Table"
                                        active={view === 'table'}
                                        onClick={() => setView('table')}
                                        icon={
                                            <svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                                <rect x="1.5" y="2.5" width="13" height="11" rx="1.5" stroke="currentColor" />
                                                <path d="M1.5 6.5h13M6 6.5v7" stroke="currentColor" />
                                            </svg>
                                        }
                                    />
                                    <ViewTab
                                        testid="connector-connections-view-cards"
                                        label="Cards"
                                        active={view === 'cards'}
                                        onClick={() => setView('cards')}
                                        icon={
                                            <svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                                <rect x="1.5" y="1.5" width="6" height="6" rx="1" stroke="currentColor" />
                                                <rect x="8.5" y="1.5" width="6" height="6" rx="1" stroke="currentColor" />
                                                <rect x="1.5" y="8.5" width="6" height="6" rx="1" stroke="currentColor" />
                                                <rect x="8.5" y="8.5" width="6" height="6" rx="1" stroke="currentColor" />
                                            </svg>
                                        }
                                    />
                                </div>
                                <button
                                    type="button"
                                    data-testid="connector-connections-sync-all"
                                    className="focus-ring"
                                    disabled={!canSyncAll}
                                    onClick={handleSyncAll}
                                    style={{
                                        fontSize: 12.5,
                                        fontWeight: 600,
                                        padding: '7px 12px',
                                        borderRadius: 8,
                                        border: '1px solid var(--hairline)',
                                        background: 'var(--bg-1)',
                                        color: canSyncAll ? 'var(--fg-0)' : 'var(--fg-3)',
                                        cursor: canSyncAll ? 'pointer' : 'not-allowed',
                                    }}
                                >
                                    Sync all
                                </button>
                            </div>

                            {visible.length === 0 ? (
                                <div
                                    data-testid="connector-connections-empty"
                                    role="status"
                                    style={{
                                        padding: 24,
                                        textAlign: 'center',
                                        color: 'var(--fg-3)',
                                        border: '1px dashed var(--hairline)',
                                        borderRadius: 10,
                                        fontSize: 13,
                                    }}
                                >
                                    {connections.length === 0
                                        ? 'No connections yet — add an account from a source above.'
                                        : 'No connections match your search.'}
                                </div>
                            ) : view === 'table' ? (
                                <ConnectionsTable rows={visible} menuId={menuId} actions={actions} inflight={inflight} />
                            ) : (
                                <ConnectionsCards rows={visible} menuId={menuId} actions={actions} inflight={inflight} />
                            )}
                        </section>
                    </div>
                )}

                {/* API connections — a distinct paradigm (endpoints → live chat
                    tools). Created + listed here; deep route/auth/relation/test
                    management drills into the dedicated page via "Manage". */}
                <ApiConnectionsSection />
            </div>

            {modal?.kind === 'credential-add' && (
                <CredentialConnectorForm
                    // key on the connector identity + whether a prefill is present →
                    // React remounts (fresh label/project/field state) if the modal
                    // is reused for a different connector or an import prefill without
                    // closing first (R17).
                    key={`credential-add-${modal.entry.key}-${modal.prefill ? 'import' : 'new'}`}
                    entry={modal.entry}
                    projects={projects}
                    // v8.29 — seed from an imported file when present (non-secret
                    // params + label/project hints; the secret is still typed).
                    initialValues={modal.prefill?.params}
                    initialLabel={modal.prefill?.label ?? ''}
                    initialProjectKey={modal.prefill?.project_key ?? null}
                    onSubmit={handleCredentialSubmit}
                    onTest={handleCredentialTest}
                    onClose={() => setModal(null)}
                    submitError={modalError}
                    fieldErrors={modalFieldErrors}
                    isSubmitting={
                        configureConnector.isPending &&
                        configureConnector.variables?.key === modal.entry.key
                    }
                />
            )}

            {modal?.kind === 'oauth-add' && (
                <AccountMetaForm
                    key={`oauth-add-${modal.entry.key}`}
                    connectorKey={modal.entry.key}
                    title={`Add ${modal.entry.display_name} account`}
                    source={{ displayName: modal.entry.display_name, iconUrl: modal.entry.icon_url }}
                    subtitle={`Connect a new ${modal.entry.display_name} account to this source`}
                    submitLabel="Continue to provider"
                    projects={projects}
                    onSubmit={handleOAuthAddSubmit}
                    onClose={() => setModal(null)}
                    submitError={modalError}
                    fieldErrors={modalFieldErrors}
                    // Scope to THIS connector — another connector's install in
                    // flight must not disable this modal.
                    isSubmitting={
                        startInstall.isPending && startInstall.variables?.key === modal.entry.key
                    }
                />
            )}

            {modal?.kind === 'edit' && (
                <AccountEditModal
                    // key on the account identity → remount with the right
                    // pre-filled values when switching Edit between accounts (R17).
                    key={`edit-${modal.account.id}`}
                    entry={modal.entry}
                    account={modal.account}
                    projects={projects}
                    initialTab={modal.tab}
                    onSubmitDetails={(values) => submitEditDetails(modal.account, values)}
                    onSubmitConnection={(payload) => submitEditConnection(modal.account, payload)}
                    onTestConnection={(payload) => testEditConnection(modal.entry, payload)}
                    onSubmitSettings={(settings) => submitEditSettings(modal.account, settings)}
                    onClose={() => setModal(null)}
                />
            )}

            {modal?.kind === 'folders' && (
                <ConnectionSettingsForm
                    // key on the account identity → remount with fresh folder
                    // fetch + pre-filled values when switching between accounts.
                    key={`settings-${modal.account.id}`}
                    connectorKey={modal.entry.key}
                    account={modal.account}
                    onSubmit={handleSettingsSubmit}
                    onClose={() => setModal(null)}
                    submitError={modalError}
                    fieldErrors={modalFieldErrors}
                    isSubmitting={
                        updateInstallation.isPending &&
                        updateInstallation.variables?.installationId === modal.account.id
                    }
                />
            )}

            {testFetchModal && (
                <TestFetchResultModal
                    key={`test-fetch-${testFetchModal.account.id}`}
                    account={testFetchModal.account}
                    result={testFetchModal.result}
                    onClose={() => setTestFetchModal(null)}
                />
            )}

            {errorVm && (
                <SyncErrorModal
                    key={`sync-error-${errorVm.id}`}
                    vm={errorVm}
                    onClose={() => setErrorId(null)}
                    onRetry={handleSync}
                />
            )}
        </AdminShell>
    );
}

const sectionHeadingStyle: React.CSSProperties = {
    margin: 0,
    fontSize: 13,
    fontWeight: 600,
    textTransform: 'uppercase',
    letterSpacing: '.06em',
    color: 'var(--fg-3)',
};

function ViewTab({
    testid,
    label,
    active,
    onClick,
    icon,
}: {
    testid: string;
    label: string;
    active: boolean;
    onClick: () => void;
    icon: React.ReactNode;
}) {
    return (
        <button
            type="button"
            data-testid={testid}
            className="amd-cn-tab focus-ring"
            aria-pressed={active}
            onClick={onClick}
            style={{
                display: 'flex',
                alignItems: 'center',
                gap: 6,
                background: active ? 'var(--bg-3)' : 'transparent',
                color: active ? 'var(--fg-0)' : 'var(--fg-3)',
                border: 'none',
                font: 'inherit',
                fontSize: 12.5,
                fontWeight: 600,
                padding: '6px 12px',
                borderRadius: 7,
                cursor: 'pointer',
            }}
        >
            {icon}
            {label}
        </button>
    );
}
