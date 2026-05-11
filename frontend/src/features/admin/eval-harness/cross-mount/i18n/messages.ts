/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * i18n/messages.ts`. Verbatim copy — pure data + helpers, no
 * dependencies.
 */
export type I18nLocale = 'en' | 'it';

type MessageCatalog = Record<string, string>;

const normalize = (value: string): string => value.toLowerCase();

const catalog: Record<I18nLocale, MessageCatalog> = {
  en: {
    app_title: 'Eval Harness UI',
    nav_dashboard: 'Dashboard',
    nav_reports: 'Reports List',
    nav_compare: 'Compare',
    nav_trend: 'Trend',
    nav_adversarial: 'Adversarial Manifests',
    nav_live_batches: 'Live Batches',
    heading_dashboard: 'Dashboard',
    heading_reports: 'Reports list',
    heading_compare: 'Compare reports',
    heading_trend: 'Dataset trend',
    heading_adversarial: 'Adversarial manifests',
    heading_adversarial_detail: 'Manifest',
    heading_live_batches: 'Live batches',
    heading_coverage: 'Coverage',
    heading_cohorts: 'Cohorts',
    heading_top_failures: 'Top failures',
    title_metric: 'Metric',
    metric_macro_f1: 'Macro F1',
    metric_exact_match: 'Exact match',
    metric_judge_pass_rate: 'Judge pass rate',
    action_open: 'Open',
    action_compare: 'Compare',
    action_download: 'Download',
    action_back: '← back',
    action_rows_csv: 'Rows CSV',
    action_latest_previous: 'Compare latest vs previous',
    label_dataset: 'Dataset',
    label_filter_dataset: 'Dataset',
    label_format: 'Format',
    label_schema: 'Schema',
    label_macro_f1: 'Macro F1',
    label_rows: 'Rows',
    label_finished: 'Finished',
    label_action: 'Action',
    label_latest_macro_f1: 'Latest Macro F1',
    label_live_batches: 'Live batches',
    label_reports_total: 'Reports total',
    label_adversarial: 'Adversarial',
    label_status: 'Status',
    label_report_id: 'Report id',
    label_size: 'Size',
    label_failures: 'Failures',
    label_raw_json: 'Raw JSON',
    label_limit: 'Limit',
    label_metric: 'Metric',
    label_cohort: 'Cohort',
    label_coverage: 'Coverage',
    label_compliance: 'Compliance',
    label_overlays: 'Overlays',
    label_cohorts: 'Cohorts',
    label_download_report: 'Download artifact',
    empty_no_data: 'No data available',
    empty_no_reports: 'No report found.',
    empty_no_manifest: 'No manifests available.',
    empty_no_batch: 'No active batch currently.',
    empty_no_trend: 'No trend data available.',
    status_improved: 'improved',
    status_regressed: 'regressed',
    status_stable: 'stable',
    text_filters: 'Filters',
    text_loading: 'Loading...',
    text_select_dataset: 'Select dataset',
    text_select_left: 'Left report',
    text_select_right: 'Right report',
    text_latest_macro: 'Latest Macro F1',
    text_active_batches: 'Active batches',
    text_adversarial_status: 'Adversarial',
    text_dataset_trend: 'Dataset trend',
    text_error_hint: 'Retry or check backend endpoint.',
    section_summary: 'Summary',
    section_cohorts: 'Cohorts',
    section_histograms: 'Histograms',
    section_failures: 'Failures',
    section_raw: 'Raw JSON',
    section_raw_json: 'Raw JSON',
    text_no_data_for_endpoint: 'Endpoint returned no data.',
    text_rows_csv_help: 'Use rows.csv for sample export and audit.',
    text_raw_json_empty: 'No raw payload available.',
    text_not_available: 'N/A',
    nav_admin_label: 'Eval Harness Admin',
    label_progress_processed: 'Progress',
    label_progress_ttl: 'TTL',
    label_rate: 'Rate',
    label_failures_count: 'Failures',
    label_started_at: 'Started at',
    button_retry: 'Retry',
    button_refresh: 'Refresh',
    trend_limit_10: '10',
    trend_limit_30: '30',
    trend_limit_50: '50',
    trend_limit_100: '100',
    label_token_overlay: 'tokens',
    label_latency_overlay: 'latency',
    label_include_token: 'Token usage overlay',
    label_include_latency: 'Latency overlay',
    heading_actions: 'Quick actions',
    // v4.4 GA Copilot iter 1 fix — DashboardPage + DataTable empty-state
    // strings were hard-coded in Italian; adding catalogue keys so the
    // `en` locale renders the matching English copy instead of mixed-
    // language UI.
    dashboard_no_manifest: 'No manifests',
    dashboard_no_manifest_helper: 'Latest available series',
    dashboard_no_trend_data: 'No trend data',
    table_empty: 'No items available',
  },
  it: {
    app_title: 'Eval Harness UI',
    nav_dashboard: 'Dashboard',
    nav_reports: 'Elenco Report',
    nav_compare: 'Confronta',
    nav_trend: 'Andamento',
    nav_adversarial: 'Manifesti Adversariali',
    nav_live_batches: 'Batch Attivi',
    heading_dashboard: 'Dashboard',
    heading_reports: 'Elenco report',
    heading_compare: 'Confronta report',
    heading_trend: 'Andamento dataset',
    heading_adversarial: 'Manifesti Adversarial',
    heading_adversarial_detail: 'Manifesto',
    heading_live_batches: 'Batch attivi',
    heading_coverage: 'Copertura',
    heading_cohorts: 'Cohorti',
    heading_top_failures: 'Errori principali',
    title_metric: 'Metrica',
    metric_macro_f1: 'Macro F1',
    metric_exact_match: 'Match esatto',
    metric_judge_pass_rate: 'Judge pass rate',
    action_open: 'Apri',
    action_compare: 'Confronta',
    action_download: 'Scarica',
    action_back: '← indietro',
    action_rows_csv: 'Rows CSV',
    action_latest_previous: 'Confronta ultimo vs precedente',
    label_dataset: 'Dataset',
    label_filter_dataset: 'Dataset',
    label_format: 'Formato',
    label_schema: 'Schema',
    label_macro_f1: 'Macro F1',
    label_rows: 'Righe',
    label_finished: 'Completato',
    label_action: 'Azione',
    label_latest_macro_f1: 'Ultimo Macro F1',
    label_live_batches: 'Batch attivi',
    label_reports_total: 'Report totali',
    label_adversarial: 'Adversarial',
    label_status: 'Stato',
    label_report_id: 'ID report',
    label_size: 'Dimensione',
    label_failures: 'Fallimenti',
    label_raw_json: 'Raw JSON',
    label_limit: 'Limite',
    label_metric: 'Metrica',
    label_cohort: 'Cohort',
    label_coverage: 'Copertura',
    label_compliance: 'Conformità',
    label_overlays: 'Overlays',
    label_cohorts: 'Cohorte',
    label_download_report: 'Scarica artifact',
    empty_no_data: 'Nessun dato disponibile',
    empty_no_reports: 'Nessun report trovato.',
    empty_no_manifest: 'Nessun manifesto disponibile.',
    empty_no_batch: 'Nessun batch attivo in questo momento.',
    empty_no_trend: 'Nessun andamento disponibile.',
    status_improved: 'migliorato',
    status_regressed: 'regressione',
    status_stable: 'stabile',
    text_filters: 'Filtri',
    text_loading: 'Caricamento...',
    text_select_dataset: 'Seleziona dataset',
    text_select_left: 'Report sinistro',
    text_select_right: 'Report destro',
    text_latest_macro: 'Macro F1 ultimo',
    text_active_batches: 'Batch attivi',
    text_adversarial_status: 'Adversarial',
    text_dataset_trend: 'Andamento dataset',
    text_error_hint: 'Riprova o controlla endpoint backend.',
    section_summary: 'Sommario',
    section_cohorts: 'Cohorts',
    section_histograms: 'Istogrammi',
    section_failures: 'Fallimenti',
    section_raw: 'Raw JSON',
    section_raw_json: 'Raw JSON',
    text_no_data_for_endpoint: 'Endpoint senza dati.',
    text_rows_csv_help: 'Usa rows.csv per esportazione e audit campioni.',
    text_raw_json_empty: 'Nessun JSON raw disponibile.',
    text_not_available: 'N/D',
    nav_admin_label: 'Eval Harness Admin',
    label_progress_processed: 'Progress',
    label_progress_ttl: 'TTL',
    label_rate: 'Rate',
    label_failures_count: 'Fallimenti',
    label_started_at: 'Inizio',
    button_retry: 'Riprova',
    button_refresh: 'Aggiorna',
    trend_limit_10: '10',
    trend_limit_30: '30',
    trend_limit_50: '50',
    trend_limit_100: '100',
    label_token_overlay: 'token',
    label_latency_overlay: 'latency',
    label_include_token: 'Overlay token',
    label_include_latency: 'Overlay latency',
    heading_actions: 'Azioni rapide',
    // v4.4 GA Copilot iter 1 fix — see `en` block.
    dashboard_no_manifest: 'Nessun manifesto',
    dashboard_no_manifest_helper: 'Ultima serie disponibile',
    dashboard_no_trend_data: 'Nessun dato trend',
    table_empty: 'Nessun elemento disponibile',
  },
} as const;

export const isLocale = (value: string): value is I18nLocale => {
  return value === 'en' || value === 'it';
};

export const resolveLocale = (value: string | undefined, fallback: I18nLocale = 'en'): I18nLocale => {
  if (!value) {
    return fallback;
  }

  const normalized = normalize(value) as string;

  if (normalized.startsWith('it')) {
    return 'it';
  }

  if (normalized.startsWith('en')) {
    return 'en';
  }

  return fallback;
};

export const getMessage = (locale: I18nLocale, key: string, fallback = ''): string => {
  return catalog[locale][key] ?? catalog.en[key] ?? fallback;
};

export const buildMetricLabel = (locale: I18nLocale, key: string, fallback?: string): string => {
  if (key === 'macro_f1') {
    return getMessage(locale, 'metric_macro_f1', fallback ?? key);
  }

  if (key === 'exact-match.mean') {
    return getMessage(locale, 'metric_exact_match', fallback ?? key);
  }

  if (key === 'llm-judge.pass_rate') {
    return getMessage(locale, 'metric_judge_pass_rate', fallback ?? key);
  }

  return fallback ?? key;
};
