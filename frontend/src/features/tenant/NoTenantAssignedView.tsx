import type { ReactNode } from 'react';
import { useUiTranslation } from '../../lib/ui-locale';
import { EditorialArtwork } from '../../components/editorial/EditorialArtwork';

export function NoTenantAssignedView(): ReactNode {
    const { t } = useUiTranslation();
    return (
        <div
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
                <EditorialArtwork artwork="community" className="onboarding-art" />
                <h1
                    id="no-tenant-assigned-title"
                    style={{ color: 'var(--fg-0)', fontSize: 20, margin: 0 }}
                >
                    {t('No organisation assigned')}
                </h1>
                <p style={{ color: 'var(--fg-3)', lineHeight: 1.6, margin: '10px 0 0' }}>
                    {t('Your account does not have a workspace yet. Contact an administrator to join an organisation.')}
                </p>
            </section>
        </div>
    );
}
