import { useEffect, useRef, useState } from 'react';
import { AdminShell } from '../shell/AdminShell';
import { ToastHost, useToast } from '../shared/Toast';
import { toAdminError } from '../shared/errors';
import type {
    ApiAuthProfile,
    ApiConnector,
    ApiRoute,
    ApiRouteRelation,
    ApiRouteSummary,
    AuthProfilePayload,
    ConnectorPayload,
    DrillResult,
    ProbePayload,
    ProbeResult,
    RelationPayload,
} from './api-connectors.api';
import { apiConnectorsApi } from './api-connectors.api';
import {
    useActivateRoute,
    useApiConnectors,
    useCreateAuthProfile,
    useCreateConnector,
    useCreateRelation,
    useDeleteConnector,
    useDeleteRelation,
    useDeleteRoute,
    useDisableRoute,
    useDrillRelation,
    useProbeEndpoint,
    useProjectOptions,
    useRegenerateDescription,
    useTryRoute,
    useUpdateAuthProfile,
    useUpdateConnector,
    useUpdateRelation,
} from './api-connectors-hooks';
import { endpointTypeBadge, routeStatusBadge } from './route-status';
import { ConnectorForm } from './ConnectorForm';
import { AuthProfileForm } from './AuthProfileForm';
import { RouteWorkspace } from './RouteWorkspace';
import { TryToolModal } from './TryToolModal';
import { FreeEndpointModal } from './FreeEndpointModal';
import { RelationEditor } from './RelationEditor';
import { DrillTestPanel } from './DrillTestPanel';
import { buttonStyle } from './styles';

/*
 * v8.27 — API Connector (Connettore API) admin landing.
 *
 * One card per connector; each card lists its routes (name, slug, method,
 * status badge, mode) with per-route actions (Test → Activate / Disable, Try,
 * Edit, Remove). "+ New API connector" creates a connector; each card can add
 * auth profiles + routes.
 *
 * R14 — every mutation surfaces success/failure via a toast; loading/empty/error
 * states are explicit. R11/R29 testids `api-connector*` / `api-route*`.
 */

type Modal =
    | { kind: 'connector-create' }
    | { kind: 'connector-edit'; connector: ApiConnector }
    | { kind: 'auth-create'; connector: ApiConnector }
    | { kind: 'auth-edit'; connector: ApiConnector; profile: ApiAuthProfile }
    | { kind: 'route-workspace'; connector: ApiConnector; route: ApiRoute | null }
    | { kind: 'route-try'; route: ApiRoute }
    | { kind: 'relation-create'; connector: ApiConnector }
    | { kind: 'relation-edit'; connector: ApiConnector; relation: ApiRouteRelation }
    | { kind: 'relation-drill'; relation: ApiRouteRelation }
    | { kind: 'probe' }
    | null;

