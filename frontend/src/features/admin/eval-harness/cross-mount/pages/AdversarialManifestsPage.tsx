/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * pages/AdversarialManifestsPage.tsx`. Imports rewritten to relative
 * paths; package CSS classes rebadged to cross-mount-scoped
 * equivalents.
 */
import { Link } from 'react-router-dom';
import { useAppContext } from '../context/AppContext';
import { useApiResource } from '../hooks/useApiResource';
import { useI18n } from '../hooks/useI18n';
import DataTable from '../components/ui/DataTable';
import EmptyState from '../components/ui/EmptyState';
import ErrorPanel from '../components/ui/ErrorPanel';
import type { AdversarialManifestSummary } from '../types/api';

const AdversarialManifestsPage = () => {
  const { createClient } = useAppContext();
  const client = createClient();
  const { t } = useI18n();
  const data = useApiResource<{ items: AdversarialManifestSummary[] }>(() => client.getAdversarialManifests(), [], {
    cacheKey: 'adversarial:manifests',
    ttlMs: 60_000,
  });

  return (
    <div className="space-y-4" data-testid="admin-eval-harness-adversarial">
      <h2 className="ehu-screen-title">{t('heading_adversarial')}</h2>

      {data.status === 'error' && data.error ? <ErrorPanel error={data.error} /> : null}
      {data.status === 'ready' && data.data?.items.length === 0 ? (
        <EmptyState title={t('empty_no_manifest')}>{t('text_no_data_for_endpoint')}</EmptyState>
      ) : null}

      <DataTable
        rows={data.status === 'ready' && data.data ? data.data.items : []}
        rowKey={(row) => row.name}
        columns={[
          { key: 'name', label: t('label_dataset') },
          { key: 'runs', label: t('label_rows'), render: (row) => String(row.runs) },
          {
            key: 'latest_f1',
            label: t('label_latest_macro_f1'),
            render: (row) => (row.latest_f1 === null || row.latest_f1 === undefined ? '—' : String(row.latest_f1)),
          },
          {
            key: 'coverage',
            label: t('label_coverage'),
            render: (row) => (row.coverage === null || row.coverage === undefined ? '—' : String(row.coverage)),
          },
          { key: 'compliance', label: t('label_compliance'), render: (row) => row.compliance ?? 'n/a' },
          { key: 'latest_status', label: t('label_status'), render: (row) => row.latest_status ?? '—' },
          {
            key: 'actions',
            label: t('label_action'),
            render: (row) => <Link to={`/adversarial/${encodeURIComponent(row.name)}`}>{t('action_open')}</Link>,
          },
        ]}
      />
    </div>
  );
};

export default AdversarialManifestsPage;
