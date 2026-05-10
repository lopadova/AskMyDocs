/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * utils/bootstrap.ts`. Verbatim copy — pure type + parser, no imports
 * to rewrite.
 *
 * The host calls `parseBootstrapConfig(JSON.stringify(rawHostConfig))`
 * inside EvalHarnessView so the cross-mounted SPA receives the same
 * defaulted shape it would have received from the package's blade
 * `<script id="eval-harness-ui-bootstrap">` payload.
 */
export interface AppBootstrapConfig {
  ui_version: string;
  metric_labels: Record<string, string>;
  tenant_header: string | null;
  polling: Record<string, number>;
  locale: string;
  shortcuts?: {
    commandPalette: string;
  };
}

export type ParsedBootstrap = AppBootstrapConfig & { shortcuts: { commandPalette: string } };

const normalizeLocale = (value: unknown): string => {
  if (typeof value !== 'string' || value.trim() === '') {
    return 'en';
  }

  const normalized = value.trim().toLowerCase();

  return normalized.startsWith('it') ? 'it' : normalized.startsWith('en') ? 'en' : normalized.slice(0, 2);
};

export const parseBootstrapConfig = (raw: string | null): AppBootstrapConfig => {
  if (!raw) {
    return {
      ui_version: '0.0.0',
      metric_labels: {},
      tenant_header: null,
      polling: {},
      locale: 'en',
    };
  }

  try {
    const value = JSON.parse(raw) as AppBootstrapConfig;
    return {
      ui_version: value?.ui_version ?? '0.0.0',
      metric_labels: value?.metric_labels ?? {},
      tenant_header: value?.tenant_header ?? null,
      polling: value?.polling ?? {},
      locale: normalizeLocale(value?.locale),
      shortcuts: {
        commandPalette: value?.shortcuts?.commandPalette ?? 'mod+k',
      },
    };
  } catch {
    return {
      ui_version: '0.0.0',
      metric_labels: {},
      tenant_header: null,
      polling: {},
      locale: 'en',
      shortcuts: {
        commandPalette: 'mod+k',
      },
    };
  }
};