export function ApiConnectorsView() {
    const toast = useToast();
    const connectorsQuery = useApiConnectors();
    const projectsQuery = useProjectOptions();

    const createConnector = useCreateConnector();
    const updateConnector = useUpdateConnector();
    const deleteConnector = useDeleteConnector();
    const createAuthProfile = useCreateAuthProfile();
    const updateAuthProfile = useUpdateAuthProfile();
    const deleteRoute = useDeleteRoute();
    const regenerateDescription = useRegenerateDescription();
    const activateRoute = useActivateRoute();
    const disableRoute = useDisableRoute();
    const tryRoute = useTryRoute();
    const probeEndpoint = useProbeEndpoint();
    const createRelation = useCreateRelation();
    const updateRelation = useUpdateRelation();
    const deleteRelation = useDeleteRelation();
    const drillRelation = useDrillRelation();

    const [modal, setModal] = useState<Modal>(null);
    const [modalError, setModalError] = useState<string | null>(null);
    const [modalFieldErrors, setModalFieldErrors] = useState<Record<string, string>>({});
    const modalRef = useRef<Modal>(null);
    useEffect(() => {
        modalRef.current = modal;
    }, [modal]);

    const [tryResult, setTryResult] = useState<unknown>(null);
    const [tryHasResult, setTryHasResult] = useState(false);
    const [probeResult, setProbeResult] = useState<ProbeResult | null>(null);
    const [probeError, setProbeError] = useState<string | null>(null);
    // Relation editor: full routes fetched on select to power the field pickers.
    const [relationListRoute, setRelationListRoute] = useState<ApiRoute | null>(null);
    const [relationDetailRoute, setRelationDetailRoute] = useState<ApiRoute | null>(null);
    const [drillResult, setDrillResult] = useState<DrillResult | null>(null);

    const state: 'loading' | 'ready' | 'error' | 'empty' = connectorsQuery.isLoading
        ? 'loading'
        : connectorsQuery.isError
          ? 'error'
          : (connectorsQuery.data?.length ?? 0) === 0
            ? 'empty'
            : 'ready';

    const connectors = connectorsQuery.data ?? [];
    const projects = projectsQuery.data ?? [];

    function openModalReset(next: Modal) {
        setModalError(null);
        setModalFieldErrors({});
        setModal(next);
    }

    function closeModal() {
        setModal(null);
        setTryResult(null);
        setTryHasResult(false);
        setProbeResult(null);
        setProbeError(null);
    }

    function applyError(e: unknown, guard: () => boolean) {
        if (!guard()) return;
        const { message, fieldErrors } = toAdminError(e);
        setModalError(message);
        setModalFieldErrors(fieldErrors);
    }

    // --- connector CRUD ---

    async function handleConnectorSubmit(payload: ConnectorPayload) {
        const current = modal;
        if (current?.kind !== 'connector-create' && current?.kind !== 'connector-edit') return;
        setModalError(null);
        setModalFieldErrors({});
        try {
            if (current.kind === 'connector-edit') {
                await updateConnector.mutateAsync({ id: current.connector.id, payload });
                toast.success('Connector updated.', 'toast-api-connector-updated');
            } else {
                await createConnector.mutateAsync(payload);
                toast.success('Connector created.', 'toast-api-connector-created');
            }
            closeModal();
        } catch (e) {
            applyError(e, () => {
                const open = modalRef.current;
                return open?.kind === 'connector-create' || open?.kind === 'connector-edit';
            });
        }
    }

    async function handleDeleteConnector(connector: ApiConnector) {
        try {
            await deleteConnector.mutateAsync(connector.id);
            toast.success('Connector removed.', 'toast-api-connector-deleted');
        } catch (e) {
            toast.error(toAdminError(e).message, 'toast-api-connector-error');
        }
    }

    // --- auth profile CRUD ---

    async function handleAuthSubmit(payload: AuthProfilePayload) {
        const current = modal;
        if (current?.kind !== 'auth-create' && current?.kind !== 'auth-edit') return;
        setModalError(null);
        setModalFieldErrors({});
        try {
            if (current.kind === 'auth-edit') {
                await updateAuthProfile.mutateAsync({ profileId: current.profile.id, payload });
                toast.success('Auth profile updated.', 'toast-api-auth-updated');
            } else {
                await createAuthProfile.mutateAsync({ connectorId: current.connector.id, payload });
                toast.success('Auth profile created.', 'toast-api-auth-created');
            }
            closeModal();
        } catch (e) {
            applyError(e, () => {
                const open = modalRef.current;
                return open?.kind === 'auth-create' || open?.kind === 'auth-edit';
            });
        }
    }

    // --- route CRUD ---

    async function handleDeleteRoute(routeSummary: ApiRouteSummary) {
        try {
            await deleteRoute.mutateAsync(routeSummary.id);
            toast.success('Route removed.', 'toast-api-route-deleted');
        } catch (e) {
            toast.error(toAdminError(e).message, 'toast-api-route-error');
        }
    }

    // Open the "Prova tool" playground — needs the FULL route (parameters,
    // schemas), which the list summary does not carry. Fetch it on demand.
    const [loadingRouteId, setLoadingRouteId] = useState<number | null>(null);

    async function openRouteTry(routeId: number) {
        setLoadingRouteId(routeId);
        try {
            const route = await apiConnectorsApi.showRoute(routeId);
            openModalReset({ kind: 'route-try', route });
        } catch (e) {
            toast.error(toAdminError(e).message, 'toast-api-route-error');
        } finally {
            setLoadingRouteId(null);
        }
    }

    // Open the unified Route Workspace (config + "Prova & esplora" console). For
    // an existing route we fetch the FULL row (parameters, schemas); for a new
    // route we open with `route: null`.
    async function openRouteWorkspace(connector: ApiConnector, routeId: number | null) {
        if (routeId === null) {
            openModalReset({ kind: 'route-workspace', connector, route: null });
            return;
        }
        setLoadingRouteId(routeId);
        try {
            const route = await apiConnectorsApi.showRoute(routeId);
            openModalReset({ kind: 'route-workspace', connector, route });
        } catch (e) {
            toast.error(toAdminError(e).message, 'toast-api-route-error');
        } finally {
            setLoadingRouteId(null);
        }
    }

    // --- regenerate / activate / disable / try ---

    async function handleRegenerate(routeId: number) {
        try {
            await regenerateDescription.mutateAsync(routeId);
            toast.success('Description regenerated.', 'toast-api-route-regenerated');
        } catch (e) {
            toast.error(toAdminError(e).message, 'toast-api-route-error');
        }
    }

    async function handleActivate(routeId: number) {
        try {
            await activateRoute.mutateAsync(routeId);
            toast.success('Route activated.', 'toast-api-route-activated');
        } catch (e) {
            toast.error(toAdminError(e).message, 'toast-api-route-error');
        }
    }

    async function handleDisable(routeId: number) {
        try {
            await disableRoute.mutateAsync(routeId);
            toast.success('Route disabled.', 'toast-api-route-disabled');
        } catch (e) {
            toast.error(toAdminError(e).message, 'toast-api-route-error');
        }
    }

    async function handleRunTry(args: Record<string, unknown>) {
        const current = modal;
        if (current?.kind !== 'route-try') return;
        setModalError(null);
        try {
            const result = await tryRoute.mutateAsync({ routeId: current.route.id, args });
            setTryResult(result);
            setTryHasResult(true);
        } catch (e) {
            if (modalRef.current?.kind === 'route-try') {
                setTryHasResult(false);
                setModalError(toAdminError(e).message);
            }
        }
    }

    // Free-endpoint playground: fire an ad-hoc no-persist probe and read the
    // outcome. A failed upstream call is a valid ProbeResult (ok:false) rendered
    // by the viewer; only a request/transport failure sets probeError (R14).
    async function handleProbe(payload: ProbePayload) {
        setProbeError(null);
        try {
            const result = await probeEndpoint.mutateAsync(payload);
            if (modalRef.current?.kind === 'probe') {
                setProbeResult(result);
            }
        } catch (e) {
            if (modalRef.current?.kind === 'probe') {
                setProbeResult(null);
                setProbeError(toAdminError(e).message);
            }
        }
    }

    function openProbe() {
        setProbeResult(null);
        setProbeError(null);
        openModalReset({ kind: 'probe' });
    }

    // --- relations (List → Detail) ---

    function openRelationEditor(connector: ApiConnector, relation: ApiRouteRelation | null) {
        setRelationListRoute(null);
        setRelationDetailRoute(null);
        if (relation) {
            openModalReset({ kind: 'relation-edit', connector, relation });
            // Pre-fetch both sides so the field pickers are populated on open.
            void selectRelationRoute('list', relation.list_route_id);
            void selectRelationRoute('detail', relation.detail_route_id);
        } else {
            openModalReset({ kind: 'relation-create', connector });
        }
    }

    async function selectRelationRoute(side: 'list' | 'detail', routeId: number | null) {
        if (routeId === null) {
            if (side === 'list') setRelationListRoute(null);
            else setRelationDetailRoute(null);
            return;
        }
        try {
            const route = await apiConnectorsApi.showRoute(routeId);
            if (side === 'list') setRelationListRoute(route);
            else setRelationDetailRoute(route);
        } catch {
            // Suggestions are a nice-to-have; a fetch failure just means no datalist.
            if (side === 'list') setRelationListRoute(null);
            else setRelationDetailRoute(null);
        }
    }

    async function handleRelationSubmit(payload: RelationPayload) {
        const current = modal;
        if (current?.kind !== 'relation-create' && current?.kind !== 'relation-edit') return;
        setModalError(null);
        try {
            if (current.kind === 'relation-edit') {
                await updateRelation.mutateAsync({ relationId: current.relation.id, payload });
                toast.success('Relation saved.', 'toast-api-relation-saved');
            } else {
                await createRelation.mutateAsync({ connectorId: current.connector.id, payload });
                toast.success('Relation created.', 'toast-api-relation-created');
            }
            closeModal();
        } catch (e) {
            if (modalRef.current?.kind === 'relation-create' || modalRef.current?.kind === 'relation-edit') {
                setModalError(toAdminError(e).message);
            }
        }
    }

    async function handleDeleteRelation(relationId: number) {
        try {
            await deleteRelation.mutateAsync(relationId);
            toast.success('Relation removed.', 'toast-api-relation-deleted');
        } catch (e) {
            toast.error(toAdminError(e).message, 'toast-api-relation-error');
        }
    }

    function openRelationDrill(relation: ApiRouteRelation) {
        setDrillResult(null);
        openModalReset({ kind: 'relation-drill', relation });
    }

    async function handleDrill(payload: { list_item?: Record<string, unknown>; item_index?: number }) {
        const current = modal;
        if (current?.kind !== 'relation-drill') return;
        setModalError(null);
        try {
            const result = await drillRelation.mutateAsync({ relationId: current.relation.id, payload });
            if (modalRef.current?.kind === 'relation-drill') {
                setDrillResult(result);
            }
        } catch (e) {
            if (modalRef.current?.kind === 'relation-drill') {
                setDrillResult(null);
                setModalError(toAdminError(e).message);
            }
        }
    }

    return (
        <AdminShell section="api-connectors">
            <ToastHost />
            <div
                data-testid="api-connectors-view"
                data-state={state}
                style={{ display: 'flex', flexDirection: 'column', gap: 14 }}
            >
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12 }}>
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
                            API Connectors
                        </h1>
                        <p style={{ fontSize: 12.5, color: 'var(--fg-3)', margin: 0 }}>
                            Wrap any HTTP API as one or more LLM tools — define routes, test them live,
                            then activate the ones the chat may call.
                        </p>
                    </div>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <button
                            type="button"
                            data-testid="api-connector-probe"
                            onClick={openProbe}
                            style={buttonStyle('secondary', false)}
                        >
                            Probe endpoint
                        </button>
                        <button
                            type="button"
                            data-testid="api-connector-create"
                            onClick={() => openModalReset({ kind: 'connector-create' })}
                            style={buttonStyle('primary', false)}
                        >
                            + New API connector
                        </button>
                    </div>
                </div>

                {state === 'loading' && (
                    <div
                        data-testid="api-connectors-loading"
                        role="status"
                        aria-busy="true"
                        style={emptyBlockStyle()}
                    >
                        Loading API connectors…
                    </div>
                )}

                {state === 'error' && (
                    <div
                        data-testid="api-connectors-error"
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
                        Could not load API connectors.{' '}
                        <button
                            type="button"
                            data-testid="api-connectors-retry"
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
                    <div data-testid="api-connectors-empty" role="status" style={emptyBlockStyle()}>
                        No API connectors yet. Create one to expose an external API as chat tools.
                    </div>
                )}

                {state === 'ready' && (
                    <div
                        data-testid="api-connectors-grid"
                        style={{ display: 'flex', flexDirection: 'column', gap: 12 }}
                    >
                        {connectors.map((connector) => (
                            <ConnectorCard
                                key={connector.id}
                                connector={connector}
                                loadingRouteId={loadingRouteId}
                                onEditConnector={() => openModalReset({ kind: 'connector-edit', connector })}
                                onDeleteConnector={() => handleDeleteConnector(connector)}
                                onAddAuthProfile={() => openModalReset({ kind: 'auth-create', connector })}
                                onEditAuthProfile={(profile) =>
                                    openModalReset({ kind: 'auth-edit', connector, profile })
                                }
                                onAddRoute={() => openRouteWorkspace(connector, null)}
                                onEditRoute={(routeId) => openRouteWorkspace(connector, routeId)}
                                onTestRoute={(routeId) => openRouteWorkspace(connector, routeId)}
                                onTryRoute={(routeId) => openRouteTry(routeId)}
                                onDeleteRoute={handleDeleteRoute}
                                onActivateRoute={handleActivate}
                                onDisableRoute={handleDisable}
                                onRegenerateRoute={handleRegenerate}
                                onAddRelation={() => openRelationEditor(connector, null)}
                                onEditRelation={(relation) => openRelationEditor(connector, relation)}
                                onDrillRelation={openRelationDrill}
                                onDeleteRelation={handleDeleteRelation}
                            />
                        ))}
                    </div>
                )}
            </div>

            {(modal?.kind === 'connector-create' || modal?.kind === 'connector-edit') && (
                <ConnectorForm
                    key={modal.kind === 'connector-edit' ? `connector-edit-${modal.connector.id}` : 'connector-create'}
                    connector={modal.kind === 'connector-edit' ? modal.connector : null}
                    projects={projects}
                    onSubmit={handleConnectorSubmit}
                    onClose={closeModal}
                    submitError={modalError}
                    fieldErrors={modalFieldErrors}
                    isSubmitting={createConnector.isPending || updateConnector.isPending}
                />
            )}

            {(modal?.kind === 'auth-create' || modal?.kind === 'auth-edit') && (
                <AuthProfileForm
                    key={modal.kind === 'auth-edit' ? `auth-edit-${modal.profile.id}` : `auth-create-${modal.connector.id}`}
                    profile={modal.kind === 'auth-edit' ? modal.profile : null}
                    onSubmit={handleAuthSubmit}
                    onClose={closeModal}
                    submitError={modalError}
                    fieldErrors={modalFieldErrors}
                    isSubmitting={createAuthProfile.isPending || updateAuthProfile.isPending}
                />
            )}

            {modal?.kind === 'route-workspace' && (
                <RouteWorkspace
                    key={modal.route ? `route-workspace-${modal.route.id}` : `route-workspace-new-${modal.connector.id}`}
                    connector={modal.connector}
                    route={modal.route}
                    onClose={closeModal}
                    onSaved={closeModal}
                />
            )}

            {modal?.kind === 'route-try' && (
                <TryToolModal
                    key={`route-try-${modal.route.id}`}
                    route={modal.route}
                    result={tryResult}
                    hasResult={tryHasResult}
                    onRun={handleRunTry}
                    onClose={closeModal}
                    isRunning={tryRoute.isPending}
                    error={modalError}
                />
            )}

            {modal?.kind === 'probe' && (
                <FreeEndpointModal
                    onSend={handleProbe}
                    result={probeResult}
                    error={probeError}
                    isSending={probeEndpoint.isPending}
                    onClose={closeModal}
                />
            )}

            {(modal?.kind === 'relation-create' || modal?.kind === 'relation-edit') && (
                <RelationEditor
                    key={modal.kind === 'relation-edit' ? `relation-edit-${modal.relation.id}` : `relation-create-${modal.connector.id}`}
                    routes={modal.connector.routes ?? []}
                    relation={modal.kind === 'relation-edit' ? modal.relation : null}
                    listRouteFull={relationListRoute}
                    detailRouteFull={relationDetailRoute}
                    onSelectListRoute={(id) => void selectRelationRoute('list', id)}
                    onSelectDetailRoute={(id) => void selectRelationRoute('detail', id)}
                    onSubmit={handleRelationSubmit}
                    onClose={closeModal}
                    submitError={modalError}
                    isSubmitting={createRelation.isPending || updateRelation.isPending}
                />
            )}

            {modal?.kind === 'relation-drill' && (
                <DrillTestPanel
                    key={`relation-drill-${modal.relation.id}`}
                    relation={modal.relation}
                    result={drillResult}
                    onDrill={handleDrill}
                    onClose={closeModal}
                    isDrilling={drillRelation.isPending}
                    error={modalError}
                />
            )}
        </AdminShell>
    );
}

