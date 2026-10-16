import { useCallback, useSyncExternalStore } from 'react';
import { SelectField, SelectOption } from '@/components/ui/select-field';

type LandingLanguage = { code: string; name: string };

/** The public i18n script owns locale, URL and copy; React owns only its Select. */
export function LandingLanguageSelect({ container }: { container: HTMLElement }) {
    const subscribe = useCallback((onChange: () => void) => {
        container.addEventListener('amd:landing-locale-changed', onChange);
        return () => container.removeEventListener('amd:landing-locale-changed', onChange);
    }, [container]);
    const readSnapshot = useCallback(() => JSON.stringify([container.dataset.locale ?? 'it', container.dataset.languages ?? '[]']), [container]);
    const snapshot = useSyncExternalStore(subscribe, readSnapshot, () => '["it","[]"]');
    const [locale, serializedLanguages] = JSON.parse(snapshot) as [string, string];
    const languages = JSON.parse(serializedLanguages) as LandingLanguage[];

    return <SelectField id="language" aria-labelledby="language-label" value={locale} placeholder={locale.toUpperCase()} onValueChange={(next) => {
        container.dispatchEvent(new CustomEvent('amd:landing-locale-request', { detail: { locale: next } }));
    }}>
        {languages.map(({ code, name }) => <SelectOption key={code} value={code} lang={code} textValue={name}>
            <span className="landing-language-code">{code.toUpperCase()}</span><span className="landing-language-name">{name}</span>
        </SelectOption>)}
    </SelectField>;
}
