import { useEffect, useMemo, useState } from 'react';
import { AdminShell } from '../shell/AdminShell';
import { ChatLogsTab } from './ChatLogsTab';
import { AuditTab } from './AuditTab';
import { ApplicationLogTab } from './ApplicationLogTab';
import { ActivityTab } from './ActivityTab';
import { FailedJobsTab } from './FailedJobsTab';

/*
 * Phase H1 — admin Log Viewer shell.
 *
 * Five tabs — Chat Logs, Canonical Audit, Application, Activity,
 * Failed Jobs. The active tab is deep-linkable via `?tab=` so
 * operators can bookmark "show me failed jobs". Selection state
 * otherwise persists in component state; filters are owned by the
 * individual tab components.
 *
 * READ-ONLY in H1. No command runner, no retry-failed-jobs action,
 * no maintenance wizard — those land in H2.
 */

type LogsTab = 'chat' | 'audit' | 'app' | 'activity' | 'failed';

const VALID_TABS: LogsTab[] = ['chat', 'audit', 'app', 'activity', 'failed'];

const TABS: Array<{ id: LogsTab; label: string }> = [
    { id: 'chat', label: 'Chat Logs' },
    { id: 'audit', label: 'Canonical Audit' },
    { id: 'app', label: 'Application' },
    { id: 'activity', label: 'Activity' },
    { id: 'failed', label: 'Failed Jobs' },
];

function parseInitialTab(): LogsTab {
    if (typeof window === 'undefined') {
        return 'chat';
    }
    const params = new URLSearchParams(window.location.search);
    const raw = params.get('tab');
    return (VALID_TABS as string[]).includes(raw ?? '') ? (raw as LogsTab) : 'chat';
}

function syncTabUrl(tab: LogsTab) {
    if (typeof window === 'undefined') return;
    const params = new URLSearchParams(window.location.search);
    params.set('tab', tab);
    const next = `${window.location.pathname}?${params.toString()}`;
    window.history.replaceState(null, '', next);
}

export function LogsView() {
    const initial = useMemo(parseInitialTab, []);
    const [tab, setTab] = useState<LogsTab>(initial);

    useEffect(() => {
        syncTabUrl(tab);
    }, [tab]);

    return (
        <AdminShell section="logs">
            <div
                data-testid="logs-view"
                style={{
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 14,
                    minHeight: 0,
                    height: '100%',
                }}
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
                        Logs
                    </h1>
                    <p style={{ fontSize: 12.5, color: 'var(--fg-3)', margin: 0 }}>
                        Chat telemetry, canonical audit trail, application log tail, activity
                        log, and failed jobs — all read-only.
                    </p>
                </div>

                <div
                    role="tablist"
                    aria-label="Log tabs"
                    style={{
                        display: 'flex',
                        gap: 4,
                        borderBottom: '1px solid var(--hairline)',
                        paddingBottom: 0,
                    }}
                >
                    {TABS.map((entry) => {
                        const active = entry.id === tab;
                        return (
                            <button
                                key={entry.id}
                                type="button"
                                role="tab"
                                aria-selected={active}
                                data-testid={`logs-tab-${entry.id}`}
                                data-active={active ? 'true' : 'false'}
                                onClick={() => setTab(entry.id)}
                                style={{
                                    padding: '8px 14px',
                                    fontSize: 13,
                                    background: 'transparent',
                                    color: active ? 'var(--fg-0)' : 'var(--fg-2)',
                                    border: 'none',
                                    borderBottom: active
                                        ? '2px solid var(--accent, #3b82f6)'
                                        : '2px solid transparent',
                                    cursor: 'pointer',
                                    fontWeight: active ? 600 : 400,
                                }}
                            >
                                {entry.label}
                            </button>
                        );
                    })}
                </div>

                <div
                    data-testid={`logs-panel-${tab}`}
                    style={{ flex: 1, minHeight: 0, overflow: 'auto' }}
                >
                    {tab === 'chat' ? <ChatLogsTab /> : null}
                    {tab === 'audit' ? <AuditTab /> : null}
                    {tab === 'app' ? <ApplicationLogTab /> : null}
                    {tab === 'activity' ? <ActivityTab /> : null}
                    {tab === 'failed' ? <FailedJobsTab /> : null}
                </div>
            </div>
        </AdminShell>
    );
}
