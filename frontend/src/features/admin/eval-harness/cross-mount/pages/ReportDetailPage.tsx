/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * pages/ReportDetailPage.tsx`. Imports rewritten to relative paths;
 * package CSS classes (`panel`, `rounded-ui`, `screen-title`,
 * `screen-subtitle`) rebadged to cross-mount-scoped equivalents
 * (`ehu-*`).
 */
import { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useAppContext } from '../context/AppContext';
import { useApiResource } from '../hooks/useApiResource';
import { useI18n } from '../hooks/useI18n';
import ErrorPanel from '../components/ui/ErrorPanel';
import EmptyState from '../components/ui/EmptyState';
import { formatDateTime, formatPercent } from '../utils/format';
import type { CohortsPayload, HistogramsPayload, ReportDetailPayload } from '../types/models';

type TabKey = 'summary' | 'cohorts' | 'histograms' | 'failures' | 'raw';

type FailureRow = {
  category: string;
  count: number;
};

const ReportDetailPage = () => {
  const { id } = useParams();
  const [activeTab, setActiveTab] = useState<TabKey>('summary');
  const { createClient } = useAppContext();
  const client = createClient();
  const { t } = useI18n();

  const report = useApiResource<ReportDetailPayload>(() => client.getReport(id ?? ''), [id], {
    cacheKey: id ? `report:${id}` : undefined,
    ttlMs: 5 * 60_000,
  });
  const cohorts = useApiResource<CohortsPayload>(() => client.getReportCohorts(id ?? ''), [id], {
    cacheKey: id ? `report:${id}:cohorts` : undefined,
    ttlMs: Number.POSITIVE_INFINITY,
  });
  const histograms = useApiResource<HistogramsPayload>(() => client.getReportHistograms(id ?? ''), [id], {
    cacheKey: id ? `report:${id}:histograms` : undefined,
    ttlMs: Number.POSITIVE_INFINITY,
  });
  const rows = useMemo(() => {
    const reportData = report.data;
    const rawMetrics = reportData?.metrics;

    if (!rawMetrics || typeof rawMetrics !== 'object' || Array.isArray(rawMetrics)) {
      return [] as { name: string; value: unknown }[];
    }

    return Object.entries(rawMetrics).map(([name, value]) => ({
      name,
      value,
    }));
  }, [report.data]);

  const failuresRows = useMemo<FailureRow[]>(() => {
    const reportData = report.data;
    if (!reportData) {
      return [];
    }

    const hasTypedFailures = Array.isArray(reportData.failures)
      && reportData.failures.every((item) => item && typeof item === 'object' && 'category' in item && 'count' in item);
    if (hasTypedFailures) {
      return reportData.failures as FailureRow[];
    }

    const rawFailures = reportData.raw_json?.failures;
    if (!Array.isArray(rawFailures)) {
      return [];
    }

    return rawFailures
      .map((item) => {
        if (item && typeof item === 'object' && 'category' in item && 'count' in item) {
          const candidate = item as { category?: unknown; count?: unknown };
          if (typeof candidate.category === 'string' && typeof candidate.count === 'number') {
            return { category: candidate.category, count: candidate.count };
          }
        }

        return null;
      })
      .filter((item): item is FailureRow => item !== null);
  }, [report.data]);

  if (report.status === 'loading' || report.status === 'idle') {
    return <div data-testid="admin-eval-harness-report-detail-loading">{t('text_loading')}</div>;
  }

  if (report.status === 'error' && report.error) {
    return <ErrorPanel error={report.error} />;
  }

  if (!report.data) {
    return <EmptyState title={t('empty_no_reports')}>{t('text_error_hint')}</EmptyState>;
  }

  const reportData = report.data;
  const schema = reportData.schema ?? reportData.schema_version ?? 'legacy';
  const cohortsData = cohorts.status === 'ready' ? cohorts.data : undefined;
  const histogramsData = histograms.status === 'ready' ? histograms.data : undefined;
  const hasCohorts = Boolean(cohortsData && cohortsData.cohorts.length > 0);
  const hasHistograms = Boolean(histogramsData && histogramsData.buckets.length > 0);
  const reportRowsCsvUrl = client.getReportRowsCsvUrl(reportData.id);
  const reportDownloadUrl = client.getReportDownloadUrl(reportData.id);

  return (
    <div className="space-y-4" data-testid="admin-eval-harness-report-detail">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="ehu-screen-title">
            {t('heading_reports')} {reportData.dataset} / {formatDateTime(reportData.finished_at ?? reportData.id ?? '')}
          </h2>
          <p className="ehu-screen-subtitle">
            {t('label_schema')}: <span className="font-semibold text-slate-900">{schema}</span> • {t('label_rows')}:{' '}
            {reportData.sample_count ?? 0} • {t('label_failures_count')}:{reportData.failures_count ?? 0}
          </p>
        </div>
        <div className="flex gap-2">
          <a href={reportRowsCsvUrl} className="ehu-rounded border px-3 py-2 text-sm" target="_blank" rel="noopener noreferrer">
            {t('action_rows_csv')}
          </a>
          <a href={reportDownloadUrl} className="ehu-rounded border px-3 py-2 text-sm" target="_blank" rel="noopener noreferrer">
            {t('action_download')}
          </a>
          <Link to="/reports" className="ehu-rounded border px-3 py-2 text-sm text-slate-600">
            {t('action_back')}
          </Link>
        </div>
      </div>

      <div className="flex gap-2">
        {(['summary', 'cohorts', 'histograms', 'failures', 'raw'] as TabKey[]).map((tab) => (
          <button
            key={tab}
            className={`ehu-rounded border px-3 py-1.5 text-sm ${activeTab === tab ? 'bg-slate-900 text-white' : 'bg-white'}`}
            onClick={() => setActiveTab(tab)}
            type="button"
          >
            {t(`section_${tab}`)}
          </button>
        ))}
      </div>

      {activeTab === 'summary' ? (
        <section className="ehu-panel ehu-rounded">
          <h3 className="mb-2 font-semibold">{t('section_summary')}</h3>
          <dl className="grid grid-cols-2 gap-2 text-sm">
            {rows.map((metric) => (
              <div key={metric.name} className="flex justify-between border-b border-slate-100 py-1">
                <dt className="font-medium">{metric.name}</dt>
                <dd>{formatPercent(Number(metric.value))}</dd>
              </div>
            ))}
          </dl>
        </section>
      ) : null}

      {activeTab === 'cohorts' ? (
        <section className="ehu-panel ehu-rounded">
          <h3 className="mb-2 font-semibold">{t('section_cohorts')}</h3>
          {cohorts.status === 'loading' || cohorts.status === 'idle' ? <p>{t('text_loading')}</p> : null}
          {cohorts.status === 'error' && cohorts.error ? <ErrorPanel error={cohorts.error} /> : null}
          {hasCohorts ? (
            <ul className="space-y-2">
              {cohortsData?.cohorts.map((row) => (
                <li key={row.cohort} className="ehu-rounded border border-slate-100 px-3 py-2 text-sm">
                  {row.cohort}: pass {formatPercent(row.pass_rate)} su {row.samples} sample
                </li>
              ))}
            </ul>
          ) : (
            <EmptyState title={t('empty_no_data')}>{t('text_no_data_for_endpoint')}</EmptyState>
          )}
        </section>
      ) : null}

      {activeTab === 'histograms' ? (
        <section className="ehu-panel ehu-rounded">
          <h3 className="mb-2 font-semibold">{t('section_histograms')}</h3>
          {histograms.status === 'ready' && hasHistograms ? (
            <ul className="space-y-2">
              {histogramsData?.buckets.map((bucket) => (
                <li key={`${bucket.min}-${bucket.max}`} className="text-sm">
                  {bucket.min} - {bucket.max}: {bucket.count}
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-slate-500">{t('text_no_data_for_endpoint')}</p>
          )}
        </section>
      ) : null}

      {activeTab === 'failures' ? (
        <section className="ehu-panel ehu-rounded">
          <h3 className="mb-2 font-semibold">{t('section_failures')}</h3>
          {failuresRows.length > 0 ? (
            <ul className="space-y-2">
              {failuresRows.map((row) => (
                <li key={row.category} className="flex justify-between ehu-rounded border px-3 py-2 text-sm">
                  <span>{row.category}</span>
                  <span>{row.count}</span>
                </li>
              ))}
            </ul>
          ) : (
            <EmptyState title={t('empty_no_data')}>{t('text_rows_csv_help')}</EmptyState>
          )}
        </section>
      ) : null}

      {activeTab === 'raw' ? (
        <section className="ehu-panel ehu-rounded">
          <h3 className="mb-2 font-semibold">{t('section_raw_json')}</h3>
          {reportData.raw_json ? (
            <pre className="max-h-80 overflow-auto ehu-rounded border bg-slate-50 p-3 text-xs">
              {JSON.stringify(reportData.raw_json ?? {}, null, 2)}
            </pre>
          ) : (
            <p>{t('text_raw_json_empty')}</p>
          )}
        </section>
      ) : null}
    </div>
  );
};

export default ReportDetailPage;
