import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { Workbench, WorkbenchHttpError } from '@ui4/workbench-react';
import '@ui4/workbench-react/style.css';
import { Icon } from '../../components/Icons';
import { ensureCsrfCookie, resetCsrf } from '../../lib/api';
import { useAuthStore } from '../../lib/auth-store';
import { useTeamStore } from '../../lib/team-store';
import { workbenchHeaders, workbenchTransport } from './workbench-transport';

const ACCESS_MESSAGES: Record<number, string> = {
    401: 'La sessione non è più valida. Accedi di nuovo per usare il workbench.',
    403: 'Non sei autorizzato a usare il workbench nel team selezionato.',
    419: 'La protezione della sessione è stata aggiornata. Riprova l’azione.',
};

export function WorkbenchView() {
    const [accessMessage, setAccessMessage] = useState<string | null>(null);

    useEffect(() => {
        void ensureCsrfCookie().catch(() => undefined);
    }, []);

    const handleRequestError = useCallback((error: Error) => {
        if (!(error instanceof WorkbenchHttpError)) {
            return;
        }

        if (error.status === 419) {
            resetCsrf();
            void ensureCsrfCookie().catch(() => undefined);
        }

        setAccessMessage(ACCESS_MESSAGES[error.status] ?? null);
    }, []);

    return (
        <div data-testid="ui4-workbench-page" style={{ flex: 1, minWidth: 0 }}>
            {accessMessage !== null && (
                <p role="alert" style={{ margin: '16px 24px 0', color: 'var(--danger, #b42318)' }}>
                    {accessMessage}
                </p>
            )}
            <Workbench
                apiBase="/api/workbench"
                credentials="same-origin"
                transport={workbenchTransport}
                headers={workbenchHeaders}
                voiceEnabled={false}
                onRequestError={handleRequestError}
            />
        </div>
    );
}

/**
 * A separate product surface, deliberately outside the authenticated app
 * dashboard. The only chrome is the AskMyDocs mark and a route to sign in;
 * the workbench itself owns the entire remaining viewport.
 */
export function WorkbenchStandalonePage() {
    const navigate = useNavigate();
    const user = useAuthStore((state) => state.user);
    const loading = useAuthStore((state) => state.loading);
    const currentTeam = useTeamStore((state) => state.currentTeam);

    return (
        <div
            data-testid="ui4-workbench-standalone"
            style={{
                minHeight: '100vh',
                display: 'flex',
                flexDirection: 'column',
                overflow: 'hidden',
                background: 'var(--bg-0)',
                color: 'var(--fg-1)',
                fontFamily: 'var(--font-sans)',
            }}
        >
            <header
                style={{
                    minHeight: 52,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    padding: '0 20px',
                    borderBottom: '1px solid var(--hairline)',
                }}
            >
                <div style={{ display: 'flex', alignItems: 'center', gap: 9, fontWeight: 600, letterSpacing: '-0.01em' }}>
                    <Icon.Logo size={24} />
                    <span>AskMyDocs</span>
                </div>
                {!loading && user === null && (
                    <button
                        type="button"
                        className="btn primary"
                        onClick={() => navigate({ to: '/login', search: { returnTo: '/workbench' } })}
                    >
                        Accedi
                    </button>
                )}
            </header>

            <main style={{ flex: 1, minHeight: 0, display: 'flex' }}>
                {loading ? null : user === null ? null : currentTeam === null ? (
                    <p role="alert" style={{ margin: '24px', color: 'var(--danger, #b42318)' }}>
                        Il tuo account non ha uno spazio di lavoro disponibile.
                    </p>
                ) : (
                    <WorkbenchView />
                )}
            </main>
        </div>
    );
}