function emptyBlockStyle(): React.CSSProperties {
    return {
        padding: 28,
        textAlign: 'center',
        color: 'var(--fg-3)',
        border: '1px dashed var(--hairline)',
        borderRadius: 10,
    };
}

// ---------------------------------------------------------------------------
// Connector card (one per connector; lists routes + auth profiles)
// ---------------------------------------------------------------------------

interface ConnectorCardProps {
    connector: ApiConnector;
    loadingRouteId: number | null;
    onEditConnector: () => void;
    onDeleteConnector: () => void;
    onAddAuthProfile: () => void;
    onEditAuthProfile: (profile: ApiAuthProfile) => void;
    onAddRoute: () => void;
    onEditRoute: (routeId: number) => void;
    onTestRoute: (routeId: number) => void;
    onTryRoute: (routeId: number) => void;
    onDeleteRoute: (route: ApiRouteSummary) => void;
    onActivateRoute: (routeId: number) => void;
    onDisableRoute: (routeId: number) => void;
    onRegenerateRoute: (routeId: number) => void;
    onAddRelation: () => void;
    onEditRelation: (relation: ApiRouteRelation) => void;
    onDrillRelation: (relation: ApiRouteRelation) => void;
    onDeleteRelation: (relationId: number) => void;
}

function ConnectorCard({
    connector,
    loadingRouteId,
    onEditConnector,
    onDeleteConnector,
    onAddAuthProfile,
    onEditAuthProfile,
    onAddRoute,
    onEditRoute,
    onTestRoute,
    onTryRoute,
    onDeleteRoute,
    onActivateRoute,
    onDisableRoute,
    onRegenerateRoute,
    onAddRelation,
    onEditRelation,
    onDrillRelation,
    onDeleteRelation,
}: ConnectorCardProps) {
    const routes = connector.routes ?? [];
    const authProfiles = connector.auth_profiles ?? [];
    const relations = connector.relations ?? [];

    return (
        <section
            data-testid={`api-connector-${connector.id}-card`}
            data-active={connector.is_active ? 'true' : 'false'}
            style={{
                border: '1px solid var(--panel-border, rgba(255,255,255,.12))',
                borderRadius: 12,
                background: 'var(--panel, rgba(255,255,255,.02))',
                padding: 14,
                display: 'flex',
                flexDirection: 'column',
                gap: 10,
            }}
        >
            <header style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'flex-start' }}>
                <div>
                    <h2 style={{ margin: 0, fontSize: 15, color: 'var(--fg-0)' }}>
                        {connector.name}
                        {!connector.is_active && (
                            <span style={{ marginLeft: 8, fontSize: 11, color: 'var(--fg-3)' }}>(inactive)</span>
                        )}
                    </h2>
                    {connector.description && (
                        <p style={{ margin: '2px 0 0', fontSize: 12, color: 'var(--fg-3)' }}>
                            {connector.description}
                        </p>
                    )}
                    <p style={{ margin: '4px 0 0', fontSize: 11, color: 'var(--fg-3)' }}>
                        {connector.base_url ?? 'no base URL'} ·{' '}
                        {connector.project_key ? `project ${connector.project_key}` : 'tenant default'}
                    </p>
                </div>
                <div style={{ display: 'flex', gap: 6 }}>
                    <button
                        type="button"
                        data-testid={`api-connector-${connector.id}-edit`}
                        onClick={onEditConnector}
                        style={buttonStyle('secondary', false)}
                    >
                        Edit
                    </button>
                    <button
                        type="button"
                        data-testid={`api-connector-${connector.id}-delete`}
                        onClick={onDeleteConnector}
                        style={buttonStyle('danger', false)}
                    >
                        Remove
                    </button>
                </div>
            </header>

            {/* Auth profiles */}
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, alignItems: 'center' }}>
                <span style={{ fontSize: 11, color: 'var(--fg-3)' }}>Auth profiles:</span>
                {authProfiles.length === 0 && (
                    <span data-testid={`api-connector-${connector.id}-auth-empty`} style={{ fontSize: 11, color: 'var(--fg-3)' }}>
                        none
                    </span>
                )}
                {authProfiles.map((profile) => (
                    <button
                        key={profile.id}
                        type="button"
                        data-testid={`api-connector-${connector.id}-auth-${profile.id}`}
                        onClick={() => onEditAuthProfile(profile)}
                        title={profile.has_credentials ? 'Configured' : 'No credentials'}
                        style={{
                            fontSize: 11,
                            padding: '2px 8px',
                            borderRadius: 999,
                            border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
                            background: 'transparent',
                            color: 'var(--fg-1)',
                            cursor: 'pointer',
                        }}
                    >
                        {profile.type}
                        {profile.id === connector.default_auth_profile_id ? ' (default)' : ''}
                    </button>
                ))}
                <button
                    type="button"
                    data-testid={`api-connector-${connector.id}-auth-add`}
                    onClick={onAddAuthProfile}
                    style={{
                        fontSize: 11,
                        padding: '2px 8px',
                        borderRadius: 999,
                        border: '1px dashed var(--panel-border, rgba(255,255,255,.25))',
                        background: 'transparent',
                        color: 'var(--accent, #818cf8)',
                        cursor: 'pointer',
                    }}
                >
                    + Auth profile
                </button>
            </div>

            {/* Routes */}
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span style={{ fontSize: 12, fontWeight: 600, color: 'var(--fg-2)' }}>
                    Routes ({routes.length})
                </span>
                <button
                    type="button"
                    data-testid={`api-connector-${connector.id}-route-add`}
                    onClick={onAddRoute}
                    style={buttonStyle('secondary', false)}
                >
                    + Add route
                </button>
            </div>

            {routes.length === 0 && (
                <p data-testid={`api-connector-${connector.id}-routes-empty`} style={{ margin: 0, fontSize: 12, color: 'var(--fg-3)' }}>
                    No routes yet.
                </p>
            )}

            {routes.map((route) => (
                <RouteRow
                    key={route.id}
                    connectorId={connector.id}
                    route={route}
                    loading={loadingRouteId === route.id}
                    onEdit={() => onEditRoute(route.id)}
                    onTest={() => onTestRoute(route.id)}
                    onTry={() => onTryRoute(route.id)}
                    onDelete={() => onDeleteRoute(route)}
                    onActivate={() => onActivateRoute(route.id)}
                    onDisable={() => onDisableRoute(route.id)}
                    onRegenerate={() => onRegenerateRoute(route.id)}
                />
            ))}

            {/* Relations (List → Detail) */}
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 4 }}>
                <span style={{ fontSize: 12, fontWeight: 600, color: 'var(--fg-2)' }}>
                    Relations ({relations.length})
                </span>
                <button
                    type="button"
                    data-testid={`api-connector-${connector.id}-relation-add`}
                    onClick={onAddRelation}
                    style={buttonStyle('secondary', false)}
                >
                    + Add relation
                </button>
            </div>

            {relations.length === 0 && (
                <p data-testid={`api-connector-${connector.id}-relations-empty`} style={{ margin: 0, fontSize: 12, color: 'var(--fg-3)' }}>
                    No list → detail relations yet.
                </p>
            )}

            {relations.map((relation) => (
                <div
                    key={relation.id}
                    data-testid={`api-connector-${connector.id}-relation-${relation.id}`}
                    style={{
                        border: '1px solid var(--hairline, rgba(255,255,255,.1))',
                        borderRadius: 8,
                        padding: 8,
                        display: 'flex',
                        gap: 8,
                        alignItems: 'center',
                        flexWrap: 'wrap',
                    }}
                >
                    <code style={{ fontSize: 11.5, color: 'var(--fg-1)' }}>
                        {relation.list_route?.slug ?? `#${relation.list_route_id}`}
                        <span aria-hidden style={{ color: 'var(--fg-3)' }}> → </span>
                        {relation.detail_route?.slug ?? `#${relation.detail_route_id}`}
                    </code>
                    <span style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>
                        {relation.field_map.length} map{relation.field_map.length === 1 ? '' : 's'}
                    </span>
                    <div style={{ display: 'flex', gap: 6, marginLeft: 'auto' }}>
                        <button
                            type="button"
                            data-testid={`api-connector-${connector.id}-relation-${relation.id}-drill`}
                            onClick={() => onDrillRelation(relation)}
                            style={buttonStyle('secondary', false)}
                        >
                            Drill-test
                        </button>
                        <button
                            type="button"
                            data-testid={`api-connector-${connector.id}-relation-${relation.id}-edit`}
                            onClick={() => onEditRelation(relation)}
                            style={buttonStyle('secondary', false)}
                        >
                            Edit
                        </button>
                        <button
                            type="button"
                            data-testid={`api-connector-${connector.id}-relation-${relation.id}-remove`}
                            onClick={() => onDeleteRelation(relation.id)}
                            style={buttonStyle('danger', false)}
                        >
                            Remove
                        </button>
                    </div>
                </div>
            ))}
        </section>
    );
}

