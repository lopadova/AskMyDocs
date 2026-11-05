import { Check } from 'lucide-react';
import { AuthLayout } from '../auth/AuthLayout';
import { Button } from '../../components/Button';
import { useUiTranslation } from '../../lib/ui-locale';

export type TenantWelcomeViewProps = { teamName: string; onContinue: () => void };

/** The URL owns the one-time invitation handoff, including refreshes. */
export function TenantWelcomeView({ teamName, onContinue }: TenantWelcomeViewProps) {
    const { t } = useUiTranslation();
    return <AuthLayout title={t('Welcome to {name}', { name: teamName })}>
        <section data-testid="tenant-welcome-view" data-state="ready">
            <Check size={28} aria-hidden="true" style={{ color: 'var(--accent-a)', marginBottom: 20 }} />
            <p aria-live="polite" data-testid="tenant-welcome-status" style={{ color: 'var(--fg-2)', lineHeight: 1.7, marginBottom: 24 }}>
                {t('Your invitation has been accepted and your access is ready. Enter your organisation’s workspace.')}
            </p>
            <Button variant="primary" data-testid="tenant-welcome-continue" onClick={onContinue}>
                {t('Enter {name}', { name: teamName })}
            </Button>
        </section>
    </AuthLayout>;
}
