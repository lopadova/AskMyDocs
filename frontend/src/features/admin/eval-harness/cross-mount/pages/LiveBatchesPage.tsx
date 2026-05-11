/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * pages/LiveBatchesPage.tsx`. Imports rewritten to relative paths;
 * package CSS classes rebadged to cross-mount-scoped equivalents.
 */
import { useMemo } from 'react';
import { useAppContext } from '../context/AppContext';
import { useApiResource } from '../hooks/useApiResource';
import { useCallback, useState } from 'react';
import { usePolling } from '../hooks/usePolling';
import DataTable from '../components/ui/DataTable';
import ErrorPanel from '../components/ui/ErrorPanel';
import EmptyState from '../components/ui/EmptyState';
import { useI18n } from '../hooks/useI18n';
import type { LiveBatch, LiveBatchProgress } from '../types/api';

const LiveBatchesPage = () => {
  const { createClient, config } = useAppContext();
  const client = createClient();
  const { t } = useI18n();
  const [progressMap, setProgressMap] = useState<Record<string, LiveBatchProgress | null>>({});
  const [progressLoading, setProgressLoading] = useState(false);
  const data = useApiResource<{ items: LiveBatch[] }>(() => client.getLiveBatches(), [], {
    cacheKey: 'live-batches:all',
    noCache: true,
  });
  const pollSeconds = config?.polling?.live_batches_seconds ?? 3;

  const refreshProgress = useCallback(async () => {
    if (data.status !== 'ready' || !data.data) {
      return;
    }

    const running = data.data.items.filter((batch) => batch.status !== 'finished');
    setProgressLoading(true);

    if (running.length === 0) {
      setProgressMap({});
    } else {
      const pairs = await Promise.allSettled(
        running.map(async (batch) => {
          const progress = await client.getBatchProgress(batch.id);
          return { id: batch.id, progress };
        }),
      );

      setProgressMap((current) => {
        const next = { ...current };

        for (const item of pairs) {
          if (item.status !== 'fulfilled') {
            continue;
          }

          if (item.value.progress.error) {
            next[item.value.id] = null;
            continue;
          }

          next[item.value.id] = item.value.progress.data ?? null;
        }

        return next;
      });
    }

    await data.reload();
    setProgressLoading(false);
  }, [client, data]);

  usePolling(refreshProgress, data.status !== 'error', pollSeconds);

  const progressRows = useMemo(() => {
    if (data.status !== 'ready' || !data.data) {
      return [] as Array<LiveBatch & { progress?: LiveBatchProgress | null }>;
    }

    return data.data.items.map((batch) => ({
      ...batch,
      progress: progressMap[batch.id],
    }));
  }, [data.status, data.data, progressMap]);

  return (
    <div className="space-y-4" data-testid="admin-eval-harness-live-batches">
      <div className="flex items-center justify-between">
        <h2 className="ehu-screen-title">{t('heading_live_batches')}</h2>
        <button
          type="button"
          className="ehu-rounded border px-3 py-2 text-sm"
          onClick={() => void data.reload()}
          data-testid="eval-harness-live-batches-refresh"
        >
          {progressLoading ? t('text_loading') : t('button_refresh')}
        </button>
      </div>
      {data.status === 'error' && data.error ? <ErrorPanel error={data.error} /> : null}

      {data.status === 'ready' && data.data?.items.length === 0 ? (
        <EmptyState title={t('empty_no_batch')}>{t('empty_no_batch')}</EmptyState>
      ) : (
        <DataTable
          rowKey={(item) => item.id}
          rows={progressRows}
          columns={[
            { key: 'id', label: 'Batch' },
            {
              key: 'status',
              label: t('label_status'),
              render: (row) => <span className="text-sm font-semibold">{row.status}</span>,
            },
            {
              key: 'processed',
              label: t('label_progress_processed'),
              render: (row) => {
                const processed = row.progress?.processed ?? row.processed;
                const total = row.progress?.total ?? row.total;
                return `${processed}/${total}`;
              },
            },
            {
              key: 'rate_per_sec',
              label: t('label_rate'),
              render: (row) =>
                row.progress?.rate_per_sec === null || row.progress?.rate_per_sec === undefined
                  ? t('text_not_available')
                  : `${row.progress.rate_per_sec}/s`,
            },
            { key: 'ttl_seconds', label: 'TTL', render: (row) => `${row.ttl_seconds}s` },
            { key: 'started_at', label: t('label_started_at'), render: (row) => row.progress?.started_at ?? t('text_not_available') },
            {
              key: 'failures',
              label: t('label_failures_count'),
              render: (row) => (row.progress?.failures === undefined ? '-' : String(row.progress?.failures ?? 0)),
            },
          ]}
        />
      )}
      {data.status === 'ready' && data.data && data.data.items.length > 0 ? (
        <p className="ehu-screen-subtitle">{t('text_rows_csv_help')}</p>
      ) : null}
    </div>
  );
};

export default LiveBatchesPage;
