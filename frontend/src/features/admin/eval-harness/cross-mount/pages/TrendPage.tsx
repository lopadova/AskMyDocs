/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * pages/TrendPage.tsx`. Imports rewritten to relative paths;
 * package CSS classes rebadged to cross-mount-scoped equivalents.
 */
import { useMemo, useState } from 'react';
import { useAppContext } from '../context/AppContext';
import { useApiResource } from '../hooks/useApiResource';
import ErrorPanel from '../components/ui/ErrorPanel';
import EmptyState from '../components/ui/EmptyState';
import { formatPercent } from '../utils/format';
import type { MetricTrend, ReportListPayload, TrendPayload } from '../types/api';
import { useI18n } from '../hooks/useI18n';

const defaultMetricOptions = ['macro_f1', 'exact-match.mean', 'llm-judge.pass_rate'];

const TrendPage = () => {
  const { createClient } = useAppContext();
  const client = createClient();
  const { t, metricLabel } = useI18n();

  const reports = useApiResource<ReportListPayload>(() => client.getReports(), [], {
    cacheKey: 'trend:reports',
    ttlMs: 30_000,
  });
  const [dataset, setDataset] = useState('');
  const [metric, setMetric] = useState('macro_f1');
  const [limit, setLimit] = useState(30);
  const [cohort, setCohort] = useState('all');
  const [overlayTokens, setOverlayTokens] = useState(false);
  const [overlayLatency, setOverlayLatency] = useState(false);

  const trend = useApiResource<TrendPayload>(() => {
    if (!dataset) {
      return Promise.resolve({ data: undefined as unknown as TrendPayload });
    }

    return client.getDatasetTrend(dataset, limit);
  }, [dataset, limit], {
    cacheKey: dataset ? `trend:${dataset}:${limit}` : 'trend:empty',
    ttlMs: 5 * 60_000,
  });

  const datasetOptions = useMemo(() => {
    if (reports.status !== 'ready' || !reports.data) {
      return [] as string[];
    }

    return [...new Set(reports.data.items.map((row) => row.dataset))];
  }, [reports.status, reports.data]);

  const metricOptions = useMemo(() => {
    const available = new Set(defaultMetricOptions);
    if (trend.status === 'ready' && trend.data) {
      trend.data.metrics.forEach((item) => {
        available.add(item.metric);
      });
    }

    return Array.from(available);
  }, [trend.status, trend.data]);

  const selectedSeries = useMemo(() => {
    if (trend.status !== 'ready' || !trend.data) {
      return [] as MetricTrend[];
    }

    const selected: MetricTrend[] = [];
    if (trend.data.metrics.some((metricItem) => metricItem.metric === metric)) {
      selected.push(...trend.data.metrics.filter((metricItem) => metricItem.metric === metric));
    }

    if (overlayTokens) {
      selected.push(...trend.data.metrics.filter((metricItem) => metricItem.metric === 'tokens'));
    }

    if (overlayLatency) {
      selected.push(...trend.data.metrics.filter((metricItem) => metricItem.metric === 'latency'));
    }

    if (selected.length === 0) {
      const fallback = trend.data.metrics[0];
      if (fallback) {
        selected.push(fallback);
      }
    }

    return selected;
  }, [overlayLatency, overlayTokens, metric, trend]);

  const selectedCohorts = useMemo(() => {
    if (trend.status !== 'ready' || !trend.data?.cohorts || trend.data.cohorts.length === 0) {
      return ['all'];
    }

    return ['all', ...trend.data.cohorts];
  }, [trend]);

  const selectedCohort = cohort;
  const cohortHint = selectedCohort === 'all' ? `${t('label_cohort')}: all` : `${t('label_cohort')}: ${selectedCohort}`;

  return (
    <div className="space-y-4" data-testid="admin-eval-harness-trend">
      <h2 className="ehu-screen-title">{t('heading_trend')}</h2>

      {trend.status === 'error' && trend.error ? <ErrorPanel error={trend.error} /> : null}

      <section className="ehu-panel ehu-rounded">
        <div className="flex flex-wrap gap-3">
          {/*
            * Copilot iter 2 finding #6 (R11 / R29): Trend page
            * filter inputs all carry stable testids.
            */}
          <label className="text-sm">
            {t('label_dataset')}
            <select
              className="mt-1 ehu-rounded border border-slate-200 px-2 py-2"
              value={dataset}
              onChange={(event) => setDataset(event.target.value)}
              data-testid="eval-harness-trend-select-dataset"
            >
              <option value="">seleziona</option>
              {datasetOptions.map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))}
            </select>
          </label>
          <label className="text-sm">
            {t('label_metric')}
            <select
              className="mt-1 ehu-rounded border border-slate-200 px-2 py-2"
              value={metric}
              onChange={(event) => setMetric(event.target.value)}
              data-testid="eval-harness-trend-select-metric"
            >
              {metricOptions.map((item) => (
                <option key={item} value={item}>
                  {metricLabel(item, item)}
                </option>
              ))}
            </select>
          </label>
          <label className="text-sm">
            {t('label_cohort')}
            <select
              className="mt-1 ehu-rounded border border-slate-200 px-2 py-2"
              value={cohort}
              onChange={(event) => setCohort(event.target.value)}
              data-testid="eval-harness-trend-select-cohort"
            >
              {selectedCohorts.map((item) => (
                <option key={item} value={item}>
                  {item}
                </option>
              ))}
            </select>
          </label>
          <label className="text-sm">
            {t('label_limit')}
            <select
              className="mt-1 ehu-rounded border border-slate-200 px-2 py-2"
              value={limit}
              onChange={(event) => setLimit(Number(event.target.value))}
              data-testid="eval-harness-trend-select-limit"
            >
              {[10, 30, 50, 100].map((size) => (
                <option key={size} value={size}>
                  {size}
                </option>
              ))}
            </select>
          </label>
          <fieldset className="text-sm">
            <legend className="mb-1">{t('label_overlays')}</legend>
            <label className="mr-3">
              <input
                checked={overlayTokens}
                onChange={() => setOverlayTokens((prev) => !prev)}
                type="checkbox"
                data-testid="eval-harness-trend-overlay-tokens"
              />{' '}
              {t('label_include_token')}
            </label>
            <label>
              <input
                checked={overlayLatency}
                onChange={() => setOverlayLatency((prev) => !prev)}
                type="checkbox"
                data-testid="eval-harness-trend-overlay-latency"
              />{' '}
              {t('label_include_latency')}
            </label>
          </fieldset>
        </div>
      </section>

      {trend.status === 'ready' && selectedSeries.length > 0 ? (
        <section className="ehu-panel ehu-rounded">
          <h3 className="mb-2 font-semibold">
            {t('heading_trend')}: {dataset}
          </h3>
          <p className="ehu-screen-subtitle mb-2">
            {t('label_metric')}: {metric} · {cohortHint}
          </p>
          <div className="space-y-2">
            {selectedSeries.map((series) => (
              <div key={`${series.metric}-${series.values.length}`} className="space-y-1 ehu-rounded border border-slate-100 p-2">
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{metricLabel(series.metric, series.metric)}</p>
                {series.values.map((point: { at: string; value: number }) => (
                  <div
                    key={`${series.metric}-${point.at}`}
                    className="flex items-center justify-between ehu-rounded bg-slate-50 p-2 text-sm"
                  >
                    <span>{point.at}</span>
                    <span>{typeof point.value === 'number' ? formatPercent(point.value) : String(point.value)}</span>
                  </div>
                ))}
              </div>
            ))}
          </div>
        </section>
      ) : (
        <EmptyState title={t('empty_no_trend')}>{t('text_error_hint')}</EmptyState>
      )}
    </div>
  );
};

export default TrendPage;
