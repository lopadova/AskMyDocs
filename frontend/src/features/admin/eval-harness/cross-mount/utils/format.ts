/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * utils/format.ts`.
 *
 * v4.4/W3 Copilot iter 2 finding #5 (i18n): the upstream package
 * hard-coded `it-IT` for `formatDateTime` and `formatNumber` even
 * though it ships an en/it i18n catalogue. The cross-mount fixes
 * this by accepting an optional `locale` argument; the
 * `useFormatters` hook below pre-binds the active locale from
 * `useAppContext().config.locale` so callers can drop in
 * `formatDateTime(value)` without re-fetching the locale at every
 * call site.
 *
 * Backwards-compatible call shape: every existing call (passing only
 * `value`) keeps working — the default falls back to the package's
 * historical `it-IT` behaviour, but new code paths and the bundled
 * `useFormatters` hook prefer the active app locale.
 */
import { useMemo } from 'react';
import { useAppContext } from '../context/AppContext';
import { resolveLocale, type I18nLocale } from '../i18n/messages';

const DEFAULT_INTL_LOCALE = 'it-IT';

const intlLocaleFor = (locale: I18nLocale | string | undefined): string => {
  if (typeof locale !== 'string' || locale.trim() === '') {
    return DEFAULT_INTL_LOCALE;
  }

  const lowered = locale.trim().toLowerCase();

  if (lowered.startsWith('it')) {
    return 'it-IT';
  }

  if (lowered.startsWith('en')) {
    return 'en-US';
  }

  return locale;
};

export const formatDateTime = (value: string, locale?: I18nLocale | string): string => {
  const parsed = new Date(value);

  if (Number.isNaN(parsed.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat(intlLocaleFor(locale), {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(parsed);
};

export const formatPercent = (value: number): string => {
  if (Number.isNaN(value)) {
    return '—';
  }

  return `${(value * 100).toFixed(1)}%`;
};

export const formatNumber = (value: number, locale?: I18nLocale | string): string =>
  new Intl.NumberFormat(intlLocaleFor(locale)).format(value);

/**
 * Hook returning the three formatters pre-bound to the active app
 * locale. Use this from page components instead of the bare
 * functions so the SPA's en/it switch actually changes the
 * date/number output. `formatPercent` is locale-agnostic (always
 * `XX.X%` ASCII) and is included here for symmetry only.
 */
export const useFormatters = () => {
  const { config } = useAppContext();
  const locale = useMemo<I18nLocale>(() => resolveLocale(config?.locale), [config?.locale]);

  return useMemo(
    () => ({
      formatDateTime: (value: string) => formatDateTime(value, locale),
      formatNumber: (value: number) => formatNumber(value, locale),
      formatPercent,
    }),
    [locale],
  );
};
