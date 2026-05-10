/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * hooks/useI18n.ts`. Verbatim copy modulo relative-path imports.
 */
import { useMemo } from 'react';
import type { I18nLocale } from '../i18n/messages';
import { buildMetricLabel, getMessage, resolveLocale } from '../i18n/messages';
import { useAppContext } from '../context/AppContext';

type MessageKey = string;

export const useI18n = () => {
  const { config } = useAppContext();
  const locale = useMemo<I18nLocale>(() => resolveLocale(config?.locale), [config?.locale]);

  const t = (key: MessageKey, fallback = '') => getMessage(locale, key, fallback);
  const metricLabel = (key: string, fallback = key) => buildMetricLabel(locale, key, fallback);

  return {
    locale,
    t,
    metricLabel,
  };
};
