import { useState } from 'react';
import { useNavigate, useParams } from '@tanstack/react-router';
import { ConnectorForm } from '../api-connectors/ConnectorForm';
import {
    useApiConnectors,
    useCreateConnector,
    useDeleteConnector,
    useProjectOptions,
    useUpdateConnector,
} from '../api-connectors/api-connectors-hooks';
import type { ApiConnector, ConnectorPayload } from '../api-connectors/api-connectors.api';
import { modalBackdropStyle, modalPanelStyle } from '../api-connectors/styles';
import { useToast } from '../shared/Toast';
import { toAdminError } from '../shared/errors';
import { ApiConnectionTile } from './ApiConnectionTile';

/**
 * The "API connections" zone of the unified Connectors gallery (L1). The API
 * connector is a distinct paradigm from the ingest sources — its deep config
 * (routes / auth / relations / tests) is a whole page — but *creating and
 * listing* a connection is modal-sized, so it belongs on the same page as every
 * other connection. This section owns create / edit / remove inline (reusing the
 * API-connector `ConnectorForm` + hooks) and drills into the dedicated page via
 * "Manage". Self-contained: it fetches its own data and never couples to the
 * ingest gallery's state.
 *
 * R14 — every mutation toasts success/failure. R30 tenant scoping + R32 gating
 * are enforced server-side (the reused `/api/admin/api-connectors` endpoints).
 */

type ApiModal =
    | { kind: 'create' }
    | { kind: 'edit'; connector: ApiConnector }
    | { kind: 'remove'; connector: ApiConnector }
    | null;

const headingStyle: React.CSSProperties = {
    margin: 0,
    fontSize: 13,
    fontWeight: 600,
    textTransform: 'uppercase',
    letterSpacing: '.06em',
    color: 'var(--fg-3)',
};

