import { useEffect, useRef, useState, type Dispatch, type SetStateAction } from 'react';
import { AdminShell } from '../shell/AdminShell';
import { ToastHost, useToast } from '../shared/Toast';
import { toAdminError } from '../shared/errors';
import { AccountMetaForm, type AccountMetaFormValues } from './AccountMetaForm';
import { ConnectorCard } from './ConnectorCard';
import { CredentialConnectorForm } from './CredentialConnectorForm';
import { ConnectionSettingsForm } from './ConnectionSettingsForm';
import type {
    ConfigureConnectorPayload,
    ConnectorEntry,
    ConnectorInstallationDto,
} from './connectors.api';
import {
    useConfigureConnector,
    useConnectors,
    useDestroyConnector,
    useDisableConnector,
    useProjectOptions,
    useStartInstall,
    useSyncNow,
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

/*
 * v8.20 — Connector admin landing page (multi-account).
 *
 * One card per registered connector; each card lists the active tenant's
 * ACCOUNTS for that connector (multi-account) with per-account actions.
 * "Add account" opens either the schema-driven credential form (IMAP) or the
 * label+project metadata form (OAuth connectors); "Edit" rebinds an account's
 * label/project. Every account optionally binds to a real KB project (R18
 * dropdown from the project registry).
 *
 * R14 — every mutation surfaces success/failure via a toast (deterministic
 * testid). No silent 200 paths.
 */

type Modal =
    | { kind: 'credential-add'; entry: ConnectorEntry }
    | { kind: 'oauth-add'; entry: ConnectorEntry }
    | { kind: 'edit'; entry: ConnectorEntry; account: ConnectorInstallationDto }
    | { kind: 'folders'; entry: ConnectorEntry; account: ConnectorInstallationDto }
    | null;

export function ConnectorsView() {
    const toast = useToast();
    const connectorsQuery = useConnectors();
    const projectsQuery = useProjectOptions();
    const startInstall = useStartInstall();
    const syncNow = useSyncNow();
    const disableConnector = useDisableConnector();
    const destroyConnector = useDestroyConnector();
    const configureConnector = useConfigureConnector();
    const updateInstallation = useUpdateInstallation();

    const [modal, setModal] = useState<Modal>(null);
    const [modalError, setModalError] = useState<string | null>(null);
    const [modalFieldErrors, setModalFieldErrors] = useState<Record<string, string>>({});
    // Mirror the open modal so an in-flight request can tell, on resolve, whether
    // the user has since switched/closed it (R17 — never paint onto a stale form).
    const modalRef = useRef<Modal>(null);
    useEffect(() => {
        modalRef.current = modal;
    }, [modal]);

    const state: 'loading' | 'ready' | 'error' | 'empty' = connectorsQuery.isLoading
        ? 'loading'
        : connectorsQuery.isError
          ? 'error'
          : (connectorsQuery.data?.length ?? 0) === 0
            ? 'empty'
            : 'ready';

    const entries = connectorsQuery.data ?? [];
    const projects = projectsQuery.data ?? [];

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

    async function handleEditSubmit(values: AccountMetaFormValues) {
        const current = modal;
        if (current?.kind !== 'edit') return;
        const target = current.account;
        setModalError(null);
        setModalFieldErrors({});
        try {
            await updateInstallation.mutateAsync({
                installationId: target.id,
                label: values.label,
                project_key: values.projectKey, // '' clears → tenant default
            });
            setModal((cur) => (cur?.kind === 'edit' && cur.account.id === target.id ? null : cur));
            toast.success('Account updated.', 'toast-connector-updated');
        } catch (e) {
            const open = modalRef.current;
            if (open?.kind !== 'edit' || open.account.id !== target.id) return;
            const { message, fieldErrors } = parseConfigureError(e);
            setModalError(message);
            setModalFieldErrors(fieldErrors);
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
            toast.success('Connection settings saved.', 'toast-connector-folders-saved');
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
    // Synchronous in-flight guard (a ref updates immediately, unlike batched
    // state) so a double-trigger on the same account can't start two overlapping
    // runs — the first finishing would otherwise clear the busy flag while the
    // second is still in flight.
    const inFlightRef = useRef<Set<number>>(new Set());

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

    return (
        <AdminShell section="connectors">
            <ToastHost />
            <style>{`@keyframes amd-spin { to { transform: rotate(360deg); } }`}</style>
            <div
                data-testid="admin-connectors"
                data-state={state}
                style={{ display: 'flex', flexDirection: 'column', gap: 14 }}
            >
                <div>
                    <h1
                        style={{
                            fontSize: 20,
                            fontWeight: 600,
                            margin: '0 0 2px',
                            letterSpacing: '-0.02em',
                            color: 'var(--fg-0)',
                        }}
                    >
                        Connectors
                    </h1>
                    <p style={{ fontSize: 12.5, color: 'var(--fg-3)', margin: 0 }}>
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
                        No connectors are registered in this AskMyDocs build.
                    </div>
                )}

                {state === 'ready' && (
                    <div
                        data-testid="admin-connectors-grid"
                        style={{
                            display: 'grid',
                            gridTemplateColumns: 'repeat(auto-fill, minmax(320px, 1fr))',
                            gap: 12,
                        }}
                    >
                        {entries.map((entry) => (
                            <ConnectorCard
                                key={entry.key}
                                entry={entry}
                                onAddAccount={handleAddAccount}
                                onSync={handleSync}
                                onDisable={handleDisable}
                                onRemove={handleRemove}
                                onEdit={(installation) => handleEdit(entry, installation)}
                                onManageFolders={(installation) => handleManageFolders(entry, installation)}
                                onCancelInstall={handleRemove}
                                syncingIds={syncingIds}
                                busyIds={busyIds}
                                addPending={addPendingFor(entry.key)}
                            />
                        ))}
                    </div>
                )}
            </div>

            {modal?.kind === 'credential-add' && (
                <CredentialConnectorForm
                    // key on the connector identity → React remounts (fresh
                    // label/project/field state) if the modal is reused for a
                    // different connector without closing first (R17).
                    key={`credential-add-${modal.entry.key}`}
                    entry={modal.entry}
                    projects={projects}
                    onSubmit={handleCredentialSubmit}
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
                <AccountMetaForm
                    // key on the account identity → remount with the right
                    // pre-filled values when switching Edit between accounts (R17).
                    key={`edit-${modal.account.id}`}
                    connectorKey={modal.entry.key}
                    title={`Edit ${modal.entry.display_name} account`}
                    submitLabel="Save changes"
                    projects={projects}
                    initialLabel={modal.account.label}
                    initialProjectKey={modal.account.project_key}
                    onSubmit={handleEditSubmit}
                    onClose={() => setModal(null)}
                    submitError={modalError}
                    fieldErrors={modalFieldErrors}
                    // Scope to THIS account — editing another account while a
                    // PATCH is in flight must not disable this modal.
                    isSubmitting={
                        updateInstallation.isPending &&
                        updateInstallation.variables?.installationId === modal.account.id
                    }
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
        </AdminShell>
    );
}
