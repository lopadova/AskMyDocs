import { useState } from 'react';
import { AdminShell } from '../shell/AdminShell';
import { useCommandCatalogue, type CatalogueEntry } from './maintenance.api';
import { CommandCard } from './CommandCard';
import { CommandWizard } from './CommandWizard';
import { CommandHistoryTable } from './CommandHistoryTable';
import { SchedulerStatusCard } from './SchedulerStatusCard';

/*
 * Phase H2 — admin Maintenance panel.
 *
 * Left: scheduler status. Center: command grid (from /catalogue,
 * grouped into KB / Pruning / Queue). Right: command history table.
 *
 * The catalogue endpoint filters by permission server-side, so
 * non-super-admin users will simply not see destructive commands
 * in the grid at all. The UX mirrors the security contract: what
 * you can't run, you can't find.
 */

type TabId = 'commands' | 'history';

function categoryOf(name: string): 'kb-content' | 'pruning' | 'queue' | 'other' {
    if (name.startsWith('kb:ingest') || name.startsWith('kb:delete') || name.startsWith('kb:validate') || name.startsWith('kb:rebuild')) {
        return 'kb-content';
    }
    if (name.startsWith('kb:prune') || name.endsWith(':prune') || name.includes('-prune')) {
        return 'pruning';
    }
    if (name.startsWith('queue:')) {
        return 'queue';
    }
    return 'other';
}

const CATEGORIES: Array<{ id: 'kb-content' | 'pruning' | 'queue' | 'other'; label: string }> = [
    { id: 'kb-content', label: 'Knowledge base' },
    { id: 'pruning', label: 'Retention / pruning' },
    { id: 'queue', label: 'Queue' },
    { id: 'other', label: 'Other' },
];

export function MaintenanceView() {
    const [tab, setTab] = useState<TabId>('commands');
    const [activeCommand, setActiveCommand] = useState<{ name: string; spec: CatalogueEntry } | null>(null);
    const catalogue = useCommandCatalogue();

    const catState: 'loading' | 'ready' | 'error' = catalogue.isLoading
        ? 'loading'
        : catalogue.isError
          ? 'error'
          : 'ready';

    return (
        <AdminShell section="maintenance">
            <div
                data-testid="maintenance-view"
                style={{ display: 'flex', flexDirection: 'column', gap: 14, minHeight: 0, height: '100%' }}
            >
                <div>
                    <h1
                        style={{
                            fontSize: 20,
                            fontWeight: 600,
                            margin: '0 0 2px',
                            letterSpacing: '-0.02em',
                            color: 'var(--fg-0)',
                        }}
                    >
                        Maintenance
                    </h1>
                    <p style={{ fontSize: 12.5, color: 'var(--fg-3)', margin: 0 }}>
                        Whitelisted artisan commands — run, audit, schedule.
                    </p>
                </div>

                <div
                    role="tablist"
                    style={{
                        display: 'flex',
                        gap: 4,
                        borderBottom: '1px solid var(--hairline)',
                    }}
                >
                    <TabBtn id="commands" active={tab === 'commands'} onClick={() => setTab('commands')}>
                        Commands
                    </TabBtn>
                    <TabBtn id="history" active={tab === 'history'} onClick={() => setTab('history')}>
                        History
                    </TabBtn>
                </div>

                {tab === 'commands' ? (
                    <div
                        data-testid="maintenance-panel-commands"
                        data-state={catState}
                        style={{ display: 'grid', gridTemplateColumns: '1fr 260px', gap: 14, flex: 1, minHeight: 0 }}
                    >
                        <div style={{ overflow: 'auto', display: 'flex', flexDirection: 'column', gap: 18 }}>
                            {catState === 'loading' ? <div>Loading catalogue…</div> : null}
                            {catState === 'error' ? (
                                <div data-testid="maintenance-catalogue-error" style={{ color: 'var(--danger-fg, #b91c1c)' }}>
                                    Failed to load command catalogue.
                                </div>
                            ) : null}
                            {catState === 'ready'
                                ? CATEGORIES.map((cat) => {
                                      const entries = Object.entries(catalogue.data!.data).filter(
                                          ([name]) => categoryOf(name) === cat.id,
                                      );
                                      if (entries.length === 0) return null;
                                      return (
                                          <section key={cat.id} data-testid={`maintenance-category-${cat.id}`}>
                                              <h2
                                                  style={{
                                                      fontSize: 12,
                                                      textTransform: 'uppercase',
                                                      letterSpacing: '0.05em',
                                                      color: 'var(--fg-3)',
                                                      margin: '0 0 8px',
                                                  }}
                                              >
                                                  {cat.label}
                                              </h2>
                                              <div
                                                  style={{
                                                      display: 'grid',
                                                      gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))',
                                                      gap: 12,
                                                  }}
                                              >
                                                  {entries.map(([name, spec]) => (
                                                      <CommandCard
                                                          key={name}
                                                          command={name}
                                                          spec={spec}
                                                          onRun={() => setActiveCommand({ name, spec })}
                                                      />
                                                  ))}
                                              </div>
                                          </section>
                                      );
                                  })
                                : null}
                        </div>
                        <SchedulerStatusCard />
                    </div>
                ) : null}

                {tab === 'history' ? (
                    <div data-testid="maintenance-panel-history" style={{ flex: 1, minHeight: 0, overflow: 'auto' }}>
                        <CommandHistoryTable />
                    </div>
                ) : null}

                {activeCommand ? (
                    <CommandWizard
                        command={activeCommand.name}
                        spec={activeCommand.spec}
                        onClose={() => setActiveCommand(null)}
                    />
                ) : null}
            </div>
        </AdminShell>
    );
}

function TabBtn({
    id,
    active,
    onClick,
    children,
}: {
    id: string;
    active: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            role="tab"
            aria-selected={active}
            data-testid={`maintenance-tab-${id}`}
            data-active={active ? 'true' : 'false'}
            onClick={onClick}
            style={{
                padding: '8px 14px',
                fontSize: 13,
                background: 'transparent',
                color: active ? 'var(--fg-0)' : 'var(--fg-2)',
                border: 'none',
                borderBottom: active ? '2px solid var(--accent, #3b82f6)' : '2px solid transparent',
                cursor: 'pointer',
                fontWeight: active ? 600 : 400,
            }}
        >
            {children}
        </button>
    );
}
