import type { ReactNode } from 'react';
import type { SidebarSection } from '../../../components/shell/nav-config';

/*
 * AdminShell — content wrapper for every `/app/admin/*` page.
 *
 * It USED to render a second admin-specific rail next to the global
 * `AppShell` sidebar, which duplicated eight sections on every admin page.
 * The navigation is now unified into the single primary sidebar
 * (`components/shell/Sidebar` + `nav-config`), so this shell keeps ONLY the
 * scrollable content area. The `section` prop is retained for call-site
 * compatibility (every admin view passes it) and to tag the DOM for tests,
 * but it no longer drives a rail.
 */

// Back-compat alias: the admin views import `AdminSection` from here. It is
// the unified sidebar section id, plus the two sub-pages that have no nav
// entry of their own — `notifications` (reached from the topbar bell) and
// `time-machine` (the per-document version timeline under /app/admin/kb).
export type AdminSection = SidebarSection | 'notifications' | 'time-machine';

export interface AdminShellProps {
    section: AdminSection;
    children: ReactNode;
}

export function AdminShell({ section, children }: AdminShellProps) {
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
