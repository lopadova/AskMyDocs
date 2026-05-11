/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * pages/DashboardPage.tsx`. Imports rewritten to relative paths;
 * package CSS classes (`panel`, `rounded-ui`, `screen-title`) rebadged
 * to cross-mount-scoped equivalents (`ehu-*`). Behaviour preserved
 * verbatim.
 */
import type { AdversarialManifestSummary, LiveBatch, ReportListPayload } from '../types/api';
import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useAppContext } from '../context/AppContext';
import { formatPercent } from '../utils/format';
import { useApiResource } from '../hooks/useApiResource';
import StatCard from '../components/ui/StatCard';
import EmptyState from '../components/ui/EmptyState';
import ErrorPanel from '../components/ui/ErrorPanel';
import { useI18n } from '../hooks/useI18n';

const DashboardPage = () => {
  const { createClient } = useAppContext();
  const client = createClient();
  const { t } = useI18n();

  const reports = useApiResource<ReportListPayload>(() => client.getReports(), [], {
    cacheKey: 'dashboard:reports',
    ttlMs: 30_000,
  });
  const adversarial = useApiResource<{ items: AdversarialManifestSummary[] }>(() => client.getAdversarialManifests(), [], {
    cacheKey: 'dashboard:adversarial',
    ttlMs: 60_000,
  });
  const batches = useApiResource<{ items: LiveBatch[] }>(() => client.getLiveBatches(), [], {
    cacheKey: 'dashboard:batches-live',
    noCache: true,
  });

  const { topDatasets, latestMacroF1, totalReports, activeBatches } = useMemo(() => {
    if (reports.status !== 'ready' || !reports.data) {
      return {
        topDatasets: [],
        latestMacroF1: null,
        totalReports: 0,
        activeBatches: 0,
      };
    }

    const sorted = [...reports.data.items].sort((a, b) => b.finished_at.localeCompare(a.finished_at));
    const latest = sorted[0];
    const totalReports = reports.data.total;

    return {
      topDatasets: sorted.slice(0, 3).map((row) => `${row.dataset} · ${formatPercent(row.macro_f1)}`),
      latestMacroF1: latest ? formatPercent(latest.macro_f1) : '—',
      totalReports,
      activeBatches: batches.status === 'ready' && batches.data ? batches.data.items.filter((batch) => batch.status === 'running').length : 0,
    };
  }, [reports.status, reports.data, batches.status, batches.data]);

  const adversarialStatus =
    adversarial.status === 'ready' && adversarial.data && adversarial.data.items.length > 0 ? adversarial.data.items[0].name : null;

  return (
    <div className="space-y-4" data-testid="admin-eval-harness-dashboard">
      <h2 className="ehu-screen-title">{t('heading_dashboard')}</h2>

      {reports.status === 'error' && reports.error ? <ErrorPanel error={reports.error} /> : null}
      {batches.status === 'error' && batches.error ? <ErrorPanel error={batches.error} /> : null}

      <section className="grid gap-4 md:grid-cols-4">
        <StatCard label={t('label_reports_total')} value={reports.status === 'ready' ? totalReports : '—'} />
        <StatCard label={t('label_latest_macro_f1')} value={reports.status === 'ready' ? latestMacroF1 : '—'} />
        <StatCard label={t('label_live_batches')} value={batches.status === 'ready' ? activeBatches : '—'} />
        <StatCard
          label={t('label_adversarial')}
          value={adversarialStatus ?? t('dashboard_no_manifest')}
          helper={adversarial.status === 'ready' ? t('dashboard_no_manifest_helper') : undefined}
        />
      </section>

      <section className="grid gap-4 md:grid-cols-2">
        <div className="ehu-panel ehu-rounded">
          <h3 className="ehu-screen-title">{t('text_dataset_trend')}</h3>
          <ul className="mt-3 space-y-2 text-sm text-slate-700">
            {topDatasets.length === 0 ? (
              <li>{t('dashboard_no_trend_data')}</li>
            ) : (
              topDatasets.map((dataset) => <li key={dataset}>- {dataset}</li>)
            )}
          </ul>
        </div>
        <div className="ehu-panel ehu-rounded">
          <h3 className="ehu-screen-title">{t('heading_actions')}</h3>
          <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-slate-700">
            <li>
              <Link to="/reports">{t('action_open')} {t('heading_reports').toLowerCase()}</Link>
            </li>
            <li>
              <Link to="/compare">{t('action_compare')} {t('heading_compare').toLowerCase()}</Link>
            </li>
            <li>
              <Link to="/live-batches">{t('action_open')} {t('heading_live_batches').toLowerCase()}</Link>
            </li>
          </ul>
        </div>
      </section>

      {reports.status === 'ready' && reports.data && reports.data.total === 0 ? (
        <EmptyState title={t('empty_no_reports')}>
          <span>{t('text_no_data_for_endpoint')}</span>
        </EmptyState>
      ) : null}
    </div>
  );
};

export default DashboardPage;