export function ApiConnectionsSection() {
    const toast = useToast();
    const navigate = useNavigate();
    const { teamHash } = useParams({ strict: false }) as { teamHash?: string };

    const listQuery = useApiConnectors();
    const projectsQuery = useProjectOptions();
    const createConnector = useCreateConnector();
    const updateConnector = useUpdateConnector();
    const deleteConnector = useDeleteConnector();

    const [modal, setModal] = useState<ApiModal>(null);
    const [modalError, setModalError] = useState<string | null>(null);
    const [modalFieldErrors, setModalFieldErrors] = useState<Record<string, string>>({});

    const connectors = listQuery.data ?? [];
    const projects = projectsQuery.data ?? [];

    const state: 'loading' | 'error' | 'empty' | 'ready' = listQuery.isLoading
        ? 'loading'
        : listQuery.isError
          ? 'error'
          : connectors.length === 0
            ? 'empty'
            : 'ready';

    function openModal(next: ApiModal) {
        setModalError(null);
        setModalFieldErrors({});
        setModal(next);
    }

    function goToManage() {
        navigate({ to: '/app/$teamHash/admin/api-connectors', params: { teamHash: teamHash ?? '' } });
    }

    async function handleSubmit(payload: ConnectorPayload) {
        const current = modal;
        if (current?.kind !== 'create' && current?.kind !== 'edit') return;
        setModalError(null);
        setModalFieldErrors({});
        try {
            if (current.kind === 'edit') {
                await updateConnector.mutateAsync({ id: current.connector.id, payload });
                toast.success('API connection updated.', 'toast-api-connector-updated');
            } else {
                await createConnector.mutateAsync(payload);
                toast.success('API connection created.', 'toast-api-connector-created');
            }
            setModal(null);
        } catch (e) {
            const { message, fieldErrors } = toAdminError(e);
            setModalError(message);
            setModalFieldErrors(fieldErrors);
        }
    }

    async function handleRemove(connector: ApiConnector) {
        try {
            await deleteConnector.mutateAsync(connector.id);
            toast.success('API connection removed.', 'toast-api-connector-deleted');
            setModal(null);
        } catch (e) {
            toast.error(toAdminError(e).message, 'toast-api-connector-error');
        }
    }

    return (
        <section
            data-testid="api-connections-section"
            data-state={state}
            style={{ display: 'flex', flexDirection: 'column', gap: 10 }}
        >
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                <h2 style={headingStyle}>API connections</h2>
                <span
                    data-testid="api-connections-count"
                    style={{
                        fontSize: 12,
                        fontWeight: 600,
                        color: 'var(--fg-2)',
                        background: 'var(--bg-2)',
                        borderRadius: 999,
                        padding: '1px 8px',
                    }}
                >
                    {connectors.length}
                </span>
                <div style={{ flex: 1, minWidth: 12 }} />
                <button
                    type="button"
                    data-testid="api-connector-gallery-create"
                    className="focus-ring"
                    onClick={() => openModal({ kind: 'create' })}
                    style={{
                        fontSize: 12.5,
                        fontWeight: 600,
                        padding: '7px 12px',
                        borderRadius: 8,
                        border: '1px solid var(--hairline)',
                        background: 'var(--bg-2)',
                        color: 'var(--fg-0)',
                        cursor: 'pointer',
                    }}
                >
                    + New API connection
                </button>
            </div>

            {state === 'loading' && (
                <div
                    data-testid="api-connections-loading"
                    role="status"
                    aria-busy="true"
                    style={dashedBox()}
                >
                    Loading API connections…
                </div>
            )}

            {state === 'error' && (
                <div data-testid="api-connections-error" role="alert" style={errorBox()}>
                    Could not load API connections.{' '}
                    <button
                        type="button"
                        data-testid="api-connections-retry"
                        className="focus-ring"
                        onClick={() => listQuery.refetch()}
                        style={{
                            marginLeft: 6,
                            padding: '3px 9px',
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
                <div data-testid="api-connections-empty" role="status" style={dashedBox()}>
                    No API connections yet — turn any HTTP endpoint into a live chat tool.
                </div>
            )}

            {state === 'ready' && (
                <div
                    data-testid="api-connections-grid"
                    style={{
                        display: 'grid',
                        gridTemplateColumns: 'repeat(auto-fill, minmax(320px, 1fr))',
                        gap: 12,
                    }}
                >
                    {connectors.map((c) => (
                        <ApiConnectionTile
                            key={c.id}
                            connector={c}
                            onManage={goToManage}
                            onEdit={(conn) => openModal({ kind: 'edit', connector: conn })}
                            onRemove={(conn) => openModal({ kind: 'remove', connector: conn })}
                        />
                    ))}
                </div>
            )}

            {(modal?.kind === 'create' || modal?.kind === 'edit') && (
                <ConnectorForm
                    key={modal.kind === 'edit' ? `api-conn-edit-${modal.connector.id}` : 'api-conn-create'}
                    connector={modal.kind === 'edit' ? modal.connector : null}
                    projects={projects}
                    onSubmit={handleSubmit}
                    onClose={() => setModal(null)}
                    submitError={modalError}
                    fieldErrors={modalFieldErrors}
                    isSubmitting={createConnector.isPending || updateConnector.isPending}
                />
            )}

            {modal?.kind === 'remove' && (
                <div
                    data-testid="api-connection-remove-backdrop"
                    onClick={(e) => {
                        if (e.target === e.currentTarget) setModal(null);
                    }}
                    style={modalBackdropStyle()}
                >
                    <div role="dialog" aria-modal="true" data-testid="api-connection-remove-modal" style={modalPanelStyle(380)}>
                        <h2 style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>Remove API connection</h2>
                        <p style={{ fontSize: 13, color: 'var(--fg-2)', margin: '4px 0 0', lineHeight: 1.5 }}>
                            Remove <strong>{modal.connector.name}</strong> and all its routes, auth profiles and
                            relations? This cannot be undone.
                        </p>
                        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 6 }}>
                            <button
                                type="button"
                                data-testid="api-connection-remove-cancel"
                                className="focus-ring"
                                disabled={deleteConnector.isPending}
                                onClick={() => setModal(null)}
                                style={{
                                    fontSize: 12.5,
                                    padding: '7px 12px',
                                    borderRadius: 8,
                                    border: '1px solid var(--hairline)',
                                    background: 'transparent',
                                    color: 'var(--fg-2)',
                                    cursor: 'pointer',
                                }}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                data-testid="api-connection-remove-confirm"
                                className="focus-ring"
                                disabled={deleteConnector.isPending}
                                onClick={() => handleRemove(modal.connector)}
                                style={{
                                    fontSize: 12.5,
                                    fontWeight: 600,
                                    padding: '7px 12px',
                                    borderRadius: 8,
                                    border: '1px solid rgba(239, 68, 68, 0.45)',
                                    background: 'rgba(239, 68, 68, 0.12)',
                                    color: '#fca5a5',
                                    cursor: 'pointer',
                                }}
                            >
                                {deleteConnector.isPending ? 'Removing…' : 'Remove'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </section>
    );
}

function dashedBox(): React.CSSProperties {
    return {
        padding: 24,
        textAlign: 'center',
        color: 'var(--fg-3)',
        border: '1px dashed var(--hairline)',
        borderRadius: 10,
        fontSize: 13,
    };
}

function errorBox(): React.CSSProperties {
    return {
        padding: 14,
        background: 'rgba(239, 68, 68, 0.08)',
        border: '1px solid rgba(239, 68, 68, 0.30)',
        borderRadius: 10,
        color: '#fca5a5',
        fontSize: 13,
    };
}
