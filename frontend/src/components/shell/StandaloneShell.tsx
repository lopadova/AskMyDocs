import { type ReactNode } from 'react';
import { Outlet, useNavigate } from '@tanstack/react-router';
import { Button } from '../Button';
import { Icon } from '../Icons';
import { TeamSwitcher } from './TeamSwitcher';
import { Tooltip } from './Tooltip';
import { useTheme } from './hooks';
import { useTeamStore } from '../../lib/team-store';

/**
 * Chrome-less shell for the full-screen chat surfaces.
 *
 * The Sessions workspace and Browse KB are meant to be read as their own
 * views, not as pages inside the admin app: the ONLY sidebar is the
 * session rail, so `AppShell`'s primary navigation would be a second,
 * competing one.
 *
 * What survives is the irreducible minimum — a way BACK (otherwise the
 * view is a trap), the team switcher (the tenant is the outermost scope
 * of everything below it, and dropping it would strand a multi-tenant
 * user), and the theme toggle. Deliberately absent: the nav sidebar,
 * breadcrumbs, the notification bell, the command palette and the tweaks
 * panel — all of which belong to the app frame this view steps out of.
 *
 * Both controls reuse the components the real topbar uses, so a team
 * switch behaves identically on either side of the boundary.
 */
export function StandaloneShell({ children }: { children?: ReactNode }): ReactNode {
    const navigate = useNavigate();
    const teams = useTeamStore((s) => s.teams);
    const currentTeam = useTeamStore((s) => s.currentTeam);
    const activeTeam = teams.find((t) => t.tenant_id === currentTeam) ?? null;
    const [theme, setTheme] = useTheme();

    return (
        <div
            data-testid="standalone-shell"
            style={{
                display: 'flex',
                flexDirection: 'column',
                height: '100vh',
                overflow: 'hidden',
                background: 'var(--bg-0)',
                color: 'var(--fg-1)',
                fontFamily: 'var(--font-sans)',
            }}
        >
            <header className="standalone-topbar" data-testid="standalone-topbar">
                <Tooltip label="Back to the app">
                    <Button
                        variant="quiet"
                        size="sm"
                        iconOnly
                        className="app-topbar-icon-button"
                        data-testid="standalone-back"
                        aria-label="Back to the app"
                        onClick={() => navigate({ to: '/app' })}
                    >
                        <Icon.Chevron size={15} style={{ transform: 'rotate(180deg)' }} />
                    </Button>
                </Tooltip>

                {activeTeam !== null && teams.length > 0 && (
                    <TeamSwitcher
                        team={activeTeam}
                        teams={teams}
                        onChange={(t) => {
                            // The URL is the source of truth for the active
                            // team, exactly as in AppShell: swap the hash
                            // segment in place and let TeamGate sync the
                            // store and clear the query cache. Staying on
                            // the same surface matters here — a team switch
                            // must not eject the user back into the app
                            // frame they deliberately left.
                            const rest = window.location.pathname.replace(/^\/app\/[^/]+/, '');
                            navigate({ to: `/app/${t.hash}${rest}` });
                        }}
                    />
                )}

                <div className="standalone-topbar-actions">
                    <Tooltip label={theme === 'dark' ? 'Light mode' : 'Dark mode'}>
                        <Button
                            variant="quiet"
                            size="sm"
                            iconOnly
                            className="app-topbar-icon-button"
                            data-testid="standalone-theme"
                            aria-label="Toggle theme"
                            onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}
                        >
                            {theme === 'dark' ? <Icon.Sun size={15} /> : <Icon.Moon size={15} />}
                        </Button>
                    </Tooltip>
                </div>
            </header>

            {/* Keyed on the team for the same reason AppShell is: switching
              * tenant remounts the subtree, so a project picker or a tree
              * selection cannot leak across tenants. */}
            <div
                key={activeTeam?.tenant_id ?? 'no-tenant'}
                style={{ flex: 1, minHeight: 0, display: 'flex', overflow: 'hidden' }}
            >
                {children ?? <Outlet />}
            </div>
        </div>
    );
}
