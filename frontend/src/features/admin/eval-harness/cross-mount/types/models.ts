/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * types/models.ts`. Verbatim copy modulo the relative-path import.
 */
import type { ApiErrorState, ApiSchemaMeta } from './api';

export interface ErrorPayload {
  kind: ApiErrorState['kind'];
  status?: number;
  message: string;
}

export interface MetricTable {
  [name: string]: number;
}

export interface ReportDetailPayload extends ApiSchemaMeta {
  id: string;
  dataset: string;
  metrics?: MetricTable;
  schema_version?: string;
  schema?: string | null;
  sample_count?: number;
  failures_count?: number;
  finished_at?: string;
  raw_json?: Record<string, unknown> | null;
  metric_labels?: Record<string, string> | null;
  failures?: { category: string; count: number }[];
}

export interface CohortRow {
  cohort: string;
  pass_rate: number;
  samples: number;
}

export interface CohortsPayload extends ApiSchemaMeta {
  id: string;
  cohorts: CohortRow[];
}

export interface HistogramBucket {
  min: number;
  max: number;
  count: number;
}

export interface HistogramsPayload extends ApiSchemaMeta {
  id: string;
  buckets: HistogramBucket[];
}

export interface DiffMetric {
  metric: string;
  delta: number;
  status: 'improved' | 'regressed' | 'stable';
}

export interface DiffPayload extends ApiSchemaMeta {
  from: string;
  to: string;
  metrics: DiffMetric[];
  cohorts?: { cohort: string; status: 'improved' | 'regressed' | 'stable'; delta: number }[];
}
