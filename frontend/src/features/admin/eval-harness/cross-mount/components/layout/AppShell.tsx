/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * components/layout/AppShell.tsx`.
 *
 * Phase 2 (unified-admin) — center-only embed. This copy is mounted ONLY
 * inside the AskMyDocs admin shell, which already provides the single
 * primary rail + topbar + breadcrumb. So the package's own redundant
 * chrome is dropped: no second header ("Eval Harness UI / Eval Harness
 * Admin") and no second sidebar. The six section links render as a slim
 * in-content tab strip instead — no nested chrome.
 *
 * Differences vs upstream preserved: cross-mount-scoped class names
 * (`ehu-panel`, `ehu-rounded`, `ehu-shell`), stable `data-testid` hooks on
 * the shell + nav items, and NavLink `aria-current="page"` semantics.
 */
import { NavLink } from 'react-router-dom';
import { ReactNode } from 'react';
import { useI18n } from '../../hooks/useI18n';

const navItems = [
  { to: '/', key: 'nav_dashboard', testid: 'admin-eval-harness-nav-dashboard' },
  { to: '/reports', key: 'nav_reports', testid: 'admin-eval-harness-nav-reports' },
  { to: '/compare', key: 'nav_compare', testid: 'admin-eval-harness-nav-compare' },
  { to: '/trend', key: 'nav_trend', testid: 'admin-eval-harness-nav-trend' },
  { to: '/adversarial', key: 'nav_adversarial', testid: 'admin-eval-harness-nav-adversarial' },
  { to: '/live-batches', key: 'nav_live_batches', testid: 'admin-eval-harness-nav-live-batches' },
];

const AppShell = ({ children }: { title?: string; version?: string; children: ReactNode }) => {
  const { t } = useI18n();

  return (
    <div className="ehu-shell ehu-embedded text-slate-900" data-testid="admin-eval-harness-app">
      <nav
        className="ehu-tabs"
        aria-label={t('nav_admin_label', 'Eval Harness sections')}
        data-testid="admin-eval-harness-tabs"
      >
        {navItems.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.to === '/'}
            data-testid={item.testid}
            className={({ isActive }) => (isActive ? 'ehu-tab is-active' : 'ehu-tab')}
          >
            {t(item.key)}
          </NavLink>
        ))}
      </nav>
      <section className="ehu-content">
        <div className="ehu-panel min-h-full">{children}</div>
      </section>
    </div>
  );
};

export default AppShell;
