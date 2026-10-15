import { useEffect, type ReactNode } from 'react';
import { Icon } from '../../components/Icons';
import { EditorialArtwork } from '../../components/editorial/EditorialArtwork';
import { LanguagePicker } from '../../components/shell/LanguagePicker';
import { useTheme } from '../../components/shell/hooks';
import { useUiTranslation } from '../../lib/ui-locale';

export type AuthLayoutProps = { title: string; subtitle?: string; children: ReactNode; footer?: ReactNode };

export function AuthLayout({ title, subtitle, children, footer }: AuthLayoutProps) {
    const { t, locale } = useUiTranslation();
    useTheme('dark');
    useEffect(() => { document.documentElement.lang = locale; }, [locale]);
    return <div className="editorial-auth">
        <aside className="editorial-auth-story">
            <EditorialArtwork artwork="knowledge" sizes="(max-width: 800px) 100vw, 50vw" />
            <a className="editorial-brand" href="/landing"><Icon.Logo size={30} /><span>AskMyDocs.</span></a>
            <div className="editorial-auth-caption"><p className="editorial-eyebrow">{t('Knowledge, with its sources.')}</p><h2>{t('A question opens a world.')}</h2><p>{t('Bring your knowledge into focus.')}</p></div>
        </aside>
        <main className="editorial-auth-main">
            <div className="editorial-auth-tools"><a href="/landing">AskMyDocs.</a><LanguagePicker /></div>
            <section className="editorial-auth-form">
                <p className="editorial-eyebrow">AskMyDocs</p><h1>{t(title)}</h1>
                {subtitle && <p className="editorial-auth-subtitle">{t(subtitle)}</p>}
                {children}
                {footer && <footer className="editorial-auth-footer">{footer}</footer>}
            </section>
        </main>
    </div>;
}

export type FieldErrors = Record<string, string[] | string>;

export function FieldError({ errors, name }: { errors: FieldErrors | undefined; name: string }) {
    const { t } = useUiTranslation();
    if (!errors || !errors[name]) {
        return null;
    }
    const raw = errors[name];
    const msg = Array.isArray(raw) ? raw[0] : raw;
    return (
        <div
            data-testid={`${name}-error`}
            style={{ fontSize: 12, color: 'var(--err)', marginTop: 6 }}
            role="alert"
        >
            {t(msg)}
        </div>
    );
}
