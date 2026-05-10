/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * pages/ReportsPage.tsx`. Imports rewritten to relative paths;
 * package CSS classes (`rounded-ui`, `screen-title`) rebadged to
 * cross-mount-scoped equivalents (`ehu-*`). Behaviour preserved.
 */
import { FormEvent, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAppContext } from '../context/AppContext';
import { useApiResource } from '../hooks/useApiResource';
import DataTable from '../components/ui/DataTable';
import ErrorPanel from '../components/ui/ErrorPanel';
import EmptyState from '../components/ui/EmptyState';
import { formatDateTime, formatPercent } from '../utils/format';
import type { ReportRow, ReportListPayload } from '../types/api';
import { useI18n } from '../hooks/useI18n';

const ReportsPage = () => {
  const { createClient } = useAppContext();
  const client = createClient();
  const { t, metricLabel } = useI18n();
  const reports = useApiResource<ReportListPayload>(() => client.getReports(), [], {
    cacheKey: 'reports:list',
    ttlMs: 30_000,
  });
  const [datasetFilter, setDatasetFilter] = useState('');
  const [minMacroF1, setMinMacroF1] = useState('');
  const [formatFilter, setFormatFilter] = useState('');
  const [schemaFilter, setSchemaFilter] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');

  const filteredRows = useMemo(() => {
    if (reports.status !== 'ready' || !reports.data) {
      return [] as ReportRow[];
    }

    const min = minMacroF1.trim() === '' ? null : Number.parseFloat(minMacroF1);
    const fromDate = dateFrom.trim() === '' ? null : new Date(`${dateFrom}T00:00:00.000Z`);
    const toDate = dateTo.trim() === '' ? null : new Date(`${dateTo}T23:59:59.999Z`);
    return reports.data.items.filter((row) => {
      const finished = new Date(row.finished_at);
      const matchDataset = datasetFilter.trim() === '' || row.dataset.includes(datasetFilter.trim());
      const matchScore = min === null || Number.isNaN(min) || row.macro_f1 >= min;
      const matchFormat = formatFilter.trim() === '' || row.format === formatFilter.trim();
      const matchSchema = schemaFilter.trim() === '' || (row.schema ?? '').includes(schemaFilter.trim());
      const matchFrom = !fromDate || finished >= fromDate;
      const matchTo = !toDate || finished <= toDate;

      return matchDataset && matchScore && matchFormat && matchSchema && matchFrom && matchTo;
    });
  }, [dateFrom, dateTo, datasetFilter, formatFilter, minMacroF1, reports.status, reports.data, schemaFilter]);

  const onSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
  };

  return (
    <div className="space-y-4" data-testid="admin-eval-harness-reports">
      <h2 className="ehu-screen-title">{t('heading_reports')}</h2>

      {reports.status === 'error' && reports.error ? <ErrorPanel error={reports.error} /> : null}
      {reports.status === 'ready' && reports.data?.items.length === 0 ? (
        <EmptyState title={t('empty_no_reports')}>{t('text_no_data_for_endpoint')}</EmptyState>
      ) : null}

      <form className="flex flex-wrap gap-3" onSubmit={onSubmit}>
        <label className="flex flex-col text-sm">
          {t('label_filter_dataset')}
          <input
            className="mt-1 ehu-rounded border border-slate-200 px-3 py-2"
            value={datasetFilter}
            onChange={(event) => setDatasetFilter(event.target.value)}
            placeholder={t('text_select_dataset')}
          />
        </label>
        <label className="flex flex-col text-sm">
          {t('label_macro_f1')}
          <input
            className="mt-1 ehu-rounded border border-slate-200 px-3 py-2"
            value={minMacroF1}
            onChange={(event) => setMinMacroF1(event.target.value)}
            placeholder="0.9"
          />
        </label>
        <label className="flex flex-col text-sm">
          {t('label_format')}
          <input
            className="mt-1 ehu-rounded border border-slate-200 px-3 py-2"
            value={formatFilter}
            onChange={(event) => setFormatFilter(event.target.value)}
            placeholder="json"
          />
        </label>
        <label className="flex flex-col text-sm">
          {t('label_schema')}
          <input
            className="mt-1 ehu-rounded border border-slate-200 px-3 py-2"
            value={schemaFilter}
            onChange={(event) => setSchemaFilter(event.target.value)}
            placeholder="v1"
          />
        </label>
        <label className="flex flex-col text-sm">
          From
          <input
            type="date"
            className="mt-1 ehu-rounded border border-slate-200 px-3 py-2"
            value={dateFrom}
            onChange={(event) => setDateFrom(event.target.value)}
          />
        </label>
        <label className="flex flex-col text-sm">
          To
          <input
            type="date"
            className="mt-1 ehu-rounded border border-slate-200 px-3 py-2"
            value={dateTo}
            onChange={(event) => setDateTo(event.target.value)}
          />
        </label>
      </form>

      <DataTable
        rowKey={(row) => `${row.id}`}
        rows={filteredRows}
        columns={[
          { key: 'dataset', label: t('label_dataset') },
          { key: 'id', label: t('label_report_id') },
          { key: 'format', label: t('label_format') },
          { key: 'schema_version', label: t('label_schema'), render: (row) => row.schema_version ?? 'legacy' },
          {
            key: 'macro_f1',
            label: metricLabel('macro_f1', t('label_macro_f1')),
            render: (row) => formatPercent(row.macro_f1),
          },
          {
            key: 'sample_count',
            label: t('label_rows'),
            render: (row) => String(row.sample_count),
          },
          { key: 'finished_at', label: t('label_finished'), render: (row) => formatDateTime(row.finished_at) },
          {
            key: 'status',
            label: t('label_action'),
            render: (row) => <Link to={`/reports/${encodeURIComponent(row.id)}`}>{t('action_open')}</Link>,
          },
        ]}
      />
    </div>
  );
};

export default ReportsPage;
