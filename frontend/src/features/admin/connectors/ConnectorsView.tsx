import { AdminShell } from '../shell/AdminShell';
import { ToastHost, useToast } from '../shared/Toast';
import { toAdminError } from '../shared/errors';
import { ConnectorCard } from './ConnectorCard';
import {
    useConnectors,
    useDestroyConnector,
    useStartInstall,
    useSyncNow,
} from './connectors-hooks';

/*
 * v4.5/W3 — Connector admin landing page.
 *
 * Renders one card per registered connector (Google Drive + Notion
 * always — additional cards appear as more reference connectors are
 * registered under W3+). Empty state is defensive: the registry is
 * always seeded with at least two connectors in this release, so an
 * empty list means the BE returned `[]`, which is itself a defect
 * worth surfacing.
 *
 * Mutations flow through TanStack Query so every state transition
 * (install / sync / disconnect) invalidates the list and the cards
 * refetch. No optimistic updates — connector ops are infrequent and
 * the server's status enum is the source of truth.
 *
 * R14 — every error surfaces as a toast (with deterministic testid).
 * Silent 200 paths are impossible: each mutation explicitly catches
 * and reports via toast.error().
 */

export function ConnectorsView() {
    const toast = useToast();
    const connectorsQuery = useConnectors();
    const startInstall = useStartInstall();
    const syncNow = useSyncNow();
    const destroyConnector = useDestroyConnector();

    const state: 'loading' | 'ready' | 'error' | 'empty' = connectorsQuery.isLoading
        ? 'loading'
        : connectorsQuery.isError
          ? 'error'
          : (connectorsQuery.data?.length ?? 0) === 0
            ? 'empty'
            : 'ready';

    const entries = connectorsQuery.data ?? [];

    async function handleConnect(key: string) {
        try {
            const result = await startInstall.mutateAsync(key);
            // Navigate the browser to the provider's OAuth URL. The
            // provider will redirect back to /app/admin/connectors/<key>/callback
            // once the user completes (or rejects) the consent screen.
            // We use window.location.assign() so the back-button behaviour
            // is predictable (single history entry).
            window.location.assign(result.redirect_to);
        } catch (e) {
            const err = toAdminError(e);
            toast.error(err.message, 'toast-connector-error');
        }
    }

    async function handleSync(installationId: number) {
        try {
            await syncNow.mutateAsync(installationId);
            toast.success('Sync queued.', 'toast-connector-synced');
        } catch (e) {
            const err = toAdminError(e);
            toast.error(err.message, 'toast-connector-error');
        }
    }

    async function handleDisconnect(installationId: number) {
        try {
            await destroyConnector.mutateAsync(installationId);
            toast.success('Connector disconnected.', 'toast-connector-disconnected');
        } catch (e) {
            const err = toAdminError(e);
            toast.error(err.message, 'toast-connector-error');
        }
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
                        Connect external sources via OAuth and sync their content into your knowledge base.
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
                            gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))',
                            gap: 12,
                        }}
                    >
                        {entries.map((entry) => (
                            <ConnectorCard
                                key={entry.key}
                                entry={entry}
                                onConnect={handleConnect}
                                onSync={handleSync}
                                onDisconnect={handleDisconnect}
                                onCancelInstall={handleDisconnect}
                                pending={{
                                    connecting:
                                        startInstall.isPending &&
                                        startInstall.variables === entry.key,
                                    syncing:
                                        syncNow.isPending &&
                                        syncNow.variables === entry.installation?.id,
                                    disconnecting:
                                        destroyConnector.isPending &&
                                        destroyConnector.variables === entry.installation?.id,
                                }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AdminShell>
    );
}
