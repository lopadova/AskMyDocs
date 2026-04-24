import type { ReactNode } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { Icon, type IconName } from '../../../components/Icons';

/*
 * AdminShell — wrapper around every `/app/admin/*` page. The global
 * `AppShell` already renders the workspace sidebar + topbar; this inner
 * shell adds an admin-specific secondary rail (Dashboard / Users /
 * Roles / KB / Logs / Maintenance / Insights) so operators can pivot
 * between subsystems without leaving the /app/admin namespace.
 *
 * The rail entries are driven by existing top-level routes — clicking
 * them navigates to the corresponding /app/<section> landing page
 * until PR #7..#11 build out the admin subsections.
 */

export type AdminSection =
    | 'dashboard'
    | 'users'
    | 'roles'
    | 'kb'
    | 'logs'
    | 'maintenance'
    | 'insights';

interface RailEntry {
    id: AdminSection;
    label: string;
    icon: IconName;
    to: string;
}

// PR7 / Phase F2 — Users + Roles pivot to their new dedicated routes
// under /app/admin/. Dashboard stays at /app/admin for backwards compat.
const RAIL: RailEntry[] = [
    { id: 'dashboard', label: 'Dashboard', icon: 'Grid', to: '/app/admin' },
    { id: 'users', label: 'Users', icon: 'Users', to: '/app/admin/users' },
    { id: 'roles', label: 'Roles', icon: 'Shield', to: '/app/admin/roles' },
    { id: 'kb', label: 'Knowledge', icon: 'Book', to: '/app/admin/kb' },
    { id: 'logs', label: 'Logs', icon: 'Activity', to: '/app/admin/logs' },
    { id: 'maintenance', label: 'Maintenance', icon: 'Wrench', to: '/app/admin/maintenance' },
    { id: 'insights', label: 'Insights', icon: 'Sparkles', to: '/app/admin/insights' },
];

export interface AdminShellProps {
    section: AdminSection;
    children: ReactNode;
}

export function AdminShell({ section, children }: AdminShellProps) {
    const navigate = useNavigate();

    return (
        <div
            data-testid="admin-shell"
            data-section={section}
            style={{
                flex: 1,
                display: 'flex',
                minWidth: 0,
                minHeight: 0,
                background: 'var(--bg-0)',
                color: 'var(--fg-1)',
            }}
        >
            <nav
                data-testid="admin-rail"
                aria-label="Admin navigation"
                style={{
                    width: 180,
                    minWidth: 180,
                    borderRight: '1px solid var(--hairline)',
                    padding: '14px 10px',
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 2,
                    background: 'var(--bg-1)',
                }}
            >
                <div
                    style={{
                        fontSize: 10.5,
                        color: 'var(--fg-3)',
                        fontFamily: 'var(--font-mono)',
                        textTransform: 'uppercase',
                        letterSpacing: '0.05em',
                        padding: '6px 10px 8px',
                    }}
                >
                    Admin
                </div>
                {RAIL.map((entry) => {
                    const active = entry.id === section;
                    const IconCmp = Icon[entry.icon];
                    return (
                        <button
                            key={entry.id}
                            type="button"
                            className="focus-ring"
                            data-testid={`admin-rail-${entry.id}`}
                            data-active={active ? 'true' : 'false'}
                            onClick={() => navigate({ to: entry.to })}
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                gap: 10,
                                padding: '8px 10px',
                                borderRadius: 8,
                                border: '1px solid transparent',
                                background: active ? 'var(--grad-accent-soft)' : 'transparent',
                                color: active ? 'var(--fg-0)' : 'var(--fg-2)',
                                fontSize: 13,
                                textAlign: 'left',
                                cursor: 'pointer',
                            }}
                        >
                            <IconCmp size={15} />
                            <span style={{ flex: 1 }}>{entry.label}</span>
                        </button>
                    );
                })}
            </nav>
            <main
                style={{
                    flex: 1,
                    minWidth: 0,
                    overflow: 'auto',
                    padding: '22px 26px 28px',
                }}
            >
                {children}
            </main>
        </div>
    );
}
