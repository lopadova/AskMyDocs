import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { Workbench, WorkbenchHttpError, type WorkbenchHeader } from '@ui4/workbench-react';
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

const ASK_MY_DOCS_WORKBENCH_HEADER: WorkbenchHeader = {
    name: 'AskMyDocs',
    logoUrl: '/askmydocs-logo.svg',
    caption: 'Il tuo spazio personale',
    href: '/workbench',
    showDate: true,
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
                header={ASK_MY_DOCS_WORKBENCH_HEADER}
                onRequestError={handleRequestError}
            />
        </div>
    );
}

/**
 * A separate product surface, deliberately outside the authenticated app
 * dashboard. The Workbench owns the full viewport and its only signed-in
 * brand is the AskMyDocs mark in its own header.
 */
export function WorkbenchStandalonePage() {
    const navigate = useNavigate();
    const user = useAuthStore((state) => state.user);
    const loading = useAuthStore((state) => state.loading);
    const currentTeam = useTeamStore((state) => state.currentTeam);

    if (!loading && user !== null && currentTeam !== null) {
        return <WorkbenchView />;
    }

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
                {loading ? null : user === null ? null : (
                    <p role="alert" style={{ margin: '24px', color: 'var(--danger, #b42318)' }}>
                        Il tuo account non ha uno spazio di lavoro disponibile.
                    </p>
                )}
            </main>
        </div>
    );
}
