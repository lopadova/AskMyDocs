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
    | 'insights'
    | 'ai-act-compliance'
    | 'connectors'
    | 'tabular-reviews'
    | 'workflows'
    | 'mcp-tools'
    | 'mcp-tokens'
    | 'collections'
    | 'synonyms'
    | 'kb-insights'
    | 'compliance-reports'
    // v8.0/W1.4 — dedicated identifier so the
    // /app/admin/notifications route does NOT highlight a
    // neighbouring rail entry (Copilot iter-6 #2). Notifications
    // intentionally have no rail entry — the user reaches the panel
    // from the Topbar bell's "See all" link, not from the admin rail.
    | 'notifications';

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
    { id: 'ai-act-compliance', label: 'AI Act', icon: 'Shield', to: '/app/admin/ai-act-compliance' },
    // v4.5/W3 — connector framework admin landing. Same flat-RBAC
    // pattern as the rest of /app/admin/*: BE Gate `manageConnectors`
    // (super-admin only) enforces; the FE rail entry is always
    // visible and the route component renders <AdminForbidden /> on
    // role miss via <RequireRole>.
    { id: 'connectors', label: 'Connectors', icon: 'Link', to: '/app/admin/connectors' },
    // v4.7/W3 — Tabular Reviews + Workflows admin landings. Per the
    // standing convention `feedback_admin_ui_panel_alignment_per_release.md`,
    // every cycle that ships new domain capabilities also ships an
    // admin SPA menu entry. BE Gates `viewTabularReviews` /
    // `viewWorkflows` enforce read/write; the FE entries are always
    // visible and the route components render <AdminForbidden /> on
    // miss via <RequireRole>.
    { id: 'tabular-reviews', label: 'Tabular Reviews', icon: 'Grid', to: '/app/admin/tabular-reviews' },
    { id: 'workflows', label: 'Workflows', icon: 'Activity', to: '/app/admin/workflows' },
    // v5.0/W2 — MCP tools admin landing. Same flat-RBAC pattern as the
    // rest of /app/admin/*: BE Gate `manageMcpTools` (super-admin only)
    // enforces; the FE rail entry is always visible and the route
    // component renders <AdminForbidden /> on miss via <RequireRole>.
    { id: 'mcp-tools', label: 'MCP Tools', icon: 'Wrench', to: '/app/admin/mcp-tools' },
    { id: 'mcp-tokens', label: 'MCP Tokens', icon: 'Link', to: '/app/admin/mcp/tokens' },
    { id: 'collections', label: 'Collections', icon: 'Book', to: '/app/admin/collections' },
    { id: 'synonyms', label: 'Synonyms', icon: 'Book', to: '/app/admin/kb/synonyms' },
    { id: 'kb-insights', label: 'Doc Insights', icon: 'Sparkles', to: '/app/admin/kb/insights' },
    { id: 'compliance-reports', label: 'Compliance', icon: 'Shield', to: '/app/admin/compliance/reports' },
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
