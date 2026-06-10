import { useEffect, useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { api } from '../../../lib/api';
import { selectCurrentHash, useTeamStore } from '../../../lib/team-store';
import { AdminShell } from '../shell/AdminShell';
import { ToastHost, pushToast } from '../shared/Toast';
import { callbackErrorMessage } from './status-utils';

/*
 * v4.5/W3 — OAuth callback handler.
 *
 * The connector's `initiateOAuth()` builds a provider URL whose
 * `redirect_uri` points back here:
 *
 *   /app/admin/connectors/<key>/callback?code=...&state=...
 *
 * The browser lands on this React route after the user grants (or
 * denies) consent. The component:
 *
 *   1. Reads `code` + `state` from `window.location.search` (we cannot
 *      use TanStack's `validateSearch` because the OAuth flow is
 *      strict about which params survive — using the native API
 *      avoids any router-side mutation of the URL before we read it).
 *   2. Forwards the full querystring to
 *      `/api/admin/connectors/<key>/oauth/callback` (controller
 *      validates the state token + exchanges the code for tokens).
 *   3. On 2xx → navigate to `/app/admin/connectors` with a success toast.
 *   4. On 4xx/5xx → show an inline error + a "Back to connectors"
 *      button. The error message comes from `callbackErrorMessage()`
 *      which maps the HTTP status to a user-facing sentence (R14 —
 *      surface failures loudly; never silent on 4xx/5xx).
 *
 * The `connectorKey` is provided by the parent route (TanStack params).
 */

export interface ConnectorCallbackProps {
    connectorKey: string;
}

type CallbackState =
    | { phase: 'idle' }
    | { phase: 'processing' }
    | { phase: 'success' }
    | { phase: 'error'; message: string; status: number };

export function ConnectorCallback({ connectorKey }: ConnectorCallbackProps) {
    const navigate = useNavigate();
    const teamHash = useTeamStore(selectCurrentHash) ?? '';
    const [state, setState] = useState<CallbackState>({ phase: 'idle' });

    useEffect(() => {
        let cancelled = false;
        // Capture once on mount. React StrictMode double-invokes
        // effects in dev, but the BE callback endpoint is idempotent
        // for already-active rows (it short-circuits via the
        // `status=PENDING` filter — see ConnectorAdminController::oauthCallback).
        setState({ phase: 'processing' });
        const search = window.location.search || '';
        api
            .get(
                `/api/admin/connectors/${encodeURIComponent(connectorKey)}/oauth/callback${search}`,
            )
            .then(() => {
                if (cancelled) return;
                setState({ phase: 'success' });
                pushToast('success', `${connectorKey} connected.`, 'toast-connector-installed');
                // Navigate back to the list after a short tick so the
                // user can see the success state.
                window.setTimeout(() => {
                    if (!cancelled) {
                        // getState(): inside an effect-scheduled timeout —
                        // reading the store imperatively avoids a stale
                        // closure on the hook value.
                        const hash = selectCurrentHash(useTeamStore.getState()) ?? '';
                        navigate({
                            to: '/app/$teamHash/admin/connectors',
                            params: { teamHash: hash },
                        });
                    }
                }, 600);
            })
            .catch((err: unknown) => {
                if (cancelled) return;
                // axios shape: err.response.status; default to 0 for
                // network errors so the message is still deterministic.
                const status =
                    (err as { response?: { status?: number } }).response?.status ?? 0;
                const body = (err as { response?: { data?: { error?: string; message?: string } } })
                    .response?.data;
                const fallback = body?.error ?? body?.message;
                setState({
                    phase: 'error',
                    message: callbackErrorMessage(status, fallback),
                    status,
                });
            });
        return () => {
            cancelled = true;
        };
        // connectorKey is the only meaningful dependency. navigate is
        // stable across renders per TanStack Router contract.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [connectorKey]);

    return (
        <AdminShell section="connectors">
            <ToastHost />
            <div
                data-testid="admin-connectors-callback"
                data-phase={state.phase}
                data-connector-key={connectorKey}
                style={{
                    flex: 1,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    padding: 40,
                }}
            >
                <div
                    style={{
                        maxWidth: 480,
                        padding: 28,
                        borderRadius: 12,
                        border: '1px solid var(--hairline)',
                        background: 'var(--bg-1)',
                        textAlign: 'center',
                    }}
                >
                    {state.phase === 'processing' && (
                        <>
                            <div
                                data-testid="callback-processing"
                                role="status"
                                aria-busy="true"
                                style={{ fontSize: 16, color: 'var(--fg-0)', marginBottom: 8 }}
                            >
                                Finalising connection…
                            </div>
                            <div style={{ fontSize: 12.5, color: 'var(--fg-3)' }}>
                                Exchanging tokens with {connectorKey}.
                            </div>
                        </>
                    )}

                    {state.phase === 'success' && (
                        <div
                            data-testid="callback-success"
                            role="status"
                            style={{ fontSize: 15, color: 'var(--fg-0)' }}
                        >
                            <div style={{ marginBottom: 6 }}>{connectorKey} connected.</div>
                            <div style={{ fontSize: 12.5, color: 'var(--fg-3)' }}>
                                Returning to the Connectors page…
                            </div>
                        </div>
                    )}

                    {state.phase === 'error' && (
                        <>
                            <div
                                data-testid="callback-error"
                                data-status={state.status}
                                role="alert"
                                style={{
                                    fontSize: 14,
                                    color: '#fca5a5',
                                    marginBottom: 12,
                                    padding: 10,
                                    background: 'rgba(239, 68, 68, 0.10)',
                                    border: '1px solid rgba(239, 68, 68, 0.35)',
                                    borderRadius: 8,
                                    lineHeight: 1.5,
                                }}
                            >
                                {state.message}
                            </div>
                            <button
                                type="button"
                                data-testid="callback-back"
                                className="focus-ring"
                                onClick={() =>
                                    navigate({
                                        to: '/app/$teamHash/admin/connectors',
                                        params: { teamHash },
                                    })
                                }
                                style={{
                                    padding: '6px 14px',
                                    fontSize: 13,
                                    background: 'var(--grad-accent)',
                                    color: '#fff',
                                    border: '1px solid transparent',
                                    borderRadius: 8,
                                    cursor: 'pointer',
                                }}
                            >
                                Back to Connectors
                            </button>
                        </>
                    )}
                </div>
            </div>
        </AdminShell>
    );
}
