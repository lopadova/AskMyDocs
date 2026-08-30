import { Fragment } from 'react';
import { Icon } from '../Icons';
import { Button } from '../Button';
import { TeamSwitcher } from './TeamSwitcher';
import { Tooltip } from './Tooltip';
import { UserMenu } from './UserMenu';
import { NotificationBell } from '../../features/notifications/NotificationBell';
import type { Team } from '../../lib/team-store';
import type { Theme } from './hooks';

export type TopbarProps = {
    team: Team | null;
    teams: Team[];
    onTeamChange: (t: Team) => void;
    theme: Theme;
    setTheme: (t: Theme) => void;
    onToggleTweaks: () => void;
    crumbs?: string[];
};

export function Topbar({
    team,
    teams,
    onTeamChange,
    theme,
    setTheme,
    onToggleTweaks,
    crumbs = [],
}: TopbarProps) {
    return (
        <header className="app-topbar" data-testid="app-topbar">
            <div className="app-topbar-leading">
                {team !== null && teams.length > 0 && (
                    <TeamSwitcher team={team} teams={teams} onChange={onTeamChange} />
                )}
                <nav className="app-topbar-crumbs" aria-label="Breadcrumb">
                    {crumbs.map((c, i) => (
                        <Fragment key={`${c}-${i}`}>
                            <Icon.Chevron size={12} />
                            <span data-current={i === crumbs.length - 1 ? 'true' : 'false'}>{c}</span>
                        </Fragment>
                    ))}
                </nav>
            </div>

            <div className="app-topbar-actions">
                {team !== null && (
                    <div className="app-topbar-status" role="status" aria-label="All systems operational">
                        <span className="pulse-dot" aria-hidden="true" />
                        <span>All systems operational</span>
                    </div>
                )}
                <div className="app-topbar-tools" aria-label="Workspace tools">
                    {/* v8.0/W1.4 — real notification bell wired to
                      * `/api/notifications/unread-count` (30s polling) and
                      * the per-user dropdown. */}
                    {team !== null && <NotificationBell />}
                    <Tooltip label={theme === 'dark' ? 'Light mode' : 'Dark mode'}>
                        <Button
                            variant="quiet"
                            size="sm"
                            iconOnly
                            className="app-topbar-icon-button"
                            aria-label="Toggle theme"
                            onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}
                        >
                            {theme === 'dark' ? <Icon.Sun size={15} /> : <Icon.Moon size={15} />}
                        </Button>
                    </Tooltip>
                    <Tooltip label="Interface settings">
                        <Button
                            variant="quiet"
                            size="sm"
                            iconOnly
                            className="app-topbar-icon-button"
                            aria-label="Open tweaks panel"
                            onClick={onToggleTweaks}
                        >
                            <Icon.Sliders size={15} />
                        </Button>
                    </Tooltip>
                </div>
                {/* The account menu — and the ONLY way to sign out. */}
                <UserMenu />
            </div>
        </header>
    );
}
