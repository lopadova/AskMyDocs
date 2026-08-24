export type TenantWelcomeViewProps = {
    teamName: string;
    onContinue: () => void;
};

/**
 * One-time handoff shown after a tenant-linked registration.
 *
 * The URL owns this state (`/app/welcome/{teamHash}`), so refreshes and E2E
 * assertions are deterministic and no ephemeral store flag can accidentally
 * suppress the welcome.
 */
export function TenantWelcomeView({ teamName, onContinue }: TenantWelcomeViewProps) {
    return (
        <main
            data-testid="tenant-welcome-view"
            data-state="ready"
            aria-labelledby="tenant-welcome-title"
            style={{
                alignItems: 'center',
                display: 'flex',
                justifyContent: 'center',
                minHeight: '100vh',
                padding: 24,
            }}
        >
            <section
                className="panel popin"
                style={{
                    background: 'var(--panel-solid)',
                    maxWidth: 520,
                    padding: 32,
                    textAlign: 'center',
                    width: '100%',
                }}
            >
                <div
                    aria-hidden="true"
                    style={{
                        alignItems: 'center',
                        background: 'rgba(34,197,94,.14)',
                        border: '1px solid rgba(34,197,94,.32)',
                        borderRadius: '50%',
                        color: 'var(--ok)',
                        display: 'inline-flex',
                        fontSize: 24,
                        height: 52,
                        justifyContent: 'center',
                        marginBottom: 18,
                        width: 52,
                    }}
                >
                    ✓
                </div>
                <h1
                    id="tenant-welcome-title"
                    style={{ color: 'var(--fg-0)', fontSize: 24, margin: '0 0 10px' }}
                >
                    Benvenuto in {teamName}
                </h1>
                <p
                    aria-live="polite"
                    data-testid="tenant-welcome-status"
                    style={{
                        color: 'var(--fg-3)',
                        lineHeight: 1.65,
                        margin: '0 0 24px',
                    }}
                >
                    Il tuo invito è stato accettato e il tuo accesso è pronto.
                    Puoi entrare subito nello spazio di lavoro della tua azienda.
                </p>
                <button
                    type="button"
                    className="btn primary"
                    data-testid="tenant-welcome-continue"
                    onClick={onContinue}
                    style={{ justifyContent: 'center', width: '100%' }}
                >
                    Entra in {teamName}
                </button>
            </section>
        </main>
    );
}
