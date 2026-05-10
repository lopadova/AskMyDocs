/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * services/evalHarnessApi.ts`.
 *
 * v4.4/W3 — TWO material differences vs the upstream `evalHarnessApi.ts`:
 *
 *   1. The internal `fetch(...)` call inside `request()` is replaced
 *      with the host shared axios instance from `frontend/src/lib/api.ts`.
 *      The host axios already carries `withCredentials: true` (Sanctum
 *      session cookie), automatic `XSRF-TOKEN` → `X-XSRF-TOKEN`
 *      forwarding, and `X-Requested-With: XMLHttpRequest` for
 *      Laravel's `EnsureFrontendRequestsAreStateful` middleware.
 *      Sharing the axios instance also means request/response
 *      interceptors registered host-side (401 redirect, 419 CSRF
 *      re-prime) apply to package calls too. Same delegation pattern
 *      as the W2 cross-mount of pii-redactor-admin (see
 *      `frontend/src/features/admin/pii-redactor/cross-mount/adminApi.ts`).
 *
 *   2. The schema-classification + schema-version + validateShape
 *      contracts are PRESERVED VERBATIM. The package's UI components
 *      type-check against `ApiResult<T>` and the same `kind` enum
 *      (`empty | invalid | unavailable | error`); changing the contract
 *      would require touching every page. The `getReport*Url` builders
 *      are unchanged because `<a href>` downloads / CSV exports stay on
 *      the browser's native cookie auth (same origin).
 *
 * R7 / R14: failures bubble through `ApiResult<T>.error` rather than
 * being silently `null`'d — every page renders an `<ErrorPanel />` for
 * non-2xx responses. Empty 200 / null body / NaN derived values are
 * caught by the `validateShape` predicates.
 */
import axios, { type AxiosResponse, type AxiosRequestConfig } from 'axios';
import { api } from '../../../../../lib/api';
import type {
  AdversarialManifestDetail,
  AdversarialManifestSummary,
  ApiResult,
  LiveBatch,
  LiveBatchProgress,
  ReportListPayload,
  TrendPayload,
} from '../types/api';
import type { DiffPayload, ErrorPayload, ReportDetailPayload, CohortsPayload, HistogramsPayload } from '../types/models';
import { buildUrl, normalizeBaseUrl } from '../utils/path';

const ERROR_EMPTY_MESSAGE = 'Resource not available yet.';
const ERROR_INVALID_MESSAGE = 'Invalid request parameters.';
const ERROR_UNAVAILABLE_MESSAGE = 'Service temporarily unavailable.';
const ERROR_SCHEMA_MESSAGE = 'Invalid response payload.';
const ERROR_SCHEMA_MISSING_MESSAGE = 'Missing schema version in response.';

const isRecord = (value: unknown): value is Record<string, unknown> =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

const schemaVersion = (value: Record<string, unknown>): string | null => {
  const version = value.schema_version;

  return typeof version === 'string' && version.trim() !== '' ? version.trim() : null;
};

const schemaValue = (value: Record<string, unknown>): string | null => {
  const schema = value.schema;

  return typeof schema === 'string' && schema.trim() !== '' ? schema.trim() : null;
};

const classifyStatusError = (status: number): ErrorPayload => {
  if (status === 404) {
    return {
      kind: 'empty',
      status,
      message: ERROR_EMPTY_MESSAGE,
    };
  }

  if (status === 422) {
    return {
      kind: 'invalid',
      status,
      message: ERROR_INVALID_MESSAGE,
    };
  }

  if (status === 503) {
    return {
      kind: 'unavailable',
      status,
      message: ERROR_UNAVAILABLE_MESSAGE,
    };
  }

  return {
    kind: 'error',
    status,
    message: `API returned ${status}`,
  };
};

const isValidSchema = (payload: unknown, expectedSchema?: string): ErrorPayload | null => {
  if (!isRecord(payload)) {
    return { kind: 'invalid', status: 200, message: ERROR_SCHEMA_MESSAGE };
  }

  const version = schemaVersion(payload);
  if (!version) {
    return { kind: 'invalid', status: 200, message: ERROR_SCHEMA_MISSING_MESSAGE };
  }

  const presentSchema = schemaValue(payload);
  if (expectedSchema && presentSchema && presentSchema !== expectedSchema) {
    return {
      kind: 'invalid',
      status: 200,
      message: `Unexpected schema '${presentSchema}', expected '${expectedSchema}'.`,
    };
  }

  void version;
  return null;
};

const asResult = <T>(payload: unknown, expectedSchema?: string, validateShape?: SchemaValidator): ApiResult<T> => {
  const schemaError = isValidSchema(payload, expectedSchema);
  if (schemaError) {
    return { error: schemaError };
  }

  if (!isRecord(payload) || (validateShape && !validateShape(payload))) {
    return { error: { kind: 'invalid', status: 200, message: ERROR_SCHEMA_MESSAGE } };
  }

  return { data: payload as T };
};

type SchemaValidator = (value: unknown) => boolean;

export class EvalHarnessApiClient {
  constructor(
    private readonly baseUrl: string,
    private readonly tenantHeader?: string | null,
  ) {}

  private async request<T>(
    path: string,
    config: AxiosRequestConfig,
    expectedSchema?: string,
    validateShape?: SchemaValidator,
  ): Promise<ApiResult<T>> {
    const url = buildUrl(normalizeBaseUrl(this.baseUrl), path);

    let response: AxiosResponse<unknown>;
    try {
      response = await api.request<unknown>({ ...config, url });
    } catch (error) {
      // Axios throws on non-2xx by default; capture status from the
      // response when present, otherwise treat it as a transport
      // failure (status 0) so the caller still gets a typed error.
      if (axios.isAxiosError(error) && error.response) {
        return { error: classifyStatusError(error.response.status) };
      }
      return {
        error: {
          kind: 'error',
          status: 0,
          message: error instanceof Error ? error.message : 'Network request failed.',
        },
      };
    }

    if (response.status === 204) {
      return { data: undefined as never };
    }

    // Axios already parses JSON when the response Content-Type is
    // `application/json`; for any unexpected non-object body the
    // schema validator below returns the same `invalid` error the
    // upstream `fetch + try/catch JSON.parse` branch produced.
    const payload = response.data as unknown;
    return asResult<T>(payload, expectedSchema, validateShape);
  }

  private requestOptions(): AxiosRequestConfig {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    };

    if (this.tenantHeader) {
      headers[this.tenantHeader] = 'active';
    }

    return { headers, withCredentials: true };
  }

  async getReports(): Promise<ApiResult<ReportListPayload>> {
    return this.request<ReportListPayload>(
      '/reports',
      this.requestOptions(),
      undefined,
      (value): boolean =>
        isRecord(value) && typeof value.total === 'number' && Array.isArray(value.items),
    );
  }

  async getReport(id: string): Promise<ApiResult<ReportDetailPayload>> {
    return this.request<ReportDetailPayload>(
      `/reports/${encodeURIComponent(id)}`,
      this.requestOptions(),
      undefined,
      (value): boolean =>
        isRecord(value) &&
        typeof value.id === 'string' &&
        typeof value.dataset === 'string' &&
        typeof value.schema_version === 'string',
    );
  }

  async getReportCohorts(id: string): Promise<ApiResult<CohortsPayload>> {
    return this.request<CohortsPayload>(
      `/reports/${encodeURIComponent(id)}/cohorts`,
      this.requestOptions(),
      undefined,
      (value): boolean =>
        isRecord(value) &&
        typeof value.id === 'string' &&
        Array.isArray(value.cohorts),
    );
  }

  async getReportHistograms(id: string): Promise<ApiResult<HistogramsPayload>> {
    return this.request<HistogramsPayload>(
      `/reports/${encodeURIComponent(id)}/histograms`,
      this.requestOptions(),
      undefined,
      (value): boolean =>
        isRecord(value) &&
        typeof value.id === 'string' &&
        Array.isArray(value.buckets),
    );
  }

  async getReportDiff(id: string, otherId: string): Promise<ApiResult<DiffPayload>> {
    return this.request<DiffPayload>(
      `/reports/${encodeURIComponent(id)}/diff/${encodeURIComponent(otherId)}`,
      this.requestOptions(),
      'eval-harness.report-api.v1.diff',
      (value): boolean =>
        isRecord(value) &&
        typeof value.from === 'string' &&
        typeof value.to === 'string' &&
        Array.isArray(value.metrics),
    );
  }

  async getDatasetTrend(name: string, limit = 30): Promise<ApiResult<TrendPayload>> {
    return this.request<TrendPayload>(
      `/datasets/${encodeURIComponent(name)}/trend?limit=${limit}`,
      this.requestOptions(),
      'eval-harness.report-api.v1.trend',
      (value): boolean =>
        isRecord(value) &&
        typeof value.dataset === 'string' &&
        Array.isArray(value.metrics),
    );
  }

  async getAdversarialManifests(): Promise<ApiResult<{ items: AdversarialManifestSummary[] }>> {
    return this.request<{ items: AdversarialManifestSummary[] }>(
      '/adversarial/manifests',
      this.requestOptions(),
      'eval-harness.report-api.v1.adversarial-manifests',
      (value): boolean => isRecord(value) && Array.isArray(value.items),
    );
  }

  async getAdversarialManifestDetail(name: string): Promise<ApiResult<AdversarialManifestDetail>> {
    return this.request<AdversarialManifestDetail>(
      `/adversarial/manifests/${encodeURIComponent(name)}`,
      this.requestOptions(),
      'eval-harness.report-api.v1.adversarial-manifest',
      (value): boolean =>
        isRecord(value) &&
        typeof value.name === 'string' &&
        typeof value.runs === 'number' &&
        Array.isArray(value.items),
    );
  }

  async getLiveBatches(): Promise<ApiResult<{ items: LiveBatch[] }>> {
    return this.request<{ items: LiveBatch[] }>(
      '/batches/live',
      this.requestOptions(),
      'eval-harness.report-api.v1.batches-live',
      (value): boolean => isRecord(value) && Array.isArray(value.items),
    );
  }

  async getBatchProgress(id: string): Promise<ApiResult<LiveBatchProgress>> {
    return this.request<LiveBatchProgress>(
      `/batches/${encodeURIComponent(id)}/progress`,
      this.requestOptions(),
      'eval-harness.report-api.v1.batch-progress',
      (value): boolean =>
        isRecord(value) &&
        typeof value.batch_id === 'string' &&
        typeof value.started_at === 'string',
    );
  }

  getReportRowsCsvUrl(id: string): string {
    return buildUrl(normalizeBaseUrl(this.baseUrl), `/reports/${encodeURIComponent(id)}/rows.csv`);
  }

  getReportDownloadUrl(id: string): string {
    return buildUrl(normalizeBaseUrl(this.baseUrl), `/reports/${encodeURIComponent(id)}/download`);
  }
}

export const createApiClient = (apiBase: string, tenantHeader?: string | null): EvalHarnessApiClient =>
  new EvalHarnessApiClient(apiBase, tenantHeader);