// ---------------------------------------------------------------------------
// Single route row
// ---------------------------------------------------------------------------

interface RouteRowProps {
    connectorId: number;
    route: ApiRouteSummary;
    loading: boolean;
    onEdit: () => void;
    onTest: () => void;
    onTry: () => void;
    onDelete: () => void;
    onActivate: () => void;
    onDisable: () => void;
    onRegenerate: () => void;
}

function RouteRow({
    connectorId,
    route,
    loading,
    onEdit,
    onTest,
    onTry,
    onDelete,
    onActivate,
    onDisable,
    onRegenerate,
}: RouteRowProps) {
    const badge = routeStatusBadge(route.status);
    const typeBadge = endpointTypeBadge(route.endpoint_type);
    const base = `api-connector-${connectorId}-route-${route.id}`;
    const canActivate = route.status === 'tested';
    const canDisable = route.status === 'active';
    const canTry = route.status === 'active' || route.status === 'tested';

    return (
        <div
            data-testid={base}
            style={{
                border: '1px solid var(--hairline, rgba(255,255,255,.1))',
                borderRadius: 8,
                padding: 8,
                display: 'flex',
                flexWrap: 'wrap',
                gap: 8,
                alignItems: 'center',
            }}
        >
            <span
                style={{
                    fontSize: 10.5,
                    fontWeight: 700,
                    padding: '1px 6px',
                    borderRadius: 4,
                    background: 'var(--bg-3, rgba(255,255,255,.06))',
                    color: 'var(--fg-2)',
                    fontFamily: 'var(--font-mono, monospace)',
                }}
            >
                {route.http_method}
            </span>
            <span style={{ fontSize: 13, color: 'var(--fg-0)' }}>{route.name}</span>
            <code style={{ fontSize: 11, color: 'var(--fg-3)' }}>{route.slug}</code>
            <span
                data-testid={`${base}-status`}
                data-status={route.status}
                role="status"
                style={{
                    fontSize: 10.5,
                    fontWeight: 600,
                    padding: '2px 8px',
                    borderRadius: 999,
                    background: badge.background,
                    border: `1px solid ${badge.border}`,
                    color: badge.color,
                }}
            >
                {badge.label}
            </span>
            <span
                data-testid={`${base}-endpoint-type`}
                data-endpoint-type={route.endpoint_type}
                title="Endpoint type (list / detail)"
                style={{
                    fontSize: 10.5,
                    fontWeight: 600,
                    padding: '2px 8px',
                    borderRadius: 999,
                    background: typeBadge.background,
                    border: `1px solid ${typeBadge.border}`,
                    color: typeBadge.color,
                }}
            >
                {typeBadge.label}
            </span>
            <span
                data-testid={`${base}-mode`}
                style={{ fontSize: 10.5, color: 'var(--fg-3)' }}
            >
                mode: {route.mode}
            </span>

            <div style={{ display: 'flex', gap: 6, marginLeft: 'auto', flexWrap: 'wrap' }}>
                <button
                    type="button"
                    data-testid={`${base}-test`}
                    onClick={onTest}
                    disabled={loading}
                    style={buttonStyle('secondary', loading)}
                >
                    {loading ? 'Loading…' : 'Test'}
                </button>
                <button
                    type="button"
                    data-testid={`${base}-try`}
                    onClick={onTry}
                    disabled={loading || !canTry}
                    title={canTry ? undefined : 'Test the route first'}
                    style={buttonStyle('secondary', loading || !canTry)}
                >
                    Try
                </button>
                <button
                    type="button"
                    data-testid={`${base}-regenerate`}
                    onClick={onRegenerate}
                    style={buttonStyle('secondary', false)}
                >
                    Regenerate
                </button>
                {canActivate && (
                    <button
                        type="button"
                        data-testid={`${base}-activate`}
                        onClick={onActivate}
                        style={buttonStyle('primary', false)}
                    >
                        Activate
                    </button>
                )}
                {canDisable && (
                    <button
                        type="button"
                        data-testid={`${base}-disable`}
                        onClick={onDisable}
                        style={buttonStyle('secondary', false)}
                    >
                        Disable
                    </button>
                )}
                <button
                    type="button"
                    data-testid={`${base}-edit`}
                    onClick={onEdit}
                    disabled={loading}
                    style={buttonStyle('secondary', loading)}
                >
                    Edit
                </button>
                <button
                    type="button"
                    data-testid={`${base}-delete`}
                    onClick={onDelete}
                    style={buttonStyle('danger', false)}
                >
                    Remove
                </button>
            </div>
        </div>
    );
}
