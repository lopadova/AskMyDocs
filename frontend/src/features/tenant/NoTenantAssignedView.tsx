import type { ReactNode } from 'react';

export function NoTenantAssignedView(): ReactNode {
    return (
        <main
            data-state="empty"
            data-testid="no-tenant-assigned-view"
            style={{
                alignItems: 'center',
                display: 'flex',
                flex: 1,
                justifyContent: 'center',
                padding: 32,
            }}
        >
            <section
                aria-labelledby="no-tenant-assigned-title"
                style={{
                    background: 'var(--bg-1)',
                    border: '1px solid var(--panel-border)',
                    borderRadius: 12,
                    maxWidth: 520,
                    padding: 28,
                    textAlign: 'center',
                }}
            >
                <h1
                    id="no-tenant-assigned-title"
                    style={{ color: 'var(--fg-0)', fontSize: 20, margin: 0 }}
                >
                    Nessun tenant assegnato
                </h1>
                <p style={{ color: 'var(--fg-3)', lineHeight: 1.6, margin: '10px 0 0' }}>
                    Il tuo account non dispone ancora di un contesto operativo.
                    Contatta un amministratore per ricevere una membership.
                </p>
            </section>
        </main>
    );
}
