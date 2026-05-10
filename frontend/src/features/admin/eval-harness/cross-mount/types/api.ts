/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * types/api.ts`. Verbatim copy.
 */
export type ApiErrorKind = 'empty' | 'invalid' | 'unavailable' | 'error';

export interface ApiErrorState {
  kind: ApiErrorKind;
  status?: number;
  message: string;
}

export interface ApiSchemaMeta {
  schema_version?: string | null;
  schema?: string | null;
}

export interface ApiResult<T> {
  data?: T;
  error?: ApiErrorState;
}

export interface ReportRow {
  id: string;
  dataset: string;
  format: string;
  schema_version?: string | null;
  schema?: string | null;
  macro_f1: number;
  sample_count: number;
  finished_at: string;
  size?: number;
  status?: string;
}

export interface ReportListPayload extends ApiSchemaMeta {
  items: ReportRow[];
  total: number;
}

export interface MetricPoint {
  at: string;
  value: number;
}

export interface MetricTrend {
  metric: string;
  values: MetricPoint[];
}

export interface TrendPayload extends ApiSchemaMeta {
  dataset: string;
  metrics: MetricTrend[];
  cohorts?: string[];
}

export interface DashboardPayload {
  report_count: number;
  latest_macro_f1?: number | null;
  active_batches?: number;
  adversarial_status?: string | null;
  trend_top_datasets?: Array<{ name: string; value: number }>;
}

export interface AdversarialManifestSummary {
  name: string;
  runs: number;
  latest_f1?: number | null;
  compliance?: string | null;
  coverage?: number | null;
  latest_status?: string | null;
}

export interface AdversarialManifestDetail {
  schema_version?: string | null;
  schema?: string | null;
  name: string;
  runs: number;
  coverage?: number | null;
  latest_status?: string | null;
  compliance?: string | null;
  items: {
    category: string;
    failures: number;
  }[];
  cohorts?: Array<{ cohort: string; pass_rate: number }>;
}

export interface LiveBatch {
  id: string;
  status: 'running' | 'finished' | 'error' | string;
  processed: number;
  total: number;
  ttl_seconds: number;
  started_at?: string;
}

export interface LiveBatchProgress {
  batch_id: string;
  started_at: string;
  status?: string;
  processed?: number;
  total?: number;
  last_checkpoint?: string | null;
  failures?: number;
  rate_per_sec?: number | null;
  ttl_seconds?: number | null;
  progress_percent?: number | null;
}
