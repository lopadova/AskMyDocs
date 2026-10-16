import { SelectField, SelectOption } from '@/components/ui/select-field';
import { useState } from 'react';
import { updateLocale } from '../../features/auth/auth.api';
import { useAuthStore } from '../../lib/auth-store';
import { setLocalUiLocale, useUiTranslation, type UiLocale } from '../../lib/ui-locale';

export function LanguagePicker() {
    const { locale, t } = useUiTranslation();
    const user = useAuthStore((state) => state.user);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(false);
    async function change(next: UiLocale) {
        setBusy(true); setError(false);
        try {
            if (user) {
                const result = await updateLocale(next);
                useAuthStore.setState((state) => ({ user: state.user ? { ...state.user, locale: result.locale } : null }));
            }
            setLocalUiLocale(next);
            document.documentElement.lang = next;
        } catch { setError(true); }
        finally { setBusy(false); }
    }
    return <div className="language-picker"><SelectField aria-label={t('Interface language')} value={locale} disabled={busy} onValueChange={(value) => void change(value as UiLocale)}><SelectOption value="it">IT</SelectOption><SelectOption value="en">EN</SelectOption></SelectField>{error && <span className="language-picker-error" role="alert">{t('Could not save the language. Try again.')}</span>}</div>;
}
