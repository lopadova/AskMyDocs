/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * pages/ComparePage.tsx`. Imports rewritten to relative paths;
 * package CSS classes rebadged to cross-mount-scoped equivalents.
 */
import { useMemo, useState } from 'react';
import { useAppContext } from '../context/AppContext';
import { useApiResource } from '../hooks/useApiResource';
import { useSearchParams } from 'react-router-dom';
import DataTable from '../components/ui/DataTable';
import ErrorPanel from '../components/ui/ErrorPanel';
import StatusTag from '../components/ui/StatusTag';
import type { DiffPayload } from '../types/models';
import type { ReportListPayload } from '../types/api';
import { useI18n } from '../hooks/useI18n';

const ComparePage = () => {
  const [params, setParams] = useSearchParams();
  const leftId = params.get('left') ?? '';
  const rightId = params.get('right') ?? '';
  const [leftValue, setLeftValue] = useState(leftId);
  const [rightValue, setRightValue] = useState(rightId);
  const [datasetForAuto, setDatasetForAuto] = useState('');
  const { t } = useI18n();

  const { createClient } = useAppContext();
  const client = createClient();

  const reports = useApiResource<ReportListPayload>(() => client.getReports(), [], {
    cacheKey: 'compare:reports',
    ttlMs: 30_000,
  });
  const comparisonCacheKey =
    leftValue && rightValue ? `compare:result:${leftValue}:${rightValue}` : `compare:pending:${leftValue}:${rightValue}`;

  const comparison = useApiResource<DiffPayload>(() => {
    if (!leftValue || !rightValue) {
      return Promise.resolve({ data: undefined as unknown as DiffPayload });
    }

    return client.getReportDiff(leftValue, rightValue);
  }, [leftValue, rightValue], {
    cacheKey: comparisonCacheKey,
    ttlMs: 5 * 60_000,
  });

  const reportRows = useMemo(() => (reports.status === 'ready' ? reports.data?.items ?? [] : []), [reports.status, reports.data]);
  const rowsByDataset = useMemo(() => {
    const map = new Map<string, ReportListPayload['items'][number][]>();
    for (const row of reportRows) {
      const arr = map.get(row.dataset) ?? [];
      arr.push(row);
      map.set(row.dataset, arr);
    }

    for (const value of map.values()) {
      value.sort((a, b) => b.finished_at.localeCompare(a.finished_at));
    }

    return map;
  }, [reportRows]);

  const datasetOptions = useMemo(() => Array.from(rowsByDataset.keys()).sort(), [rowsByDataset]);

  const latestPreviousAvailable = useMemo(() => {
    if (!datasetForAuto) {
      return false;
    }
    return (rowsByDataset.get(datasetForAuto) ?? []).length >= 2;
  }, [datasetForAuto, rowsByDataset]);

  const applyLatestPrevious = () => {
    if (!datasetForAuto) {
      return;
    }

    const rows = rowsByDataset.get(datasetForAuto) ?? [];
    if (rows.length < 2) {
      return;
    }

    const [latest, previous] = rows.slice(0, 2);
    setLeftValue(latest.id);
    setRightValue(previous.id);
    void comparison.reload();
    setParams({
      left: latest.id,
      right: previous.id,
    });
  };

  const available = new Set(reportRows.map((row) => row.id));

  const apply = () => {
    setParams({
      left: leftValue,
      right: rightValue,
    });
    void comparison.reload();
  };

  return (
    <div className="space-y-4" data-testid="admin-eval-harness-compare">
      <h2 className="ehu-screen-title">{t('heading_compare')}</h2>

      {comparison.status === 'error' && comparison.error ? <ErrorPanel error={comparison.error} /> : null}

      {/*
        * Copilot iter 2 finding #6 (R11 / R29): every interactive
        * select + button on the Compare page carries a stable
        * `data-testid` for vitest + Playwright determinism.
        */}
      <section className="ehu-panel ehu-rounded">
        <div className="flex flex-wrap gap-3">
          <label className="text-sm">
            {t('text_select_left')}
            <select
              className="mt-1 block ehu-rounded border border-slate-200 p-2"
              value={leftValue}
              onChange={(event) => setLeftValue(event.target.value)}
              data-testid="eval-harness-compare-select-left"
            >
              <option value="">seleziona</option>
              {reportRows.map((item) => (
                <option key={item.id} value={item.id}>
                  {item.dataset} / {item.id}
                </option>
              ))}
            </select>
          </label>
          <label className="text-sm">
            {t('text_select_right')}
            <select
              className="mt-1 block ehu-rounded border border-slate-200 p-2"
              value={rightValue}
              onChange={(event) => setRightValue(event.target.value)}
              data-testid="eval-harness-compare-select-right"
            >
              <option value="">seleziona</option>
              {reportRows.map((item) => (
                <option key={`r-${item.id}`} value={item.id} disabled={!available.has(item.id)}>
                  {item.dataset} / {item.id}
                </option>
              ))}
            </select>
          </label>
          <label className="text-sm">
            {t('label_dataset')}
            <select
              className="mt-1 block ehu-rounded border border-slate-200 p-2"
              value={datasetForAuto}
              onChange={(event) => setDatasetForAuto(event.target.value)}
              data-testid="eval-harness-compare-select-dataset"
            >
              <option value="">{t('text_select_dataset')}</option>
              {datasetOptions.map((dataset) => (
                <option key={dataset} value={dataset}>
                  {dataset}
                </option>
              ))}
            </select>
          </label>
          <div className="self-end">
            <button
              type="button"
              className="ehu-rounded bg-slate-900 px-4 py-2 text-white"
              onClick={apply}
              data-testid="eval-harness-compare-apply"
            >
              {t('action_compare')}
            </button>
            <button
              className="ml-2 ehu-rounded bg-slate-700 px-4 py-2 text-white disabled:opacity-50"
              onClick={applyLatestPrevious}
              disabled={!latestPreviousAvailable}
              type="button"
              data-testid="eval-harness-compare-latest-previous"
            >
              {t('action_latest_previous')}
            </button>
          </div>
        </div>
      </section>

      {comparison.status === 'ready' && comparison.data ? (
        <section className="ehu-panel ehu-rounded">
          <h3 className="mb-3 font-semibold">{`Diff ${comparison.data.from} vs ${comparison.data.to}`}</h3>
          <DataTable
            rowKey={(row) => `${row.metric}-${row.status}-${row.delta}`}
            rows={comparison.data.metrics}
            columns={[
              { key: 'metric', label: t('title_metric') },
              {
                key: 'delta',
                label: 'Delta',
                render: (row: DiffPayload['metrics'][number]) => (row.delta > 0 ? `+${row.delta}` : `${row.delta}`),
              },
              {
                key: 'status',
                label: t('status_stable'),
                render: (row: DiffPayload['metrics'][number]) => <StatusTag status={row.status} />,
              },
            ]}
          />

          {comparison.data.cohorts && comparison.data.cohorts.length > 0 ? (
            <div className="mt-4 text-sm">
              <h4 className="mb-2 font-semibold">{t('section_cohorts')}</h4>
              <ul className="space-y-1">
                {comparison.data.cohorts.map((cohort) => (
                  <li key={cohort.cohort} className="ehu-rounded border border-slate-200 px-2 py-1">
                    {cohort.cohort}: {cohort.status} ({cohort.delta})
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
        </section>
      ) : (
        <p className="text-sm text-slate-500">{t('text_error_hint')}</p>
      )}
    </div>
  );
};

export default ComparePage;
