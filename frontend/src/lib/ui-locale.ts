import { useCallback, useSyncExternalStore } from 'react';
import { useAuthStore } from './auth-store';
import { IT_MESSAGES } from './ui-messages.it';

export type UiLocale = 'it' | 'en';
const EVENT = 'amd:locale';

export function getUiLocale(): UiLocale {
    const state = useAuthStore.getState?.();
    const saved = state?.user?.locale;
    if (saved) return saved.toLowerCase().startsWith('it') ? 'it' : 'en';
    // Older servers and embedded clients omit locale; retain their English interface.
    if (state?.user) return 'en';
    try { return localStorage.getItem('amd-ui-locale') === 'en' ? 'en' : 'it'; }
    catch { return 'it'; }
}

function subscribe(listener: () => void) {
    const unsubscribe = useAuthStore.subscribe?.(listener) ?? (() => undefined);
    window.addEventListener(EVENT, listener);
    window.addEventListener('storage', listener);
    return () => {
        unsubscribe();
        window.removeEventListener(EVENT, listener);
        window.removeEventListener('storage', listener);
    };
}

export function setLocalUiLocale(locale: UiLocale) {
    try { localStorage.setItem('amd-ui-locale', locale); } catch { /* Private browsing. */ }
    window.dispatchEvent(new Event(EVENT));
}

export function translateUi(message: string, locale: UiLocale = getUiLocale(), values?: Record<string, string | number>): string {
    const english = message.replace(/&amp;/g, '&').replace(/&nbsp;/g, ' ').replace(/&rsquo;/g, '’').replace(/&ldquo;/g, '“').replace(/&rdquo;/g, '”').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&#123;/g, '{').replace(/&#125;/g, '}');
    const translated = locale === 'it' ? (IT_MESSAGES[message] ?? IT_MESSAGES[english] ?? english) : english;
    return values ? translated.replace(/\{(\w+)\}/g, (token, key: string) => String(values[key] ?? token)) : translated;
}

/** Catalogues contain interface copy only; document and conversation content is never translated. */
export function useUiTranslation() {
    const locale = useSyncExternalStore(subscribe, getUiLocale, () => 'it' as const);
    const t = useCallback((message: string, values?: Record<string, string | number>) => translateUi(message, locale, values), [locale]);
    return { locale, t };
}
