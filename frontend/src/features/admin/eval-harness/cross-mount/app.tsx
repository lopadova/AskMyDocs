/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * app.tsx`. Imports rewritten to relative paths; behaviour preserved
 * verbatim — same 8 routes, same `<AppShell />`, same wildcard
 * `<Navigate to="/" replace />` fallback.
 */
import { Navigate, Route, Routes } from 'react-router-dom';
import AppShell from './components/layout/AppShell';
import DashboardPage from './pages/DashboardPage';
import ReportsPage from './pages/ReportsPage';
import ReportDetailPage from './pages/ReportDetailPage';
import ComparePage from './pages/ComparePage';
import TrendPage from './pages/TrendPage';
import AdversarialManifestsPage from './pages/AdversarialManifestsPage';
import AdversarialManifestDetailPage from './pages/AdversarialManifestDetailPage';
import LiveBatchesPage from './pages/LiveBatchesPage';
import { useI18n } from './hooks/useI18n';

const App = ({ version, title }: { version: string; title: string }) => {
  const { t } = useI18n();

  return (
    <AppShell title={title ?? t('app_title')} version={version}>
    <Routes>
      <Route path="/" element={<DashboardPage />} />
      <Route path="/reports" element={<ReportsPage />} />
      <Route path="/reports/:id" element={<ReportDetailPage />} />
      <Route path="/compare" element={<ComparePage />} />
      <Route path="/trend" element={<TrendPage />} />
      <Route path="/adversarial" element={<AdversarialManifestsPage />} />
      <Route path="/adversarial/:name" element={<AdversarialManifestDetailPage />} />
      <Route path="/live-batches" element={<LiveBatchesPage />} />
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
    </AppShell>
  );
};

export default App;
