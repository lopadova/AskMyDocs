/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * components/layout/AppShell.tsx`.
 *
 * Three material differences vs the upstream `AppShell.tsx`:
 *
 *   1. Class names rebadged to cross-mount-scoped equivalents
 *      (`ehu-panel`, `ehu-rounded`) so they match the styles defined
 *      in `cross-mount/eval-harness-ui.css` instead of relying on the
 *      package's Tailwind v3 component-layer plugin.
 *
 *   2. The outer wrapper class drops `min-h-screen` (the host's
 *      `<div data-testid="admin-eval-harness-host">` already owns the
 *      layout flex/min-h chain) and adds the `ehu-shell` wrapper class
 *      that the cross-mount CSS scopes its tokens under.
 *
 *   3. Added stable `data-testid` hooks on the shell + nav items so
 *      vitest + Playwright can drive navigation deterministically (R11).
 *      NavLink's `aria-current="page"` semantics are preserved verbatim.
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

const AppShell = ({ title, version, children }: { title: string; version: string; children: ReactNode }) => {
  const { t } = useI18n();

  return (
    <div className="ehu-shell bg-slate-50 text-slate-900" data-testid="admin-eval-harness-app">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex w-full max-w-7xl items-center justify-between px-6 py-4">
          <div>
            <h1 className="text-xl font-semibold">{title}</h1>
            <p className="text-xs text-slate-500">v {version}</p>
          </div>
          <span className="text-xs text-slate-500">{t('nav_admin_label', 'Eval Harness Admin')}</span>
        </div>
      </header>
      <div className="mx-auto flex w-full max-w-7xl gap-4 px-6 py-4">
        <aside className="w-56">
          <nav className="ehu-panel ehu-rounded" aria-label={t('nav_admin_label', 'Eval Harness Admin')}>
            <ul className="space-y-1">
              {navItems.map((item) => (
                <li key={item.to}>
                  <NavLink
                    to={item.to}
                    end={item.to === '/'}
                    data-testid={item.testid}
                    className={({ isActive }) =>
                      `block ehu-rounded px-3 py-2 text-sm ${
                        isActive ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'
                      }`
                    }
                  >
                    {t(item.key)}
                  </NavLink>
                </li>
              ))}
            </ul>
          </nav>
        </aside>
        <section className="min-h-[calc(100vh-88px)] flex-1">
          <div className="ehu-panel min-h-full">
            {children}
          </div>
        </section>
      </div>
    </div>
  );
};

export default AppShell;
