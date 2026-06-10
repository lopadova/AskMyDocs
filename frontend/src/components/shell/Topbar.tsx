import { Fragment } from 'react';
import { Icon } from '../Icons';
import { TeamSwitcher } from './TeamSwitcher';
import { Tooltip } from './Tooltip';
import { NotificationBell } from '../../features/notifications/NotificationBell';
import type { Team } from '../../lib/team-store';
import type { Theme } from './hooks';

export type TopbarProps = {
    team: Team;
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
        <header
            style={{
                height: 52,
                flex: '0 0 52px',
                borderBottom: '1px solid var(--hairline)',
                background: 'var(--bg-1)',
                display: 'flex',
                alignItems: 'center',
                gap: 12,
                padding: '0 16px',
                position: 'relative',
                zIndex: 5,
            }}
        >
            <TeamSwitcher team={team} teams={teams} onChange={onTeamChange} />
            <div
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 6,
                    color: 'var(--fg-3)',
                    fontSize: 12,
                }}
            >
                {crumbs.map((c, i) => (
                    <Fragment key={`${c}-${i}`}>
                        <Icon.Chevron size={12} />
                        <span style={{ color: i === crumbs.length - 1 ? 'var(--fg-1)' : 'var(--fg-3)' }}>{c}</span>
                    </Fragment>
                ))}
            </div>
            <div style={{ flex: 1 }} />
            <div
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 4,
                    padding: '4px 10px',
                    background: 'var(--bg-2)',
                    border: '1px solid var(--panel-border)',
                    borderRadius: 9,
                    fontSize: 11.5,
                    color: 'var(--fg-2)',
                }}
            >
                <span className="pulse-dot" style={{ width: 6, height: 6 }} />
                <span className="mono">All systems operational</span>
            </div>
            {/* v8.0/W1.4 — real notification bell wired to
              * `/api/notifications/unread-count` (30s polling) and
              * the per-user dropdown. Replaces the previous static
              * mockup. */}
            <NotificationBell />
            <Tooltip label={theme === 'dark' ? 'Light mode' : 'Dark mode'}>
                <button
                    type="button"
                    className="btn icon ghost"
                    aria-label="Toggle theme"
                    onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}
                >
                    {theme === 'dark' ? <Icon.Sun size={15} /> : <Icon.Moon size={15} />}
                </button>
            </Tooltip>
            <Tooltip label="Tweaks">
                <button
                    type="button"
                    className="btn icon ghost"
                    aria-label="Open tweaks panel"
                    onClick={onToggleTweaks}
                >
                    <Icon.Sliders size={15} />
                </button>
            </Tooltip>
        </header>
    );
}
