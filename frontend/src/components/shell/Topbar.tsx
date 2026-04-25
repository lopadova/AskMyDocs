import { Fragment } from 'react';
import { Icon } from '../Icons';
import { ProjectSwitcher } from './ProjectSwitcher';
import { Tooltip } from './Tooltip';
import type { Project } from '../../lib/seed';
import type { Theme } from './hooks';

export type TopbarProps = {
    project: Project;
    projects: Project[];
    onProjectChange: (p: Project) => void;
    theme: Theme;
    setTheme: (t: Theme) => void;
    onToggleTweaks: () => void;
    crumbs?: string[];
};

export function Topbar({
    project,
    projects,
    onProjectChange,
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
            <ProjectSwitcher project={project} projects={projects} onChange={onProjectChange} />
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
            <Tooltip label="Notifications">
                <button type="button" className="btn icon ghost" style={{ position: 'relative' }}>
                    <Icon.Bell size={15} />
                    <span
                        style={{
                            position: 'absolute',
                            top: 6,
                            right: 7,
                            width: 6,
                            height: 6,
                            background: 'var(--accent-a)',
                            borderRadius: 99,
                            border: '1.5px solid var(--bg-1)',
                        }}
                    />
                </button>
            </Tooltip>
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
